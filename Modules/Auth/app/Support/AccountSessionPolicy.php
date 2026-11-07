<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;

/** Account-wide login replacement; no inferred device, role or warehouse exemptions. */
final class AccountSessionPolicy
{
    public const CONTRACT = 'auth-session-replacement-v1';

    public const CONTROLLED_CONTRACT = 'auth-session-policy-v2';

    public const MAX_ACTIVE_FAMILIES = 2;

    public static function enabled(): bool
    {
        return self::controlledMode() || (bool) config('refresh_token.single_active_session.enabled', false);
    }

    public static function controlledMode(): bool
    {
        return (bool) config('refresh_token.controlled_multi_session.enabled', false);
    }

    public static function replaceExistingSessions(): bool
    {
        return self::enabled() && ! self::controlledMode();
    }

    /** Caller holds the same parent-user lock as refresh and revocation. */
    public static function assertAdmissionAllowed(User $user): void
    {
        if (! self::controlledMode()) {
            return;
        }
        self::assertReady();
        if (config('refresh_token.controlled_multi_session.draining', false)) {
            ApiError::abort(409, 'AUTH_SESSION_ADMISSION_DRAINING', 'Tạm dừng tạo phiên mới; các phiên đang hoạt động được giữ nguyên.');
        }
        $maximum = self::maxActiveFamilies();
        if ($maximum === null) {
            return;
        }
        $count = RefreshToken::query()->where('user_id', $user->user_id)->active()
            ->whereNull('replaced_by_auth_session_id')->distinct()
            ->count(DB::raw('COALESCE(auth_session_id, id)'));
        if ($count >= $maximum) {
            ApiError::abort(409, 'AUTH_SESSION_LIMIT_REACHED', 'Tài khoản đã đạt giới hạn phiên hoạt động. Vui lòng chủ động đăng xuất một phiên trước khi đăng nhập thêm.', [], [
                'max_active_families' => $maximum,
                'automatic_eviction' => false,
            ]);
        }
    }

    public static function maxActiveFamilies(): ?int
    {
        $value = config('refresh_token.controlled_multi_session.max_active_families', self::MAX_ACTIVE_FAMILIES);
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value) !== 1
            || ($maximum = filter_var($value, FILTER_VALIDATE_INT)) === false) {
            ApiError::abort(503, 'AUTH_SESSION_POLICY_NOT_READY', 'Cấu hình giới hạn phiên không hợp lệ.');
        }

        return $maximum === 0 ? null : $maximum;
    }

    public static function assertReady(): void
    {
        $origin = rtrim((string) config('app.url'), '/');
        $host = parse_url($origin, PHP_URL_HOST);
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) && in_array($scheme, ['http', 'https'], true);
        if (self::controlledMode() && (! app()->environment('local', 'testing')
            || (! $local && $origin !== 'https://khohoanamdev.lptech.info.vn'))) {
            ApiError::abort(503, 'AUTH_SESSION_POLICY_NOT_READY', 'Chính sách đa phiên chỉ được phép trên môi trường DEV/TEST đã duyệt.');
        }
        if (self::controlledMode()) {
            self::maxActiveFamilies();
        }
        if (self::enabled() && (! Schema::hasColumn('refresh_tokens', 'replaced_by_auth_session_id')
            || ! Schema::hasColumn('refresh_tokens', 'auth_session_id'))) {
            ApiError::abort(503, 'AUTH_SESSION_POLICY_NOT_READY', 'Chính sách phiên chưa sẵn sàng. Vui lòng thử lại sau.');
        }
    }

    public static function replaced(?string $replacingFamily = null): never
    {
        ApiError::abort(401, 'AUTH_SESSION_REPLACED', 'Tài khoản đã đăng nhập ở phiên khác. Vui lòng đăng nhập lại.', [], [
            'replacement' => self::replacementDetails($replacingFamily),
        ]);
    }

    /** Safe information for the displaced client; no raw IP or user agent. */
    public static function replacementDetails(?string $family): array
    {
        if ($family === null || $family === '') {
            return [];
        }
        $session = RefreshToken::query()->where('auth_session_id', $family)->oldest('created_at')->first();
        if ($session === null) {
            return [];
        }
        $agent = strtolower((string) $session->user_agent);
        $browser = match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'firefox/') => 'Firefox',
            str_contains($agent, 'chrome/') || str_contains($agent, 'crios/') => 'Chrome',
            str_contains($agent, 'safari/') => 'Safari',
            default => null,
        };
        $device = match (true) {
            str_contains($agent, 'iphone') => 'iPhone',
            str_contains($agent, 'ipad') => 'iPad',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh') || str_contains($agent, 'mac os') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => 'Thiết bị khác',
        };
        $ipHint = null;
        if (filter_var($session->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', (string) $session->ip_address);
            $ipHint = implode('.', array_slice($octets, 0, 3)).'.xxx';
        }

        return [
            'device_label' => $browser === null ? $device : $browser.' trên '.$device,
            'signed_in_at' => $session->created_at?->toIso8601String(),
            'ip_hint' => $ipHint,
        ];
    }

    public static function contract(): array
    {
        if (self::controlledMode()) {
            $maximum = self::maxActiveFamilies();

            return [
                'version' => self::CONTROLLED_CONTRACT,
                'revision' => 'controlled-multi-session-r2',
                'enabled' => true,
                'mode' => config('refresh_token.controlled_multi_session.draining', false) ? 'CONTROLLED_MULTI_SESSION_DRAINING' : 'CONTROLLED_MULTI_SESSION',
                'scope' => 'ACCOUNT_AUTH_SESSION_FAMILIES',
                'count_unit' => 'ACTIVE_AUTH_SESSION_FAMILY',
                'admission_limit' => $maximum === null ? 'UNLIMITED' : 'LIMITED',
                'max_active_families' => $maximum,
                'stable_session_required' => true,
                'automatic_eviction' => false,
                'role_exemptions' => [],
                'limit_error' => $maximum === null ? null : 'AUTH_SESSION_LIMIT_REACHED',
                'logout_scope' => 'CURRENT_AUTH_SESSION_FAMILY',
                'replay_scope' => 'PERSISTED_PRESENTED_TOKEN_FAMILY_ONLY',
                'legacy_revoked_reason' => 'NOT_INFERRED',
                'global_security_revocation_preserved' => true,
                'legacy_access_policy' => 'RELOGIN_REQUIRED',
                'replacement_error' => 'AUTH_SESSION_REPLACED',
                'push_disconnect_supported' => false,
                'server_detection_boundary' => 'NEXT_PROTECTED_REQUEST_NO_REVOCATION_CACHE',
                'work_shift_ended' => false,
                'business_drafts_deleted' => false,
                'automatic_login_retry' => false,
            ];
        }

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
