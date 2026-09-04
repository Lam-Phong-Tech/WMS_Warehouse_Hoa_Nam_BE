<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Support\ApiAuthContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vô hiệu hoá tài khoản (status=INACTIVE|LOCKED) đã chặn login/refresh (AuthController,
 * RefreshTokenService), nhưng một JWT access token phát hành TRƯỚC thời điểm khoá vẫn
 * còn hiệu lực cho tới khi hết hạn tự nhiên — request nào dùng token đó vẫn lọt qua
 * CheckPermission bình thường. Middleware này chặn khoảng hở đó: khoá tài khoản có
 * hiệu lực ngay từ request kế tiếp, không cần chờ token hết hạn.
 *
 * Đăng ký global giống EnforceInventoryWarehouseScope — resolve user qua
 * ApiAuthContext và no-op khi chưa đăng nhập (401 canonical do auth:api ở route
 * xử lý).
 *
 * Bỏ qua hoàn toàn các endpoint public (ApiAuthContext::PUBLIC_PATH_PATTERNS):
 * FE gắn Bearer token cũ vào cả request login, nếu không bỏ qua thì token của
 * tài khoản đã khoá sẽ làm chính request đăng nhập trả 403 ACCOUNT_DISABLED.
 * Không mất lớp chặn nào: AuthController::login tự kiểm status và trả 403
 * ACCESS_DENIED với message phân biệt được nguyên nhân khoá, còn
 * RefreshTokenService::rotate thu hồi toàn bộ refresh token khi user không ACTIVE.
 */
class EnforceActiveAccountStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiAuthContext::isPublicEndpoint($request)) {
            return $next($request);
        }

        $user = ApiAuthContext::resolveUser();

        if ($user !== null && $user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCOUNT_DISABLED', 'Tài khoản đang bị khoá hoặc ngừng hoạt động. Vui lòng liên hệ quản trị viên.');
        }

        return $next($request);
    }
}
