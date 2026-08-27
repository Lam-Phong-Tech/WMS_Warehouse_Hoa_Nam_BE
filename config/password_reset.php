<?php

/**
 * Quên mật khẩu qua OTP email. Ngưỡng để trong config (không hardcode) theo
 * đúng convention của config/rate_limit.php — DevOps chỉnh theo môi trường mà
 * không cần đổi code.
 */
return [
    // OTP 6 số, hiệu lực 5 phút theo yêu cầu nghiệp vụ.
    'otp_length' => (int) env('PASSWORD_RESET_OTP_LENGTH', 6),
    'otp_ttl_minutes' => (int) env('PASSWORD_RESET_OTP_TTL_MINUTES', 5),

    // Sai quá số lần này thì OTP hiện tại coi như vô hiệu, phải bấm "Gửi lại mã".
    'max_attempts' => (int) env('PASSWORD_RESET_OTP_MAX_ATTEMPTS', 5),

    // Cửa sổ cho bước đặt mật khẩu mới sau khi OTP đã xác thực đúng.
    'reset_token_ttl_minutes' => (int) env('PASSWORD_RESET_TOKEN_TTL_MINUTES', 10),
];
