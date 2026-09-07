<?php

namespace App\Http\Middleware;

use App\Support\RateLimitResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ApiAuthContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * WWHN-SEC-01 — trần gọi API cho nhóm ĐÃ ĐĂNG NHẬP (OWASP API4:2023
 * Unrestricted Resource Consumption).
 *
 * Bằng chứng trước khi có lớp này: `php artisan route:list` cho thấy 0/321
 * route `auth:api` mang middleware `throttle:` nào, trong đó có 170 route GHI;
 * bắn 60 request liên tiếp vào `GET /api/v1/products` trả 60× HTTP 200.
 *
 * ĐĂNG KÝ GLOBAL LÀ CÓ CHỦ Ý, cùng lý do đã ghi cho EnforceInventoryWarehouseScope
 * trong bootstrap/app.php: gắn `throttle:` vào từng nhóm route trong 5 file
 * routes (551 + 238 + 48 + 24 + 56 dòng) thì một lần refactor route sau này chỉ
 * cần quên một nhóm là mất trần ở đó mà không có gì báo. Ở đây phân loại theo
 * chính request nên route mới sinh ra đã có trần sẵn.
 *
 * KHÔNG áp lên:
 *  - path ngoài `api/*`;
 *  - route đã tự khai `throttle:` (pv-public, public-api, public-quote-request,
 *    auth-refresh) — tránh đếm hai lần và tránh phá ngưỡng đã hiệu chỉnh riêng;
 *  - endpoint public trong ApiAuthContext::PUBLIC_PATH_PATTERNS: `/auth/login`
 *    đã có bộ đếm brute-force riêng chỉ tính lần THẤT BẠI (AuthController), còn
 *    quên mật khẩu/OTP có limiter riêng trong PasswordResetController. Chồng
 *    thêm trần theo IP ở đây sẽ khoá nhầm cả kho dùng chung NAT khi một người
 *    gõ sai mật khẩu nhiều lần.
 *
 * Chi phí: 4 lượt Redis cho mỗi request được tính — 1 đọc của tooManyAttempts,
 * 3 của hit() (add timer, add counter, incr). Đây là chi phí của chính
 * Illuminate\Cache\RateLimiter, cơ chế mà AuthController::login đã dùng từ
 * WWHN-289; không thêm truy vấn DB nào. Lượt đọc thứ 5 (remaining()) đã được bỏ
 * bằng cách tính từ giá trị trả về của hit().
 */
class ApiRateLimit
{
    /**
     * Đoạn cuối đường dẫn nhận diện nhóm kết xuất/tải tệp.
     *
     * So khớp theo TIỀN TỐ của đoạn cuối chứ không dùng danh sách glob cố định.
     * Đường dẫn thật trong hệ thống có dạng
     * "/reports/inventory-balance/export-excel", "/reports/export/{id}/download",
     * "/skus/import-reports/{id}/error-file". Bản glob đầu tiên chỉ khớp đoạn
     * cuối đúng bằng "export" nên bỏ sót trọn nhóm "export-excel" — 11 endpoint
     * báo cáo nặng nhất — do đó phân loại theo đoạn cuối là an toàn hơn.
     */
    private const EXPORT_SEGMENT_PREFIXES = ['export', 'download', 'error-file', 'import-template'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')
            || $this->hasExplicitThrottle($request)
            || ApiAuthContext::isPublicEndpoint($request)) {
            return $next($request);
        }

        // Resolve ĐÚNG MỘT LẦN rồi truyền xuống: cả việc chọn nhóm lẫn việc dựng
        // khoá đều cần biết user, và đây là thao tác đắt nhất của middleware này.
        $user = ApiAuthContext::resolveUser();

        [$name, $maxAttempts] = $this->resolveTier($request, $user);

        if ($maxAttempts <= 0) {
            return $next($request);
        }

        $key = $this->throttleKey($request, $name, $user);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return RateLimitResponse::make($request, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ], 'Bạn thao tác quá nhanh. Vui lòng thử lại sau '.$retryAfter.' giây.');
        }

        // hit() TRẢ VỀ số lần đã dùng — tính X-RateLimit-Remaining từ giá trị này
        // thay vì gọi thêm RateLimiter::remaining(), vốn là một lượt đọc Redis nữa
        // trên đường tới hạn của MỌI request.
        $hits = (int) RateLimiter::hit($key, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $hits));

        return $response;
    }

    /**
     * @return array{0: string, 1: int} [tên nhóm, trần mỗi phút]. Trần <= 0 = tắt.
     */
    private function resolveTier(Request $request, ?User $user): array
    {
        // Nhóm PV ẩn danh nằm ngoài PUBLIC_PATH_PATTERNS nhưng đã có
        // `throttle:pv-public` ở route — hasExplicitThrottle() đã bắt trước đó.
        // Tới đây chỉ còn các endpoint yêu cầu đăng nhập.
        if ($user === null) {
            return ['unauthenticated', (int) config('rate_limit.api_unauthenticated_per_minute', 60)];
        }

        if ($this->isExportPath($request)) {
            return ['export', (int) config('rate_limit.api_export_per_minute', 30)];
        }

        if ($request->isMethodCacheable()) {
            return ['read', (int) config('rate_limit.api_read_per_minute', 600)];
        }

        return ['write', (int) config('rate_limit.api_write_per_minute', 240)];
    }

    /**
     * Đoạn cuối đường dẫn có bắt đầu bằng một trong EXPORT_SEGMENT_PREFIXES không.
     */
    private function isExportPath(Request $request): bool
    {
        $segments = $request->segments();
        $last = end($segments);

        if (! is_string($last) || $last === '') {
            return false;
        }

        foreach (self::EXPORT_SEGMENT_PREFIXES as $prefix) {
            if (str_starts_with($last, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Route tự khai `throttle:` thì để nguyên limiter của nó làm nguồn sự thật.
     */
    private function hasExplicitThrottle(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Đã đăng nhập: khoá theo user_id — cả kho đi chung một IP NAT nên khoá theo
     * IP sẽ để một người quét nhanh làm 429 cả ca trực. Chưa đăng nhập: khoá
     * theo IP vì chưa có định danh nào khác.
     */
    private function throttleKey(Request $request, string $tier, ?User $user): string
    {
        $subject = $user !== null
            ? 'u:'.$user->user_id
            : 'ip:'.$request->ip();

        return 'api-rl:'.$tier.':'.sha1($subject);
    }
}
