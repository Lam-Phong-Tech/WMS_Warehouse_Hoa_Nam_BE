<?php

return [
    'enabled' => filter_var(env('NOTIFICATION_DELIVERY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'tier' => env('NOTIFICATION_DELIVERY_TIER','unknown'),
    'project_id' => env('FCM_PROJECT_ID', 'hoa-nam-scanner-devtest'),
    'credentials_file' => env('FCM_CREDENTIALS_FILE'),
    'web_config' => json_decode((string) env('FCM_WEB_CONFIG', '{}'), true) ?: [],
    'vapid_public_key' => env('FCM_VAPID_PUBLIC_KEY'),
    'allowed_web_origins' => ['https://appqr.lptech.info.vn'],
    'android_package' => 'vn.info.lptech.wmshoanam',
    'binding_days' => 30,
    'message_ttl_seconds' => 3600,
    'self_test_enabled' => filter_var(env('NOTIFICATION_DELIVERY_SELF_TEST_ENABLED', false), FILTER_VALIDATE_BOOL),
];
