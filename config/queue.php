<?php

/**
 * WWHN-295 — Bất biến runtime của queue: retry_after PHẢI lớn hơn timeout của
 * worker. Nếu bằng nhau (mặc định cũ: worker `--timeout=90` trong
 * docker-compose.yml đối với retry_after 90), một job chạy sát ngưỡng sẽ được
 * queue release lại đúng lúc worker vẫn đang xử lý -> worker thứ hai nhận LẠI
 * chính job đó. Với audit outbox và reconcile tồn kho, chạy hai lần nghĩa là
 * bản ghi audit trùng hoặc số liệu đối soát bị cộng dồn.
 *
 * Vì vậy retry_after được SUY RA từ worker timeout thay vì khai độc lập; hai
 * giá trị không thể lệch nhau do sửa một chỗ mà quên chỗ kia.
 * `wms:golive-check` (core.queue_retry_window) chặn cutover nếu bất biến này vỡ.
 */
$workerTimeout = max(1, (int) env('QUEUE_WORKER_TIMEOUT', 90));
$retryAfter = max($workerTimeout + 30, (int) env('QUEUE_RETRY_AFTER', $workerTimeout + 30));

return [

    // Queue name for notification listeners. Keep "default" until a deployed
    // worker consumes the dedicated queue; switching without that consumer
    // would silently stop realtime notifications.
    'notification_queue' => env('NOTIFICATION_QUEUE', 'default'),
    'notification_delivery_queue' => env('NOTIFICATION_DELIVERY_QUEUE', 'notification-delivery'),

    // Timeout mà `php artisan queue:work --timeout=` phải dùng (docker-compose.yml
    // đọc cùng biến QUEUE_WORKER_TIMEOUT) — dùng cho golive-check, không phải key
    // của Laravel.
    'worker_timeout' => $workerTimeout,

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', $retryAfter),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', $retryAfter),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', $retryAfter),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
