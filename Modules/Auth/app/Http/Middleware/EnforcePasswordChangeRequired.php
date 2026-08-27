<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nhân viên mới được admin cấp mật khẩu (User::must_change_password=true, xem
 * UserController::store()) phải đổi mật khẩu trước khi dùng bất kỳ chức năng
 * nào khác. Chặn global (mẫu EnforceActiveAccountStatus) và trả đúng
 * `403 PASSWORD_CHANGE_REQUIRED` mà FE đã có sẵn hạ tầng redirect
 * (src/shared/api/axios.ts, src/app/router/ProtectedRoute.tsx) — FE build
 * trước, BE trước đây chưa từng phát ra mã này.
 *
 * Chỉ 2 route được miễn: change-password (để thực sự đổi được) và logout (để
 * luôn thoát được phiên).
 */
class EnforcePasswordChangeRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if ($user instanceof User
            && $user->must_change_password
            && ! $request->is('api/v1/auth/change-password')
            && ! $request->is('api/v1/auth/logout')
        ) {
            ApiError::abort(403, 'PASSWORD_CHANGE_REQUIRED', 'Bạn phải đổi mật khẩu trước khi tiếp tục sử dụng hệ thống.');
        }

        return $next($request);
    }
}
