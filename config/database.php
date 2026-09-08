<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
|--------------------------------------------------------------------------
| WWHN-SEC-05 — chính sách TLS cho kết nối PostgreSQL
|--------------------------------------------------------------------------
|
| Trước đây giá trị duy nhất là `env('DB_SSLMODE', 'prefer')`. `prefer` là chế
| độ nguy hiểm nhất của libpq vì nó THÀNH CÔNG cả khi không có TLS: client thử
| bắt tay TLS, server từ chối, libpq âm thầm chạy tiếp bằng kết nối rõ. Không
| exception, không log, không dấu hiệu nào — mật khẩu ứng dụng, toàn bộ PII của
| phiếu bảo hành và mọi câu lệnh đi qua mạng ở dạng plaintext. Đo trên runtime
| thật 08/09/2026: config('database.connections.pgsql.sslmode') === 'prefer'.
|
| Mặc định MỚI phụ thuộc môi trường thay vì là một hằng số:
|   local/testing  -> 'prefer'   (stack docker dev không dựng CA; giữ nguyên
|                                 hành vi cũ để không ai phải cấu hình thêm)
|   mọi nơi khác   -> 'require'  (fail-closed: không TLS thì KHÔNG kết nối)
|
| APP_ENV gõ sai rơi vào nhánh 'mọi nơi khác' — cố ý, cùng nguyên tắc
| fail-closed với config/golive.php.
|
| 'require' mã hoá đường truyền nhưng KHÔNG xác thực danh tính server nên vẫn hở
| với MITM chủ động. Đích đến là 'verify-full', cần DB_SSLROOTCERT trỏ tới CA của
| cụm DB. CA đó do DevOps sở hữu và không nằm trong repo, nên giá trị được đặt
| qua env; `wms:golive-check` (security.db_transport) nói rõ môi trường nào chưa
| đạt verify-full và thiếu gì, thay vì im lặng chấp nhận.
|
| Bốn khoá ssl* dưới đây được Laravel ghép thẳng vào DSN
| (Illuminate\Database\Connectors\PostgresConnector::addSslOptions) và chỉ được
| ghép khi isset() — vì vậy null = không xuất hiện trong DSN.
*/
$appEnvForDbTls = strtolower(trim((string) env('APP_ENV', 'production')));
$dbTlsIsDevelopment = in_array($appEnvForDbTls, ['local', 'testing'], true);
$dbSslMode = strtolower(trim((string) env('DB_SSLMODE', $dbTlsIsDevelopment ? 'prefer' : 'require')));
$dbSslMode = $dbSslMode !== '' ? $dbSslMode : 'require';

/*
|--------------------------------------------------------------------------
| WWHN-SEC-05 — TLS cho Valkey/Redis
|--------------------------------------------------------------------------
|
| Redis/Valkey của hệ thống KHÔNG chỉ giữ cache vô hại: SESSION_DRIVER=redis,
| QUEUE_CONNECTION=redis, toàn bộ bộ đếm rate-limit, và lớp cache đọc
| (CachesReadPayload, 55/84 endpoint) — trong đó có payload báo cáo bảo hành
| mang customer_name/phone/address. Trước thay đổi này config không có khoá
| `scheme` nào, nên phpredis luôn nối bằng TCP trần: ai bắt được gói tin trong
| mạng nội bộ đọc được cả phiên đăng nhập lẫn PII.
|
| Mặc định `tcp` — stack dev và mọi môi trường đang chạy giữ nguyên hành vi cũ,
| không ai phải dựng CA để `docker compose up` chạy được. Production đặt
| REDIS_SCHEME=tls (Laravel ghép tiền tố `tls://` vào host trong
| PhpRedisConnector::formatHost) và trỏ REDIS_SSL_CAFILE tới CA của cụm.
|
| REDIS_SSL_VERIFY_PEER mặc định BẬT: tắt xác thực chứng chỉ biến TLS thành lớp
| mã hoá không danh tính, vẫn MITM được. Chỉ tắt khi đang chẩn đoán sự cố.
|
| `context` PHẢI là null khi không chạy TLS. Đã đo 08/09/2026: truyền mảng RỖNG
| vẫn khiến PhpRedisConnector::establishConnection() đẩy tham số stream-context
| thứ 7 xuống Redis::connect(), và phpredis khi đó cố bật TLS -> "Failed to
| enable crypto" / "Redis server went away". Chỉ null mới thật sự là no-op.
*/
$redisScheme = strtolower(trim((string) env('REDIS_SCHEME', 'tcp')));
$redisScheme = $redisScheme !== '' ? $redisScheme : 'tcp';
$redisTlsContext = null;

