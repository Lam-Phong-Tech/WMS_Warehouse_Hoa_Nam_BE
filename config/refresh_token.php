<?php

$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));
$isLocalLike = in_array($appEnv, ['local', 'testing'], true);
$sameSite = strtolower((string) env('REFRESH_TOKEN_COOKIE_SAME_SITE', 'lax'));
$requestedSecure = filter_var(env('REFRESH_TOKEN_COOKIE_SECURE', false), FILTER_VALIDATE_BOOL);

// Production-like environments must never downgrade the long-lived browser
// credential to a non-Secure cookie. SameSite=None also requires Secure in modern
// browsers, including when explicitly used during local HTTPS testing.
$secureCookie = ! $isLocalLike || $sameSite === 'none' || $requestedSecure;

return [
    // Mặc định 20160 phút = 14 ngày.
    'ttl_minutes' => (int) env('REFRESH_TOKEN_TTL_MINUTES', 20160),

    // WWHN-SEC-09: cửa sổ giữ lại token đã hết hạn/bị thu hồi để truy vết sự cố
    // (audit TOKEN_REFRESH_FAILED trỏ fingerprint về hàng này) trước khi
    // `auth:prune-refresh-tokens` xoá hẳn khỏi DB.
    'prune_after_days' => (int) env('REFRESH_TOKEN_PRUNE_AFTER_DAYS', 30),

    // Browser persistence: access token vẫn memory-only; refresh token được mirror
    // vào HttpOnly cookie. JSON refresh_token vẫn được giữ để tương thích
    // Swagger/Postman/non-browser client.
    'cookie' => [
        'name' => env('REFRESH_TOKEN_COOKIE_NAME', 'wms_refresh_token'),
        'path' => env('REFRESH_TOKEN_COOKIE_PATH', '/api/v1/auth'),
        'domain' => env('REFRESH_TOKEN_COOKIE_DOMAIN') ?: null,
        'secure' => $secureCookie,
        'same_site' => $sameSite,
    ],
];
