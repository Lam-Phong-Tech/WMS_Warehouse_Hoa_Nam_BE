<?php

namespace Modules\Auth\Support;

use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Throwable;

/**
 * Hai middleware global EnforceActiveAccountStatus / EnforcePasswordChangeRequired
 * được `append` trong bootstrap/app.php nên chúng chạy TRƯỚC route middleware,
 * tức chạy cả trên những endpoint vốn KHÔNG cần đăng nhập (login, refresh, quên
 * mật khẩu, health, `/api/v1/public/*`).
 *
 * Hai lỗi thật đã gặp khi deploy:
 *  1. FE gắn `Authorization: Bearer <token cũ>` cho mọi request, kể cả
 *     `POST /api/v1/auth/login`. Token cũ đó có thể thuộc tài khoản đã bị khoá
 *     hoặc đang `must_change_password` — middleware đọc được user từ token và
 *     trả 403 ACCOUNT_DISABLED / 403 PASSWORD_CHANGE_REQUIRED ngay trên chính
 *     request đăng nhập. Người dùng không đăng nhập lại được, kể cả bằng tài
 *     khoản khác, cho tới khi tự xoá token trong localStorage.
 *  2. Header Authorization rác/bị cắt làm `auth('api')->user()` ném exception
 *     nằm ngoài JWTException; handler Throwable chung ở bootstrap/app.php biến
 *     nó thành 500 INTERNAL_SERVER_ERROR cho login.
 *
 * Lớp này là chốt chặn duy nhất cho cả hai:
 *  - `isPublicEndpoint()` liệt kê path phải chạy được khi chưa đăng nhập;
 *  - `resolveUser()` resolve JWT user "không gây hại": mọi lỗi parse/verify
 *    token quy về null. 401 canonical vẫn do `auth:api` ở route phát ra, còn
 *    ACTIVE/must_change_password của chính request login vẫn do AuthController
 *    kiểm (xem AuthController::login) nên bỏ qua ở đây không mất lớp chặn nào.
 */
final class ApiAuthContext
{
    /**
     * Mẫu path (cú pháp `Request::is`) chạy được khi chưa đăng nhập. Nguồn sự
     * thật: Modules/Auth/routes/api.php (nhóm nằm NGOÀI `Route::middleware('auth:api')`),
     * routes/api.php (health) và Modules/Warehouse/routes/public_api.php.
     *
     * @var list<string>
     */
    public const PUBLIC_PATH_PATTERNS = [
        'api/v1/auth/login',
        // Bước 2 của đăng nhập hai bước. Route này CỐ Ý không có `auth:api`: bước
        // 1 không phát token nào, nên tại đây chưa hề có actor. Không khai vào
        // đây thì EnforceActiveAccountStatus / EnforceCredentialFreshness /
        // EnforcePasswordChangeRequired vẫn chạy trên một request không có user —
        // ba lượt resolve vô nghĩa, và tệ hơn là che mất chỗ kiểm tra thật.
        //
        // Không mất lớp chặn nào: route có `throttle:auth-mfa-verify` riêng (nên
        // ApiRateLimit vốn đã bỏ qua nó qua nhánh hasExplicitThrottle), phiên chờ
        // chỉ dùng được một lần, và MfaController::verify tự kiểm lại
        // `status === 'ACTIVE'` trước khi phát token.
        'api/v1/auth/mfa/verify',
        'api/v1/auth/refresh',
        'api/v1/auth/forgot-password',
        'api/v1/auth/verify-otp',
        'api/v1/auth/reset-password',
        // P14 intake/reconcile authenticate only their own receipt capability.
        // Stale account tokens must not disclose existence or block support.
        // This never exempts /auth/me or any shift/document endpoint.
        'api/v1/auth/recovery-requests',
        'api/v1/auth/recovery-requests/operations/*',
        'api/v1/health',
        'api/v1/public',
        'api/v1/public/*',
        // RUM Web Vitals beacon (routes/api.php). Ẩn danh CÓ CHỦ Ý: beacon bắn
        // cả trước đăng nhập/sau logout, không được làm phiên chết vì thiếu
        // token. Không mất lớp chặn nào: route mang `throttle:120,1` riêng
        // (ApiRateLimit vốn bỏ qua route có throttle tường minh), body chỉ là
        // số liệu hiệu năng, không side-effect nghiệp vụ.
        'api/v1/telemetry/vitals',
    ];

    private function __construct() {}

    public static function isPublicEndpoint(Request $request): bool
    {
        return $request->is(...self::PUBLIC_PATH_PATTERNS);
    }

    /**
     * `auth('api')->user()` nhưng không bao giờ ném ra ngoài. Token thiếu, hết
     * hạn, sai chữ ký hay header dị dạng đều trả null thay vì đẩy request
     * xuống handler 401/500 toàn cục.
     */
    public static function resolveUser(): ?User
    {
        try {
            $user = auth('api')->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }
}
