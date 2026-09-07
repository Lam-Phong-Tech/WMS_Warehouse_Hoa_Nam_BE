<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Support\ApiAuthContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * WWHN-SEC-02 — access token phát hành TRƯỚC lần đổi mật khẩu gần nhất bị từ chối.
 *
 * Khoảng hở được bịt: đổi mật khẩu (tự đổi, quên mật khẩu, hoặc admin đặt lại)
 * chỉ thu hồi refresh token. Access token là JWT stateless nên bản sao đã bị
 * đánh cắp vẫn gọi API bình thường tới khi hết hạn tự nhiên. Nghĩa là hành động
 * phản xạ đúng đắn nhất khi nghi lộ tài khoản — đổi mật khẩu — lại KHÔNG cắt
 * được quyền truy cập của kẻ tấn công.
 *
 * Cách so sánh: claim `iat` (giây) < `password_changed_at` quy về giây.
 *  - Quy về giây là CÓ CHỦ Ý. `iat` chỉ có độ phân giải giây còn
 *    `password_changed_at` có micro-giây; so trực tiếp thì chính token vừa được
 *    cấp lại trong cùng giây đó (AuthController::changePassword trả token mới)
 *    sẽ bị từ chối ngay — người vừa đổi mật khẩu bị đăng xuất tức thì.
 *  - Hệ quả: token cấp trong CÙNG GIÂY với lúc đổi mật khẩu vẫn được chấp nhận.
 *    Cửa sổ 1 giây này là đánh đổi cố ý, đúng bản chất của thu hồi JWT dựa trên
 *    thời gian; các lớp khác (thu hồi refresh token, blacklist khi logout,
 *    EnforceActiveAccountStatus) vẫn nguyên vẹn.
 *
 * Đăng ký global cùng nhóm EnforceActiveAccountStatus và vì đúng lý do đó: kiểm
 * ở từng route thì route mới sẽ âm thầm thiếu lớp chặn này.
 *
 * Chi phí: 0 truy vấn thêm — user đã được resolve sẵn cho middleware kế bên,
 * payload đọc từ chính token đang xác thực.
 */
class EnforceCredentialFreshness
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiAuthContext::isPublicEndpoint($request)) {
            return $next($request);
        }

        $user = ApiAuthContext::resolveUser();

        if ($user === null) {
            return $next($request);
        }

        // Quy về epoch giây qua User::passwordChangedAtEpoch() — KHÔNG đọc thẳng
        // thuộc tính. Xem ghi chú ở model: giá trị thô lưu theo UTC "trần" và
        // đọc sai timezone sẽ đẩy mốc lên tương lai, khoá nhầm cả token mới cấp.
        $changedAt = $user->passwordChangedAtEpoch();

        if ($changedAt === null) {
            return $next($request);
        }

        $issuedAt = $this->tokenIssuedAt();

        if ($issuedAt !== null && $issuedAt < $changedAt) {
            ApiError::abort(
                401,
                'CREDENTIALS_CHANGED',
                'Mật khẩu của tài khoản đã được thay đổi. Vui lòng đăng nhập lại.',
            );
        }

        return $next($request);
    }

    /**
     * Claim `iat` của access token đang dùng. Mọi lỗi đọc/parse quy về null —
     * token hỏng đã được `auth:api` xử lý thành 401 canonical ở route, middleware
     * này không được biến nó thành 500.
     */
    private function tokenIssuedAt(): ?int
    {
        try {
            $payload = auth('api')->payload();
        } catch (Throwable) {
            return null;
        }

        $issuedAt = $payload->get('iat');

        return is_numeric($issuedAt) ? (int) $issuedAt : null;
    }
}
