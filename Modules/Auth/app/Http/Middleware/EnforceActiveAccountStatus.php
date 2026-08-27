<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vô hiệu hoá tài khoản (status=INACTIVE|LOCKED) đã chặn login/refresh (AuthController,
 * RefreshTokenService), nhưng một JWT access token phát hành TRƯỚC thời điểm khoá vẫn
 * còn hiệu lực cho tới khi hết hạn tự nhiên — request nào dùng token đó vẫn lọt qua
 * CheckPermission bình thường. Middleware này chặn khoảng hở đó: khoá tài khoản có
 * hiệu lực ngay từ request kế tiếp, không cần chờ token hết hạn.
 *
 * Đăng ký global giống EnforceInventoryWarehouseScope — resolve `auth('api')->user()`
 * và no-op khi chưa đăng nhập (401 canonical do auth:api ở route xử lý).
 */
class EnforceActiveAccountStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if ($user instanceof User && $user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCOUNT_DISABLED', 'Tài khoản đang bị khoá hoặc ngừng hoạt động. Vui lòng liên hệ quản trị viên.');
        }

        return $next($request);
    }
}
