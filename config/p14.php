<?php

return [
    // Candidate integration is OFF until BA, release and authenticated smoke gates pass.
    'recovery_enabled' => (bool) env('P14_RECOVERY_ENABLED', false),
    'shift_enabled' => (bool) env('P14_SHIFT_ENABLED', false),
    // Stops new STARTs while keeping tracked-session fencing and closure available.
    // Do not disable both flags with tracked ACTIVE/UNKNOWN work outstanding.
    'shift_drain_enabled' => (bool) env('P14_SHIFT_DRAIN_ENABLED', false),
    'support_email' => env('P14_RECOVERY_SUPPORT_EMAIL'),
    'recovery_retention_hours' => 24,
    'recovery_ip_per_hour' => 10,
    'recovery_username_per_hour' => 3,
    'recovery_read_per_minute' => 30,
    'close_check_seconds' => 300,
    'snapshot_max_drafts' => 1000,
    'snapshot_max_bytes' => 4 * 1024 * 1024,
];
