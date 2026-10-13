<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // An absent pre-P14 schema cannot contain a tracked family. A query
        // failure is not caught: unavailable evidence must not bypass fencing.
        if (self::mode() === 'DISABLED' && ! Schema::hasColumn('mini_app_work_shifts', 'tracking_contract')) {
            return false;
        }
        $actor = $request->user() ?? auth('api')->user();
        if (! $actor instanceof User) {
            return false; // Existing authentication middleware remains authoritative.
        }
        $sessions = app(StableAuthSession::class);
        $session = self::mode() === 'DRAINING' ? $sessions->requireId($actor) : $sessions->id($actor);
        if ($session === null) {
            return false; // Preserve existing legacy authentication, never infer a family.
        }

        // Include ended tracked shifts, even when both flags are accidentally
        // OFF. The owner guard then fails closed (503), not untracked writes.
        // Recovery requires an approved return to ENABLED/DRAINING; no flag is
        // automatically enabled and unrelated login families remain unchanged.
        return DB::table('mini_app_work_shifts')->where('actor_id', $actor->user_id)
            ->where('auth_session_id', $session)->where('tracking_contract', P14ShiftService::CONTRACT)->exists();
    }
}
