<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Support\ApiAuthContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nhân viên mới được admin cấp mật khẩu (User::must_change_password=true, xem
 * UserController::store()) phải đổi mật khẩu trước khi dùng bất kỳ chức năng
 * nào khác. Chặn global (mẫu EnforceActiveAccountStatus) và trả đúng
 * `403 PASSWORD_CHANGE_REQUIRED` mà FE đã có sẵn hạ tầng redirect
 * (src/shared/api/axios.ts, src/app/router/ProtectedRoute.tsx) — FE build
 * trước, BE trước đây chưa từng phát ra mã này.
 *
 * Miễn trừ:
 *  - change-password (để thực sự đổi được) và logout (để luôn thoát được phiên);
 *  - toàn bộ endpoint public trong ApiAuthContext::PUBLIC_PATH_PATTERNS. Bắt
 *    buộc: FE gắn Bearer token cũ vào cả POST /api/v1/auth/login, nếu không
 *    miễn thì tài khoản đang must_change_password sẽ nhận 403 ngay trên request
 *    đăng nhập và không bao giờ vào được màn đổi mật khẩu. `auth/refresh` cũng
 *    phải chạy được để phiên không chết khi người dùng đang ở màn đổi mật khẩu.
 */
class EnforcePasswordChangeRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiAuthContext::isPublicEndpoint($request)) {
            return $next($request);
        }

        $user = ApiAuthContext::resolveUser();

        if ($user !== null
            && $user->requiresPasswordChange()
            && ! $request->is('api/v1/auth/change-password')
            && ! $request->is('api/v1/auth/logout')
        ) {
            $maxAgeDays = (int) config('login_security.password.max_age_days', 90);
            $message = (! $user->must_change_password && $user->isPasswordExpired())
                ? "Mật khẩu của bạn đã quá hạn {$maxAgeDays} ngày. Vui lòng đặt lại mật khẩu mới để tiếp tục sử dụng hệ thống."
                : 'Bạn phải đổi mật khẩu trước khi tiếp tục sử dụng hệ thống.';

            ApiError::abort(403, 'PASSWORD_CHANGE_REQUIRED', $message);
        }

        return $next($request);
    }
}
