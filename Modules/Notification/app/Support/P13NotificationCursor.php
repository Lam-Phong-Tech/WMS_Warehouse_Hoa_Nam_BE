<?php

namespace Modules\Notification\Support;

use App\Support\ApiError;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Support\StableAuthSession;
use Modules\Notification\Models\NotificationRecipient;

/** Forward keyset, not an immutable snapshot of unread membership. */
final class P13NotificationCursor
{
    public const CONTRACT = 'p13-notifications-v1';

    public const TTL_SECONDS = 900;

    public static function validate(Request $request): array
    {
        $input = $request->query();
        if (array_diff(array_keys($input), ['page', 'per_page', 'unread', 'severity', 'cursor']) !== []) {
            ApiError::abort(422, 'NOTIFICATION_UNKNOWN_FILTER', 'Bộ lọc thông báo không được hỗ trợ.');
        }
        $data = Validator::make($input, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'unread' => ['sometimes', 'in:0,1,true,false'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'cursor' => ['sometimes', 'required', 'string', 'max:4096'],
        ])->validate();
        if (array_key_exists('cursor', $data) && array_key_exists('page', $data)) {
            ApiError::abort(422, 'NOTIFICATION_PAGINATION_CONFLICT', 'Không gửi page cùng cursor.');
        }

        return [
            'page' => (int) ($data['page'] ?? 1),
            'per_page' => (int) ($data['per_page'] ?? 15),
            'unread' => $request->boolean('unread'),
            'severity' => $data['severity'] ?? null,
            'cursor' => $data['cursor'] ?? null,
        ];
    }

    public static function query(Request $request, array $input): Builder
    {
        return NotificationRecipient::query()->with('notification')
            ->where('user_id', $request->user()->user_id)
            ->when($input['unread'], fn (Builder $q): Builder => $q->whereNull('read_at'))
            ->when($input['severity'] !== null, fn (Builder $q): Builder => $q->whereHas(
                'notification', fn (Builder $n): Builder => $n->where('severity', $input['severity'])
            ));
    }

    public static function read(Request $request, array $input): array
    {
        // Do not bind to read_at or unread count: reading a row must not invalidate the cursor.
        // Stable session survives refresh rotation, but a new login cannot reuse its cursor.
        $scope = hash('sha256', json_encode([
            self::CONTRACT, $request->path(), (string) $request->user()->user_id,
            (int) $request->user()->version, app(StableAuthSession::class)->id($request->user()),
            $input['unread'], $input['severity'], $input['per_page'],
        ], JSON_THROW_ON_ERROR));
        $query = self::query($request, $input);
        if ($input['cursor'] === 'start') {
            $first = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->first();
            $state = [
                'contract' => self::CONTRACT, 'scope' => $scope,
                'expires' => now()->timestamp + self::TTL_SECONDS,
                'upper' => $first === null ? null : self::key($first), 'after' => null,
            ];
        } else {
            try {
                $state = json_decode(Crypt::decryptString($input['cursor']), true, 16, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                ApiError::abort(422, 'NOTIFICATION_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại danh sách.');
            }
            if (! is_array($state) || ($state['contract'] ?? null) !== self::CONTRACT
                || ! is_string($state['scope'] ?? null) || ! is_int($state['expires'] ?? null)
                || ! self::validKey($state['upper'] ?? null) || ! self::validKey($state['after'] ?? null)) {
                ApiError::abort(422, 'NOTIFICATION_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại danh sách.');
            }
            if (! hash_equals($scope, $state['scope'])) {
                ApiError::abort(409, 'NOTIFICATION_CURSOR_SCOPE_MISMATCH', 'Cursor không thuộc người dùng, phiên hoặc bộ lọc hiện tại.');
            }
            if ($state['expires'] <= now()->timestamp) {
                ApiError::abort(410, 'NOTIFICATION_CURSOR_EXPIRED', 'Cursor đã hết hạn; tải lại danh sách.');
            }
        }
        if ($state['upper'] === null) {
            $query->whereRaw('1 = 0');
        } else {
            self::before($query, $state['upper'], true);
        }
        // Total is live filtered membership within the high-water mark, not initial snapshot size.
        $total = (clone $query)->count();
        if ($state['after'] !== null) {
            self::before($query, $state['after'], false);
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($input['per_page'] + 1)->get();
        $hasMore = $rows->count() > $input['per_page'];
        $rows = $rows->take($input['per_page'])->values();
        $next = null;
        if ($hasMore) {
            $state['after'] = self::key($rows->last());
            $next = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        }

        return ['rows' => $rows, 'meta' => [
            'contract' => self::CONTRACT, 'pagination_mode' => 'keyset',
            'per_page' => $input['per_page'], 'has_more' => $hasMore, 'next_cursor' => $next,
            'cursor_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $state['expires']),
            'total' => $total, 'total_scope' => 'LIVE_FILTERED_WITHIN_HIGH_WATER',
            'sort' => ['created_at' => 'desc', 'id' => 'desc'],
            'snapshot_consistent' => false, 'membership' => 'LIVE_UNREAD_OR_ALL',
            'new_notifications' => 'RESTART_FROM_START',
            'session_binding' => 'STABLE_AUTH_SESSION_WHEN_PRESENT',
        ]];
    }

    private static function key(NotificationRecipient $recipient): array
    {
        return ['time' => (string) $recipient->getRawOriginal('created_at'), 'id' => (string) $recipient->id];
    }

    private static function validKey(mixed $key): bool
    {
        return is_array($key) && is_string($key['time'] ?? null) && strlen($key['time']) <= 128
            && is_string($key['id'] ?? null) && Str::isUuid($key['id']);
    }

    private static function before(Builder $query, array $key, bool $inclusive): void
    {
        $query->where(function (Builder $q) use ($key, $inclusive): void {
            $q->where('created_at', '<', $key['time'])->orWhere(function (Builder $tie) use ($key, $inclusive): void {
                $tie->where('created_at', $key['time'])->where('id', $inclusive ? '<=' : '<', $key['id']);
            });
        });
    }
}
