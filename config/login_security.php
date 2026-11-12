<?php

return [
    'backoff' => [
        'enabled' => (bool) env('LOGIN_BACKOFF_ENABLED', true),
        'free_attempts' => (int) env('LOGIN_BACKOFF_FREE_ATTEMPTS', 3),
        'delays' => array_values(array_filter(
            array_map('intval', explode(',', (string) env(
                'LOGIN_BACKOFF_DELAYS', '1,2,4,8,16,30,60,120,300,600,900'
            ))),
            static fn (int $delay): bool => $delay > 0,
        )),
        'cap_seconds' => (int) env('LOGIN_BACKOFF_CAP_SECONDS', 900),
        'counter_ttl_seconds' => (int) env('LOGIN_BACKOFF_COUNTER_TTL_SECONDS', 86400),
        'step_up_after_cycles' => (int) env('LOGIN_STEP_UP_AFTER_CYCLES', 5),
        'alert_after_cycles' => (int) env('LOGIN_ALERT_AFTER_CYCLES', 5),
    ],
    'step_up' => [
        'enabled' => (bool) env('LOGIN_STEP_UP_ENABLED', true),
        'token_ttl_seconds' => (int) env('LOGIN_STEP_UP_TOKEN_TTL_SECONDS', 300),
        'password_attempts' => (int) env('LOGIN_STEP_UP_PASSWORD_ATTEMPTS', 3),
        'max_code_attempts' => (int) env('LOGIN_STEP_UP_MAX_CODE_ATTEMPTS', 5),
        'otp_resend_cooldown_seconds' => (int) env('LOGIN_STEP_UP_RESEND_COOLDOWN_SECONDS', 60),
        'otp_max_per_hour' => (int) env('LOGIN_STEP_UP_OTP_MAX_PER_HOUR', 5),
    ],
    'trusted_device' => [
        'enabled' => (bool) env('TRUSTED_DEVICE_ENABLED', false),
        'cookie_name_prefix' => (string) env('TRUSTED_DEVICE_COOKIE_PREFIX', 'wms_trusted_device_'),
        'cookie_path' => (string) env('TRUSTED_DEVICE_COOKIE_PATH', '/api/v1/auth'),
        'cookie_same_site' => (string) env('TRUSTED_DEVICE_COOKIE_SAME_SITE', 'lax'),
        'cookie_secure' => (bool) env('TRUSTED_DEVICE_COOKIE_SECURE', true),
        'ttl_days' => (int) env('TRUSTED_DEVICE_TTL_DAYS', 30),
        'privileged_ttl_days' => (int) env('TRUSTED_DEVICE_PRIVILEGED_TTL_DAYS', 7),
        'max_per_user' => (int) env('TRUSTED_DEVICE_MAX_PER_USER', 10),
        'rotate_after_days' => (int) env('TRUSTED_DEVICE_ROTATE_AFTER_DAYS', 7),
        'rotation_grace_minutes' => (int) env('TRUSTED_DEVICE_ROTATION_GRACE_MINUTES', 10),
        'revoke_on_password_change' => (bool) env('TRUSTED_DEVICE_REVOKE_ON_PASSWORD_CHANGE', true),
        'enforce_transports' => ['cookie'],
    ],
    'device_otp' => [
        'ttl_minutes' => (int) env('DEVICE_OTP_TTL_MINUTES', 5),
        'max_attempts' => (int) env('DEVICE_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('DEVICE_OTP_RESEND_COOLDOWN_SECONDS', 60),
        'max_per_hour' => (int) env('DEVICE_OTP_MAX_PER_HOUR', 5),
        'challenge_ttl_seconds' => (int) env('DEVICE_OTP_CHALLENGE_TTL_SECONDS', 300),
    ],
    'pow' => [
        'enabled' => (bool) env('LOGIN_POW_ENABLED', false),
        'trigger_failures' => (int) env('LOGIN_POW_TRIGGER_FAILURES', 3),
        'base_difficulty' => (int) env('LOGIN_POW_BASE_DIFFICULTY', 16),
        'max_difficulty' => (int) env('LOGIN_POW_MAX_DIFFICULTY', 22),
        'challenge_ttl_seconds' => (int) env('LOGIN_POW_CHALLENGE_TTL_SECONDS', 120),
        'max_solve_ms' => (int) env('LOGIN_POW_MAX_SOLVE_MS', 3000),
    ],
    'password' => [
        'max_age_days' => (int) env('LOGIN_PASSWORD_MAX_AGE_DAYS', 90),
        // Số mật khẩu người dùng tự đặt gần nhất bị chặn tái sử dụng. Mật khẩu
        // do hệ thống cấp luôn bị chặn vĩnh viễn, không tính vào giới hạn này.
        'history_limit' => (int) env('LOGIN_PASSWORD_HISTORY_LIMIT', 5),
    ],
];
