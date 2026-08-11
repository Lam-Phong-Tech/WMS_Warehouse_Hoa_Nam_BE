<?php

$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));
$secureByDefault = ! in_array($appEnv, ['local', 'testing'], true);

return [
    // Mặc định 20160 phút = 14 ngày.
    'ttl_minutes' => (int) env('REFRESH_TOKEN_TTL_MINUTES', 20160),

    // Browser persistence: access token vẫn memory-only; chỉ refresh token được
    // lưu trong HttpOnly cookie. JSON refresh_token vẫn được giữ để tương thích
    // Swagger/Postman/non-browser client.
    'cookie' => [
        'name' => env('REFRESH_TOKEN_COOKIE_NAME', 'wms_refresh_token'),
        'path' => env('REFRESH_TOKEN_COOKIE_PATH', '/api/v1/auth'),
        'domain' => env('REFRESH_TOKEN_COOKIE_DOMAIN') ?: null,
        // Local/testing HTTP mặc định false; dev/UAT/prod mặc định true để tránh
        // vô tình phát long-lived refresh credential qua cookie không Secure.
        'secure' => filter_var(
            env('REFRESH_TOKEN_COOKIE_SECURE', $secureByDefault),
            FILTER_VALIDATE_BOOL
        ),
        'same_site' => strtolower((string) env('REFRESH_TOKEN_COOKIE_SAME_SITE', 'lax')),
    ],
];
