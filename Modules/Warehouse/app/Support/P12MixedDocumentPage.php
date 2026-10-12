<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** One global keyset: null-time flag, created_at, source rank, canonical ID. */
final class P12MixedDocumentPage
{
    public static function state(string $cursor, string $binding): array
    {
        if ($cursor === 'start') {
            return [
                'v' => 1, 'binding' => $binding,
                'expires' => now()->timestamp + 900,
                'upper_bound' => now()->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s.u'),
                'after' => null,
            ];
        }
        try {
            $state = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            ApiError::abort(422, 'DOCUMENTS_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại từ start.');
        }
        if (! is_array($state) || ($state['v'] ?? null) !== 1
            || ! is_string($state['binding'] ?? null) || ! is_int($state['expires'] ?? null)
            || ! self::timestamp($state['upper_bound'] ?? null) || ! is_array($state['after'] ?? null)) {
            ApiError::abort(422, 'DOCUMENTS_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại từ start.');
        }
        if (! hash_equals($binding, $state['binding'])) {
            ApiError::abort(409, 'DOCUMENTS_CURSOR_CONTEXT_CHANGED', 'Cursor khác tài khoản, phiên, quyền, kho hoặc bộ lọc.');
        }
        if ($state['expires'] <= now()->timestamp) {
            ApiError::abort(410, 'DOCUMENTS_CURSOR_EXPIRED', 'Cursor đã hết hạn; tải lại từ start.');
        }
        $after = $state['after'];
        if (! in_array($after['missing'] ?? null, [0, 1], true)
            || ! in_array($after['rank'] ?? null, [1, 2, 3], true)
            || ! is_string($after['id'] ?? null) || ! Str::isUuid($after['id'])
            || (($after['missing'] === 1) ? ($after['time'] ?? 'invalid') !== '' : ! self::timestamp($after['time'] ?? null))) {
            ApiError::abort(422, 'DOCUMENTS_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại từ start.');
        }

        return $state;
    }

    public static function read(Builder $query, array $state, int $size, string $sort): array
    {
        $direction = $sort === 'created_at_asc' ? 'asc' : 'desc';
        $operator = $direction === 'asc' ? '>' : '<';
        $after = $state['after'];
        if ($after !== null) {
            $query->where(function (Builder $outer) use ($after, $operator): void {
                $outer->where('time_missing', '>', $after['missing'])
                    ->orWhere(function (Builder $same) use ($after, $operator): void {
                        $same->where('time_missing', $after['missing'])->where(function (Builder $tail) use ($after, $operator): void {
                            if ($after['missing'] === 0) {
                                $tail->where('created_at', $operator, $after['time']);
                            }
                            $tail->orWhere(function (Builder $ties) use ($after, $operator): void {
                                if ($after['missing'] === 0) {
                                    $ties->where('created_at', $after['time']);
                                } else {
                                    $ties->whereNull('created_at');
                                }
                                $ties->where(fn (Builder $ids) => $ids->where('source_rank', $operator, $after['rank'])
                                    ->orWhere(fn (Builder $id) => $id->where('source_rank', $after['rank'])->where('id', $operator, $after['id'])));
                            });
                        });
                    });
            });
        }
        $rows = $query->orderBy('time_missing')->orderBy('created_at', $direction)
            ->orderBy('source_rank', $direction)->orderBy('id', $direction)->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size)->values();
        $next = null;
        if ($more) {
            $last = $rows->last();
            $state['after'] = [
                'missing' => (int) $last->time_missing, 'time' => $last->created_at ?? '',
                'rank' => (int) $last->source_rank, 'id' => $last->id,
            ];
            // The original expiry and watermark survive every page, not a sliding TTL.
            $next = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        }

        return [$rows, ['has_more' => $more, 'next_cursor' => $next, 'returned_count' => $rows->count()]];
    }

    private static function timestamp(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:[+-]\d{2}(?::\d{2})?|Z)?$/D', $value) === 1;
    }
}
