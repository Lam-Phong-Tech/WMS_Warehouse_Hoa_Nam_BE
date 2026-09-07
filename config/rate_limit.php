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

    // Storefront công khai `/api/v1/public/*` — nhóm ĐỌC, khoá theo IP. Cao hơn
    // pv_public vì một lần mở app đã gọi song song /config + /home rồi mới tới
    // listing, facets và chi tiết.
    'public_api_per_minute' => (int) env('RATE_LIMIT_PUBLIC_API_PER_MINUTE', 180),

    // POST /api/v1/public/quote-requests — endpoint GHI ẩn danh duy nhất. Khoá
    // theo (IP + số điện thoại), ngưỡng thấp vì đây là bề mặt spam lead-gen.
    'public_quote_request_per_minute' => (int) env('RATE_LIMIT_PUBLIC_QUOTE_REQUEST_PER_MINUTE', 5),

    // Đăng nhập: chỉ đếm lần THẤT BẠI, khoá theo (IP + định danh đăng nhập)
    // nên một IP dùng chung (NAT kho) không tự khoá lẫn nhau.
    'auth_login_per_minute' => (int) env('RATE_LIMIT_AUTH_LOGIN_PER_MINUTE', 10),

    // Refresh token: chống quay vòng token bị đánh cắp.
    'auth_refresh_per_minute' => (int) env('RATE_LIMIT_AUTH_REFRESH_PER_MINUTE', 30),

    // Quên mật khẩu: chặn dò email/spam gửi OTP, khoá theo (IP + email).
    'password_reset_request_per_minute' => (int) env('RATE_LIMIT_PASSWORD_RESET_REQUEST_PER_MINUTE', 3),

    // Xác thực OTP: chặn brute-force mã 6 số, khoá theo (IP + email).
    'otp_verify_per_minute' => (int) env('RATE_LIMIT_OTP_VERIFY_PER_MINUTE', 10),

    /*
    |--------------------------------------------------------------------------
    | Nhóm endpoint ĐÃ ĐĂNG NHẬP (WWHN-SEC-01)
    |--------------------------------------------------------------------------
    |
    | Trước WWHN-SEC-01 chỉ nhóm ẩn danh (pv/public/auth-refresh) có limiter;
    | 321 route `auth:api` — trong đó 170 route GHI — không có bất kỳ trần nào.
    | Một tài khoản hợp lệ bị chiếm (token rò qua XSS, máy quét thất lạc) có thể
    | rút toàn bộ danh mục, báo cáo và lịch sử xuất/nhập ở tốc độ tối đa của
    | server mà không chạm ngưỡng nào.
    |
    | Ngưỡng đặt theo NHỊP THAO TÁC THẬT của kho, không theo lý thuyết: nhân
    | viên quét liên tục khoảng 1 mã/giây (60 req/phút). Trần GHI 240/phút gấp 4
    | lần nhịp quét nhanh nhất; trần ĐỌC 600/phút = 10 req/giây, đủ cho màn Tổng
    | quan bắn song song nhiều widget khi F5. Người dùng thật không chạm tới;
    | script rút dữ liệu thì chạm ngay.
    |
    | Khoá theo user_id (KHÔNG theo IP): cả kho đi chung một đường NAT nên khoá
    | theo IP sẽ để một người quét nhanh làm 429 cả ca trực.
    |
    | 0 = tắt guard (chỉ dùng cho môi trường đo tải — xem docker-compose.perf.yml).
    */

    // Nhóm ĐỌC đã đăng nhập (GET/HEAD).
    'api_read_per_minute' => (int) env('RATE_LIMIT_API_READ_PER_MINUTE', 600),

    // Nhóm GHI đã đăng nhập (POST/PUT/PATCH/DELETE): Post Receipt/Issue, bảo
    // hành, điều chỉnh tồn, IAM.
    'api_write_per_minute' => (int) env('RATE_LIMIT_API_WRITE_PER_MINUTE', 240),

    // Kết xuất/tải tệp: chi phí server cao nhất và là bề mặt rút dữ liệu hàng
    // loạt điển hình (mục 19 anti-abuse: "export hàng loạt", "mass download").
    'api_export_per_minute' => (int) env('RATE_LIMIT_API_EXPORT_PER_MINUTE', 30),

    // Request CHƯA đăng nhập trỏ vào endpoint cần đăng nhập — luôn kết thúc
    // bằng 401. Khoá theo IP để việc dò route/dò token không miễn phí.
    'api_unauthenticated_per_minute' => (int) env('RATE_LIMIT_API_UNAUTHENTICATED_PER_MINUTE', 60),
];
