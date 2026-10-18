<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\Schema;

/** Account-wide login replacement; no inferred device, role or warehouse exemptions. */
final class AccountSessionPolicy
{
    public const CONTRACT = 'auth-session-replacement-v1';

    public static function enabled(): bool
    {
        return (bool) config('refresh_token.single_active_session.enabled', false);
    }

    public static function assertReady(): void
    {
        if (self::enabled() && ! Schema::hasColumn('refresh_tokens', 'replaced_by_auth_session_id')) {
            ApiError::abort(503, 'AUTH_SESSION_POLICY_NOT_READY', 'Chính sách phiên chưa sẵn sàng. Vui lòng thử lại sau.');
        }
    }

    public static function replaced(): never
    {
        ApiError::abort(401, 'AUTH_SESSION_REPLACED', 'Tài khoản đã đăng nhập ở phiên khác. Vui lòng đăng nhập lại.');
    }

    public static function contract(): array
    {
        return [
            'version' => self::CONTRACT,
            'enabled' => self::enabled(),
            'scope' => 'ACCOUNT_ALL_CLIENTS',
            'role_exemptions' => [],
            'replacement_boundary' => 'COMMITTED_COMPLETED_LOGIN_NOT_PASSWORD_OR_MFA_CHALLENGE',
            'legacy_access_policy' => self::enabled() ? 'RELOGIN_REQUIRED' : 'EXISTING_POLICY',
            'replacement_error' => 'AUTH_SESSION_REPLACED',
            'idle_detection' => 'AUTHENTICATED_CHECK_ON_FOREGROUND_AND_BEFORE_COMMAND',
            'push_disconnect_supported' => false,
            'recommended_foreground_check_interval_seconds' => 60,
            'server_detection_boundary' => 'NEXT_PROTECTED_REQUEST_NO_REVOCATION_CACHE',
            'in_flight_request_cancellation' => false,
            'work_shift_ended' => false,
            'business_drafts_deleted' => false,
        ];
    }
}
