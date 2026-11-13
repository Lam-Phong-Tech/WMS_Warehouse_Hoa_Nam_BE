<?php

return [
    'enabled' => filter_var(env('NOTIFICATION_DELIVERY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'coalesce_seconds' => (int) env('NOTIFICATION_COALESCE_SECONDS', 30),
    // History is never rate limited. Only admission to optional push delivery is paced.
    'max_push_per_user_per_minute' => env('NOTIFICATION_MAX_PUSH_PER_USER_PER_MINUTE', 60),
    'drain_time_budget_seconds' => (int) env('NOTIFICATION_DELIVERY_DRAIN_BUDGET_SECONDS', 20),
    'monitor_delivery_threshold' => (int) env('NOTIFICATION_MONITOR_DELIVERY_THRESHOLD', 100),
    'monitor_overdue_seconds' => (int) env('NOTIFICATION_MONITOR_OVERDUE_SECONDS', 300),
    'monitor_queue_threshold' => (int) env('NOTIFICATION_MONITOR_QUEUE_THRESHOLD', 100),
    'monitor_failed_threshold' => (int) env('NOTIFICATION_MONITOR_FAILED_THRESHOLD', 0),
    'tier' => env('NOTIFICATION_DELIVERY_TIER', 'unknown'),
    'project_id' => env('FCM_PROJECT_ID', 'hoa-nam-scanner-devtest'),
    'credentials_file' => env('FCM_CREDENTIALS_FILE'),
    'web_config' => json_decode((string) env('FCM_WEB_CONFIG', '{}'), true) ?: [],
    'vapid_public_key' => env('FCM_VAPID_PUBLIC_KEY'),
    'allowed_web_origins' => ['https://appqr.lptech.info.vn'],
    'android_package' => 'vn.info.lptech.wmshoanam',
    'binding_days' => 30,
    'message_ttl_seconds' => 3600,
    'self_test_enabled' => filter_var(env('NOTIFICATION_DELIVERY_SELF_TEST_ENABLED', false), FILTER_VALIDATE_BOOL),
    'canary_file' => env('NOTIFICATION_DELIVERY_CANARY_FILE'),
];
