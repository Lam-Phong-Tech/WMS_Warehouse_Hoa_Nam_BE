<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit write policy
    |--------------------------------------------------------------------------
    |
    | outbox_required: ghi audit chính; nếu lỗi phải ghi được audit_outbox.
    | fail_closed: ghi audit chính lỗi thì request thất bại, kể cả đã vào outbox.
    | best_effort: luôn cảnh báo/đẩy outbox nhưng không làm hỏng nghiệp vụ.
    |
    | WWHN-172 mutation routes ép policy fail_closed theo request trong
    | Wwhn172AggregateLock và restore policy ở finally để không làm thay đổi
    | hành vi của use case ngoài phạm vi.
    |
    */
    'write_policy' => env('AUDIT_WRITE_POLICY', 'outbox_required'),

    'reconcile' => [
        'batch_size' => (int) env('AUDIT_RECONCILE_BATCH_SIZE', 200),
        'max_backoff_minutes' => (int) env('AUDIT_RECONCILE_MAX_BACKOFF_MINUTES', 60),
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

        // WWHN-222 (DEV222-P1-04): danh sách secret kỹ thuật ở trên không che
        // PII cá nhân/khách hàng. Chỉ thêm key cụ thể đã xác nhận là PII thật
        // trong schema (app_user.email/phone, defect_report.customer_*,
        // stock_documents.recipient_*) — KHÔNG thêm key chung chung như
        // "address"/"name" vì warehouses.address, product name... không phải
        // PII và cần giữ nguyên trong audit để còn truy vết nghiệp vụ.
        'email',
        'phone',
        'contact_phone',
        'customer_name',
        'customer_phone',
        'customer_address',
        'recipient_name',
        'recipient_contact_phone',
        'recipient_address',
    ],
];
