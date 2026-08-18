<?php

/**
 * WWHN-289 (DEV289-P1-04) — anti-abuse / rate limit contract.
 *
 * SRS §8.1 (bảng Security) yêu cầu nhóm endpoint PV công khai
 * (`GET /pv/products`, `/pv/products/{sku_id}`, `/pv/industries`,
 * `/pv/recommendations`) phải có "read-only DTO/rate-limit/cache/anti-abuse
 * controls"; Annex C khai error set của đúng 4 operation này là 400,404,429,500.
 * Trước WWHN-289 hệ thống không có bất kỳ rate limiter nào nên 429 là mã lỗi
 * được công bố nhưng không thể xảy ra.
 *
 * Ngưỡng để trong config (không hardcode) để DevOps chỉnh theo môi trường mà
 * không cần đổi code; giá trị 0 = tắt guard (chỉ dùng cho môi trường đo tải).
 */
return [
    // Endpoint PV ẩn danh: giới hạn theo IP.
    'pv_public_per_minute' => (int) env('RATE_LIMIT_PV_PUBLIC_PER_MINUTE', 120),

    // Đăng nhập: chỉ đếm lần THẤT BẠI, khoá theo (IP + định danh đăng nhập)
    // nên một IP dùng chung (NAT kho) không tự khoá lẫn nhau.
    'auth_login_per_minute' => (int) env('RATE_LIMIT_AUTH_LOGIN_PER_MINUTE', 10),

    // Refresh token: chống quay vòng token bị đánh cắp.
    'auth_refresh_per_minute' => (int) env('RATE_LIMIT_AUTH_REFRESH_PER_MINUTE', 30),
];
