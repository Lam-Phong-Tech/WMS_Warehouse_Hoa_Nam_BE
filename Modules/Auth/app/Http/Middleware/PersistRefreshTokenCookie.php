<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Support\RefreshTokenTransport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cổng kênh refresh token cho các route xác thực: login, MFA verify, refresh, logout,
 * change-password. Chính sách nằm ở RefreshTokenTransport; middleware này làm ba việc:
 *
 *  1. chốt kênh của request (cookie mặc định; body chỉ khi được phép) — xin kênh sai là
 *     422/403 trước khi controller chạy;
 *  2. kênh cookie: request mang Origin/Referer KHÔNG thuộc allowlist bị từ chối trước khi
 *     chạm mật khẩu hay cookie (login-CSRF, CSRF cưỡng ép xoay vòng). Riêng /auth/refresh
 *     còn bắt buộc PHẢI có Origin/Referer tin cậy — đó là endpoint duy nhất xác thực bằng
 *     chính cookie;
 *  3. kênh cookie: hết hạn cookie khi refresh bằng cookie thất bại (tránh vòng F5 → 401 vô
 *     hạn) và khi logout/đổi mật khẩu thành công.
 *
 * Việc đặt refresh token vào response do controller gọi RefreshTokenTransport::deliver()
 * ngay tại chỗ phát token: bất biến "kênh cookie không có token trong JSON" không phụ thuộc
 * vào việc middleware này có được gắn đúng route hay không.
 */
class PersistRefreshTokenCookie
{
    /** Action phát refresh token hoặc xác thực bằng nó. */
    private const TOKEN_ACTIONS = ['login', 'verify', 'refresh'];

    /** Action kết thúc phiên trình duyệt khi thành công. */
    private const SESSION_ENDING_ACTIONS = ['logout', 'changePassword'];

    public function __construct(private readonly RefreshTokenTransport $transport) {}

    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()?->getActionMethod();
        $mode = $this->transport->mode($request);

        if ($mode === RefreshTokenTransport::COOKIE && in_array($action, self::TOKEN_ACTIONS, true)) {
            $origin = $this->transport->requestOrigin($request);
            $untrustedOrigin = $origin !== null && ! $this->transport->isTrustedBrowserOrigin($origin, $request);
            $cookieRefreshWithoutOrigin = $action === 'refresh' && $origin === null;

            if ($untrustedOrigin || $cookieRefreshWithoutOrigin) {
                ApiError::abort(403, 'ORIGIN_NOT_ALLOWED', 'Nguồn gọi (Origin) không được phép dùng phiên đăng nhập bằng cookie.');
            }
        }

        /** @var Response $response */
        $response = $next($request);

        if ($mode !== RefreshTokenTransport::COOKIE) {
            return $response;
        }

        $presentedCookie = $request->cookie($this->transport->cookieName());
        $failedCookieRefresh = $action === 'refresh'
            && ! $response->isSuccessful()
            && is_string($presentedCookie)
            && $presentedCookie !== '';
        $endedSession = in_array($action, self::SESSION_ENDING_ACTIONS, true) && $response->isSuccessful();

        if ($failedCookieRefresh || $endedSession) {
            $response->headers->setCookie($this->transport->expiredCookie());
        }

        return $response;
    }
}
