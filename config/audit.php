<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit write policy
    |--------------------------------------------------------------------------
    |
    | outbox_required: ghi audit chính; nếu lỗi phải ghi được audit_outbox.
    | fail_closed: ghi audit chính lỗi thì request thất bại, kể cả đã vào outbox.
    | best_effort: cảnh báo và cố ghi outbox; nếu cả hai nơi ghi đều lỗi thì
    |               nghiệp vụ vẫn tiếp tục. Chỉ bật khi có Tech Lead disposition.
    |
    | Giá trị ngoài ba policy trên bị fail-safe bởi AuditLogger, không được phép
    | âm thầm rơi về best_effort.
    |
    | WWHN-172 mutation routes ép policy fail_closed theo request trong
    | Wwhn172AggregateLock và restore policy ở finally để không làm thay đổi
    | hành vi của use case ngoài phạm vi.
    |
    */
    'write_policy' => env('AUDIT_WRITE_POLICY', 'outbox_required'),

    'reconcile' => [
        'enabled' => filter_var(env('AUDIT_RECONCILE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'batch_size' => (int) env('AUDIT_RECONCILE_BATCH_SIZE', 200),
        'max_backoff_minutes' => (int) env('AUDIT_RECONCILE_MAX_BACKOFF_MINUTES', 60),
        // Default: retry audit outbox every 5 minutes. Production may override
        // with an approved cron expression without changing application code.
        'schedule' => env('AUDIT_RECONCILE_CRON', '*/5 * * * *'),
        'overlap_lock_minutes' => (int) env('AUDIT_RECONCILE_LOCK_MINUTES', 10),
    ],

    'redacted_value' => '[REDACTED]',

    'sensitive_keys' => [
        'password',
        'password_hash',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'session',
        'secret',
        'api_key',
        'private_key',
    ],
];
