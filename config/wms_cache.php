<?php

/**
 * Centralized TTL/behaviour policy cho application cache (Valkey/Redis).
 * Không đọc rải rác env TTL trong controller/service — luôn qua config này
 * (App\Support\Cache\CacheTtl) để một chỗ audit/điều chỉnh được toàn bộ.
 */
return [

    // Đơn vị giây. Đây là guideline mặc định — có thể override qua .env theo môi trường.
    'ttl' => [
        'rbac' => (int) env('CACHE_TTL_RBAC', 300),
        'master' => (int) env('CACHE_TTL_MASTER', 1800),
        'reference' => (int) env('CACHE_TTL_REFERENCE', 3600),
        'sku' => (int) env('CACHE_TTL_SKU', 900),
        'search' => (int) env('CACHE_TTL_SEARCH', 120),
        'dashboard' => (int) env('CACHE_TTL_DASHBOARD', 60),
        'report' => (int) env('CACHE_TTL_REPORT', 300),
        'stock_short' => (int) env('CACHE_TTL_STOCK_SHORT', 20),
    ],

    // +/- % jitter quanh TTL để tránh nhiều key hết hạn cùng lúc (thundering herd on expiry).
    'jitter_percent' => (int) env('CACHE_TTL_JITTER_PERCENT', 10),

    // Cache::lock() cho stampede protection trên các key "hot" (dashboard, report, master list).
    'lock' => [
        'wait_seconds' => (int) env('CACHE_LOCK_WAIT_SECONDS', 5),
        'ttl_seconds' => (int) env('CACHE_LOCK_TTL_SECONDS', 10),
    ],

    // true: nếu Valkey lỗi/không kết nối được, application cache tự động fallback
    // thẳng xuống resolver (DB) thay vì làm hỏng response. Không áp dụng cho session/queue.
    'fail_open' => (bool) env('CACHE_FAIL_OPEN', true),
];
