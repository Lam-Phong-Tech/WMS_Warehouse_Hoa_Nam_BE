<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;

/** Client-declared installation identity, never inferred physical hardware or location. */
final class ClientInstallations
{
    public const SOURCE = 'CLIENT_DECLARED_INSTALLATION_NOT_VERIFIED_PHYSICAL_DEVICE';

    /** Caller holds the user lock. A rotation can never reassign its original installation. */
    public static function forSession(string $user, Request $request, ?string $family): ?string
    {
        if ($family !== null) {
            $id = P10SessionFamily::query($user, $family)->oldest('created_at')->value('auth_device_id');
            if ($id !== null) {
                DB::table('auth_client_installations')->where('user_id', $user)->where('id', $id)->update(['last_seen_at' => now('UTC')]);
            }

            return $id;
        }
        $device = trim((string) $request->header('X-Device-Id'));
        if ($device === '') {
            return null;
        }
        $metadata = ['device_id' => $device, 'device_label' => $request->header('X-Device-Label'),
            'platform' => $request->header('X-Client-Platform'), 'client_type' => $request->header('X-Client-Type')];
        // Browser header values are byte strings, not automatically UTF-8.
        // Reject invalid bytes before validation/SQL/JSON can encounter them;
        // never echo untrusted header contents in the error response or audit.
        foreach ($metadata as $field => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                ApiError::abort(422, 'INVALID_DEVICE_METADATA', 'Thông tin ứng dụng không đúng định dạng UTF-8.', ['fields' => [$field]]);
            }
        }
        $data = Validator::make($metadata, [
            'device_id' => ['required', 'uuid:4'], 'device_label' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'platform' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/'],
            'client_type' => ['nullable', 'in:web,app'],
        ])->validate();
        $device = strtolower($data['device_id']);
        $existing = DB::table('auth_client_installations')->where('user_id', $user)->where('client_device_id', $device)->first();
        $id = $existing->id ?? (string) Str::uuid();
        DB::table('auth_client_installations')->updateOrInsert(['user_id' => $user, 'client_device_id' => $device], [
            'id' => $id, 'device_label' => $data['device_label'], 'platform' => $data['platform'], 'client_type' => $data['client_type'],
            'first_seen_at' => $existing->first_seen_at ?? now('UTC'), 'last_seen_at' => now('UTC'),
        ]);

        return $id;
    }

    /** Existing P10 live-family query, retaining the refresh row mutation target. */
    public static function activeFamilies(string $user): Collection
    {
        $ranked = RefreshToken::query()->where('user_id', $user)->active()
            ->select(['id', 'auth_session_id', 'auth_device_id', 'auth_session_started_at', 'user_agent', 'ip_address', 'created_at', 'expires_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY COALESCE(auth_session_id, id) ORDER BY created_at DESC, id DESC) AS p10_rank');

        return DB::query()->fromSub($ranked, 'p10_sessions')->where('p10_rank', 1)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public static function session(object $row, string $current, ?object $device = null): array
    {
        $family = P10ProfilePolicy::family($row->auth_session_id, (string) $row->id);
        $isCurrent = hash_equals($current, $family);

        return [
            'id' => (string) $row->id, 'auth_session_id' => $family, 'session_id' => $family,
            'is_current' => $isCurrent, 'can_revoke' => ! $isCurrent,
            'device_id' => $device->id ?? null, 'device_label' => $device->device_label ?? null, 'location' => null,
            'platform' => $device->platform ?? null, 'client_type' => $device->client_type ?? null,
            'user_agent' => $row->user_agent, 'ip_address' => $row->ip_address,
            'metadata_source' => $device === null ? 'STORED_REQUEST_HEADERS_NOT_VERIFIED_DEVICE_OR_LOCATION' : self::SOURCE,
            'started_at' => $row->auth_session_started_at === null ? null : Carbon::parse($row->auth_session_started_at, 'UTC')->toIso8601String(),
            'started_at_semantics' => $row->auth_session_started_at === null ? 'UNAVAILABLE_LEGACY_SESSION' : 'COMPLETED_LOGIN_TIMESTAMP',
            'last_active_at' => self::time($row->created_at),
            'last_active_semantics' => 'LOGIN_OR_TOKEN_REFRESH_NOT_EACH_API_REQUEST', 'expires_at' => self::time($row->expires_at),
        ];
    }

    public static function time(?string $value): ?string
    {
        // Existing refresh timestamps follow the application's DB timezone convention.
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    public static function groups(string $user, string $current): array
    {
        $rows = self::activeFamilies($user);
        $devices = DB::table('auth_client_installations')->where('user_id', $user)->get()->keyBy('id');
        $groups = [];
        foreach ($rows as $row) {
            $family = P10ProfilePolicy::family($row->auth_session_id, (string) $row->id);
            $device = $devices->get($row->auth_device_id);
            $key = $device->id ?? $family;
            $session = self::session($row, $current, $device);
            if (! isset($groups[$key])) {
                $groups[$key] = ['device_id' => $device->id ?? null, 'group_key' => $key,
                    'device_label' => $device->device_label ?? null, 'platform' => $device->platform ?? null,
                    'client_type' => $device->client_type ?? null,
                    'metadata_source' => $device === null ? 'UNKNOWN_LEGACY_INSTALLATION' : self::SOURCE,
                    'first_seen_at' => $device === null ? $session['started_at'] : Carbon::parse($device->first_seen_at, 'UTC')->toIso8601String(),
                    'last_active_at' => $session['last_active_at'], 'last_active_semantics' => $session['last_active_semantics'],
                    'is_current' => false, 'can_revoke' => $device !== null, 'session_count' => 0, 'sessions' => []];
            }
            $groups[$key]['sessions'][] = $session;
            $groups[$key]['session_count']++;
            $groups[$key]['is_current'] = $groups[$key]['is_current'] || $session['is_current'];
            $groups[$key]['can_revoke'] = $device !== null && ! $groups[$key]['is_current'];
        }
        $known = count(array_filter($groups, fn ($group) => $group['device_id'] !== null));

        return ['data' => array_values($groups), 'meta' => [
            'current_session_id' => $current, 'active_device_count' => $known,
            'unknown_device_group_count' => count($groups) - $known, 'active_session_count' => $rows->count(),
            'other_session_count' => $rows->filter(fn ($row) => P10ProfilePolicy::family($row->auth_session_id, (string) $row->id) !== $current)->count(),
            'account_session_policy' => AccountSessionPolicy::contract(),
            'device_policy' => ['identity' => 'CLIENT_INSTALLATION_NOT_PHYSICAL_DEVICE', 'max_devices' => null,
                'max_sessions_per_device' => null, 'limits_configured' => false],
            'request_receipt_supported' => false,
        ]];
    }
}
