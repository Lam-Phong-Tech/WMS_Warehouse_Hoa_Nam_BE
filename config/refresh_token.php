<?php

$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));
$isLocalLike = in_array($appEnv, ['local', 'testing'], true);
$sameSite = strtolower((string) env('REFRESH_TOKEN_COOKIE_SAME_SITE', 'lax'));
$requestedSecure = filter_var(env('REFRESH_TOKEN_COOKIE_SECURE', false), FILTER_VALIDATE_BOOL);

// Production-like environments must never downgrade the long-lived browser
// credential to a non-Secure cookie. SameSite=None also requires Secure in modern
// browsers, including when explicitly used during local HTTPS testing.
$secureCookie = ! $isLocalLike || $sameSite === 'none' || $requestedSecure;

$bodyTransportOrigins = array_values(array_filter(
    array_map(
        static fn (string $origin): string => strtolower(rtrim(trim($origin), '/')),
        explode(',', (string) env('REFRESH_TOKEN_BODY_TRANSPORT_ORIGINS', ''))
    ),
    static fn (string $origin): bool => $origin !== ''
));

return [
    // Mặc định 20160 phút = 14 ngày.
    'ttl_minutes' => (int) env('REFRESH_TOKEN_TTL_MINUTES', 20160),

    // WWHN-SEC-09: cửa sổ giữ lại token đã hết hạn/bị thu hồi để truy vết sự cố
    // (audit TOKEN_REFRESH_FAILED trỏ fingerprint về hàng này) trước khi
    // `auth:prune-refresh-tokens` xoá hẳn khỏi DB.
    'prune_after_days' => (int) env('REFRESH_TOKEN_PRUNE_AFTER_DAYS', 30),

    // Trình duyệt: access token memory-only; refresh token CHỈ nằm trong cookie HttpOnly
    // này. P1 bảo mật 10/09/2026 — JSON của login/refresh/MFA verify không còn chứa
    // refresh token (Modules\Auth\Support\RefreshTokenTransport).
    'cookie' => [
        'name' => env('REFRESH_TOKEN_COOKIE_NAME', 'wms_refresh_token'),
        'path' => env('REFRESH_TOKEN_COOKIE_PATH', '/api/v1/auth'),
        'domain' => env('REFRESH_TOKEN_COOKIE_DOMAIN') ?: null,
        'secure' => $secureCookie,
        'same_site' => $sameSite,
    ],

    // Kênh riêng cho client KHÔNG phải trình duyệt (công cụ tích hợp, ứng dụng native): gửi
    // header `X-WMS-Token-Transport: body` để nhận/gửi refresh token trong JSON. TẮT mặc
    // định. Request mang Origin chỉ được dùng kênh này khi Origin nằm trong
    // `allowed_origins` — KHÔNG đưa origin của web FE WMS vào đây, nếu không XSS trên web
    // lại đọc được refresh token.
    'body_transport' => [
        'enabled' => filter_var(env('REFRESH_TOKEN_BODY_TRANSPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'allowed_origins' => $bodyTransportOrigins,
    ],
];
