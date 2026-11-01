<?php

$rawAllowedOrigins = trim((string) env('CORS_ALLOWED_ORIGINS', ''));
$rawAllowedOriginPatterns = trim((string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''));

// Canonical FE origin của WMS Hoa Nam. Giữ ở config để một release DEV/TEST mới
// không thể vô tình làm hỏng đăng nhập chỉ vì .env/CORS allow-list bị thiếu hoặc
// config cache còn profile cũ. WMS_BROWSER_ORIGINS chỉ BỔ SUNG origin chính thức,
// không được phép xoá canonical origin này bằng cách để env rỗng.
$canonicalBrowserOrigins = [
    'https://khohoanamfe.lptech.info.vn',
];
$rawBrowserOrigins = trim((string) env('WMS_BROWSER_ORIGINS', ''));
$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));
$appUrlHost = strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST));
$isLoopbackApp = in_array($appUrlHost, ['localhost', '127.0.0.1', '::1'], true);
$isLocalLike = in_array($appEnv, ['local', 'testing', 'test', 'dev', 'development'], true)
    || $isLoopbackApp;

// Local/DEV phải chạy được ngay cả khi .env cũ để CORS_ALLOWED_ORIGINS= rỗng.
// Giữ exact 5173 cho đường lỗi nginx và thêm regex loopback mọi port vì Vite tự
// nhảy 5174/5175 khi 5173 đang bận. Không áp dụng fallback này cho UAT/PROD.
if ($rawAllowedOrigins === '' && $isLocalLike) {
    $rawAllowedOrigins = 'http://localhost:5173,http://127.0.0.1:5173';
}

if ($rawAllowedOriginPatterns === '' && $isLocalLike) {
    $rawAllowedOriginPatterns = '#^https?://(?:localhost|127\\.0\\.0\\.1|\\[::1\\])(?::\\d+)?$#i';
}

$allowedOrigins = array_values(array_unique(array_filter(
    array_map(
        static fn (string $origin): string => strtolower(rtrim(trim($origin), '/')),
        array_merge(
            explode(',', $rawAllowedOrigins),
            $canonicalBrowserOrigins,
            explode(',', $rawBrowserOrigins),
        )
    ),
    static fn (string $origin): bool => $origin !== ''
)));

$allowedOriginPatterns = array_values(array_unique(array_filter(
    array_map(
        static fn (string $pattern): string => trim($pattern),
        explode(',', $rawAllowedOriginPatterns)
    ),
    static fn (string $pattern): bool => $pattern !== ''
)));

return [
    // All HTTP APIs, including auth preflight requests, are handled by Laravel CORS.
    'paths' => ['api/*'],

    // Origin is the security boundary. Keeping methods/headers open prevents future
    // WMS contract additions (Authorization, Idempotency-Key, If-Match, etc.) from
    // silently breaking browser preflight while still rejecting unknown origins.
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => $allowedOriginPatterns,
    'allowed_headers' => ['*'],

    // FE/QA need request correlation and optimistic-concurrency metadata.
    // Content-Disposition: BR-EXP-03 bắt tên file export phải có timestamp do BE
    // đặt — trình duyệt chỉ đọc được header này khi nó được expose qua CORS.
    // Idempotent-Replay: AC-GWT-IDEMP-001, cho phép FE phân biệt lần chạy thật và replay.
    // Retry-After và X-RateLimit-*: cho FE khác origin đọc được thời gian chờ và hạn mức 429.
    'exposed_headers' => ['X-Request-ID', 'ETag', 'Content-Disposition', 'Idempotent-Replay',
        'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining',
        'Vary', 'X-Content-Type-Options', 'X-Read-Fields-Contract', 'X-WMS-Encrypted'],

    /*
     * Thời gian trình duyệt được nhớ kết quả preflight, tính bằng GIÂY.
     *
     * Mỗi ĐƯỜNG DẪN API mới trong một phiên duyệt tốn MỘT request OPTIONS đi trọn
     * vòng Laravel (đo 07/09/2026: 140–430 ms tuỳ tải máy). Một lần F5 màn Tổng
     * quan chạm 7 đường dẫn khác nhau, nên đây là chi phí thật nằm trên đường
     * tới hạn chứ không phải chi tiết lý thuyết.
     *
     * 86400 là trần của Firefox; Chrome tự cắt xuống 7200 giây (2 giờ) — vẫn gấp
     * đôi giá trị 3600 cũ, tức số lần preflight trong một ca làm việc giảm một
     * nửa. Đặt cao không có hại: chính sách CORS chỉ đổi khi deploy, và bộ nhớ
     * preflight nằm trong từng trình duyệt.
     *
     * CÁCH TRIỆT ĐỂ (khuyến nghị cho môi trường thật): phục vụ FE và API CÙNG
     * origin — nginx của FE proxy `/api` sang backend. Cùng origin thì trình
     * duyệt không phát preflight nào cả. Mẫu cấu hình: repo FE,
     * `deploy/nginx/frontend.conf.example`.
     */
    'max_age' => 86400,

    // Auth persistence depends on an HttpOnly refresh cookie. This is therefore a
    // protocol invariant rather than a deployment feature flag. Security remains
    // bounded by exact CORS_ALLOWED_ORIGINS and explicit regex patterns above;
    // wildcard "*" origins are intentionally not used.
    'supports_credentials' => true,
];
