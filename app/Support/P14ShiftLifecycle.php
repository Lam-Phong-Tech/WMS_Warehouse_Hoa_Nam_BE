<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;

/** Admission, closure and fencing share this policy; no runtime flag is enabled here. */
final class P14ShiftLifecycle
{
    public const VERSION = 'p01-p14-lifecycle-v1';

    public static function mode(): string
    {
        if (config('p14.shift_drain_enabled', false)) {
            return 'DRAINING';
        }

        return config('p14.shift_enabled', false) ? 'ENABLED' : 'DISABLED';
    }

    public static function closureEnabled(): bool
    {
        return self::mode() !== 'DISABLED';
    }

    public static function assertNewStartAllowed(): void
    {
        if (self::mode() === 'DRAINING') {
            ApiError::abort(503, 'SHIFT_START_PAUSED', 'Đang hoàn tất các ca hiện có; chưa mở ca mới.');
        }
    }

    public static function payload(object $shift): array
    {
        $tracked = ($shift->tracking_contract ?? null) === P14ShiftService::CONTRACT;
        $reason = match (true) {
            $shift->ended_at !== null => 'SHIFT_ALREADY_ENDED',
            ! $tracked => 'SHIFT_TRACKING_INCOMPLETE',
            ! self::closureEnabled() => 'P14_SHIFT_NOT_ENABLED',
            default => null,
        };

        return [
            'contract' => self::VERSION, 'mode' => self::mode(),
            'tracking_status' => $tracked ? 'TRACKED' : 'LEGACY_UNTRACKED',
            'end_supported' => $reason === null, 'end_unavailable_reason' => $reason,
            'end_readiness' => 'REQUIRES_CLOSE_CHECK_AND_LOCAL_CLEAN',
            'owner_coverage' => 'PARTIAL_EXPLICIT_OWNER_PROTOCOLS',
            'local_state_verified' => false, 'legacy_upgrade_allowed' => false,
        ];
    }

    public static function fenceRequired(Request $request): bool
    {
        if (config('p14.shift_enabled', false)) {
            return true;
        }
        if (self::mode() !== 'DRAINING') {
            return false;
        }
        $actor = $request->user() ?? auth('api')->user();
        if (! $actor instanceof User) {
            return false; // Existing authentication middleware remains authoritative.
        }
        $session = app(StableAuthSession::class)->requireId($actor);

        // Include ended tracked shifts: removing the header after END must not
        // bypass the fence. Other login families are not silently enrolled.
        return DB::table('mini_app_work_shifts')->where('actor_id', $actor->user_id)
            ->where('auth_session_id', $session)->where('tracking_contract', P14ShiftService::CONTRACT)->exists();
    }
}
