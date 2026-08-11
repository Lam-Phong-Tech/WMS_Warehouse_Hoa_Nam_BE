<?php

$allowedOrigins = array_values(array_filter(
    array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:5173,http://127.0.0.1:5173'
        ))
    ),
    static fn (string $origin): bool => $origin !== ''
));

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
    'exposed_headers' => ['X-Request-ID', 'ETag'],

    'max_age' => 3600,

    // Authentication uses Bearer JWT + refresh token in JSON, not cross-site cookies.
    'supports_credentials' => false,
];
