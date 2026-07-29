<?php

return [
    // Mặc định 20160 phút = 14 ngày.
    'ttl_minutes' => (int) env('REFRESH_TOKEN_TTL_MINUTES', 20160),
];
