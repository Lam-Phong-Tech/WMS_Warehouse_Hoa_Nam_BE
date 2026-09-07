<?php

$rawAllowedOrigins = trim((string) env('CORS_ALLOWED_ORIGINS', ''));
$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));

// Existing developer .env files may already contain CORS_ALLOWED_ORIGINS= blank.
// Local/testing must remain usable after pull without weakening deployed environments.
if ($rawAllowedOrigins === '' && in_array($appEnv, ['local', 'testing'], true)) {
    $rawAllowedOrigins = 'http://localhost:5173,http://127.0.0.1:5173';
}

$allowedOrigins = array_values(array_unique(array_filter(
    array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', $rawAllowedOrigins)
    ),
    static fn (string $origin): bool => $origin !== ''
)));

return [
    // All HTTP APIs, including auth preflight requests, are handled by Laravel CORS.
    'paths' => ['api/*'],

    // Origin is the security boundary. Keeping methods/headers open prevents future
    // WMS contract additions (Authorization, Idempotency-Key, If-Match, etc.) from
    // silently breaking browser preflight while still rejecting unknown origins.
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],

    // FE/QA need request correlation and optimistic-concurrency metadata.
    // Content-Disposition: BR-EXP-03 bắt tên file export phải có timestamp do BE
    // đặt — trình duyệt chỉ đọc được header này khi nó được expose qua CORS.
    // Idempotent-Replay: AC-GWT-IDEMP-001, cho phép FE phân biệt lần chạy thật và replay.
    'exposed_headers' => ['X-Request-ID', 'ETag', 'Content-Disposition', 'Idempotent-Replay'],

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
    // bounded by the exact CORS_ALLOWED_ORIGINS allow-list above; wildcard origins
    // are intentionally not used.
    'supports_credentials' => true,
];
