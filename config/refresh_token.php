<?php

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
        'secure' => filter_var(env('REFRESH_TOKEN_COOKIE_SECURE', false), FILTER_VALIDATE_BOOL),
        'same_site' => strtolower((string) env('REFRESH_TOKEN_COOKIE_SAME_SITE', 'lax')),
    ],
];