// Chỉ truyền khoá "scheme" xuống phpredis khi THẬT SỰ bật TLS. Với giá trị
// 'tcp', PhpRedisConnector::formatHost() sẽ ghép tiền tố 'tcp://' vào host —
// hợp lệ nhưng là một thay đổi hành vi không cần thiết trên mọi môi trường đang
// chạy. null => isset() trả false => Laravel giữ nguyên host như trước.
$redisSchemeForConnection = null;

if ($redisScheme === 'tls' || $redisScheme === 'ssl') {
    $redisSslOptions = [
        'verify_peer' => filter_var(env('REDIS_SSL_VERIFY_PEER', true), FILTER_VALIDATE_BOOL),
        'verify_peer_name' => filter_var(env('REDIS_SSL_VERIFY_PEER_NAME', true), FILTER_VALIDATE_BOOL),
    ];

    if (($redisCaFile = trim((string) env('REDIS_SSL_CAFILE', ''))) !== '') {
        $redisSslOptions['cafile'] = $redisCaFile;
    }

    if (($redisSslCert = trim((string) env('REDIS_SSL_LOCAL_CERT', ''))) !== '') {
        $redisSslOptions['local_cert'] = $redisSslCert;
    }

    if (($redisSslKey = trim((string) env('REDIS_SSL_LOCAL_PK', ''))) !== '') {
        $redisSslOptions['local_pk'] = $redisSslKey;
    }

    $redisTlsContext = ['ssl' => $redisSslOptions];
    $redisSchemeForConnection = $redisScheme;
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            // WWHN-SEC-05 — xem khối chính sách TLS ở đầu file.
            'sslmode' => $dbSslMode,
            // Chỉ có ý nghĩa với verify-ca / verify-full. Để trống thì khoá không
            // xuất hiện trong DSN và libpq giữ nguyên hành vi mặc định.
            'sslrootcert' => ($v = trim((string) env('DB_SSLROOTCERT', ''))) !== '' ? $v : null,
            'sslcert' => ($v = trim((string) env('DB_SSLCERT', ''))) !== '' ? $v : null,
            'sslkey' => ($v = trim((string) env('DB_SSLKEY', ''))) !== '' ? $v : null,
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            // WWHN-SEC-05 — xem khối "TLS cho Valkey/Redis" ở đầu file.
            'scheme' => $redisSchemeForConnection,
            'context' => $redisTlsContext,
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            // Chặn trên cho việc nối và đọc Valkey. Mặc định 0 = vô hạn (đúng như
            // trước), nên stack dev không đổi hành vi; hồ sơ chịu tải đặt giá trị
            // thật. VÌ SAO CẦN: khi Valkey treo (không phải chết), phpredis chờ vô
            // hạn — worker php-fpm bị ghim, lớp fail-open xuống database KHÔNG BAO
            // GIỜ chạy, và trần 48 worker/replica cạn trong vài giây. Hàng đợi dùng
            // 'block_for' => null (config/queue.php:92) nên read_timeout không cắt
            // nhầm BLPOP.
            'timeout' => (float) env('REDIS_TIMEOUT', 0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 0),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            // WWHN-SEC-05 — xem khối "TLS cho Valkey/Redis" ở đầu file.
            'scheme' => $redisSchemeForConnection,
            'context' => $redisTlsContext,
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            // Chặn trên cho việc nối và đọc Valkey. Mặc định 0 = vô hạn (đúng như
            // trước), nên stack dev không đổi hành vi; hồ sơ chịu tải đặt giá trị
            // thật. VÌ SAO CẦN: khi Valkey treo (không phải chết), phpredis chờ vô
            // hạn — worker php-fpm bị ghim, lớp fail-open xuống database KHÔNG BAO
            // GIỜ chạy, và trần 48 worker/replica cạn trong vài giây. Hàng đợi dùng
            // 'block_for' => null (config/queue.php:92) nên read_timeout không cắt
            // nhầm BLPOP.
            'timeout' => (float) env('REDIS_TIMEOUT', 0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 0),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
