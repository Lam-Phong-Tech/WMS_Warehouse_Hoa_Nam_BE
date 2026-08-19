<?php

declare(strict_types=1);

/**
 * WWHN-199 — DROP + CREATE lại DB candidate để bằng chứng luôn chạy trên schema
 * dựng mới, không phải trên tàn dư của lần chạy trước.
 *
 * Fail-closed: từ chối chạy nếu tên DB truyền vào trùng `DB_DATABASE` trong .env
 * (DB nghiệp vụ), hoặc trùng các tên hay dùng cho môi trường thật.
 *
 * Dùng:
 *   php scripts/wwhn199-recreate-candidate-database.php hn_wwhn199
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$target = $argv[1] ?? 'hn_wwhn199';

$config = config('database.connections.pgsql');
if (! is_array($config)) {
    fwrite(STDERR, "Khong tim thay connection pgsql trong config/database.php\n");
    exit(2);
}

/**
 * DB nghiệp vụ phải đọc từ CHÍNH FILE .env, không dùng env(): script này luôn được gọi
 * với `DB_DATABASE=<candidate>` trong biến môi trường, nên env('DB_DATABASE') sẽ trả về
 * tên DB candidate và biến hàng rào bảo vệ thành vô nghĩa.
 */
$businessDatabase = '';
$envPath = __DIR__.'/../.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*DB_DATABASE\s*=\s*(.*)$/', $line, $matches) === 1) {
            $businessDatabase = trim($matches[1], " \t\"'");
            break;
        }
    }
}

$protected = array_filter([$businessDatabase, 'postgres', 'template0', 'template1']);

if (in_array($target, $protected, true)) {
    fwrite(STDERR, "TU CHOI: \"{$target}\" la DB duoc bao ve (DB nghiep vu hoac DB he thong).\n");
    exit(2);
}

if (! str_starts_with($target, 'hn_wwhn')) {
    fwrite(STDERR, "TU CHOI: ten DB candidate phai bat dau bang \"hn_wwhn\" de tranh nham lan.\n");
    exit(2);
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']);

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $quoted = '"'.str_replace('"', '""', $target).'"';

    // WITH (FORCE) chi co tu PostgreSQL 13; fallback cho ban cu hon.
    try {
        $pdo->exec("DROP DATABASE IF EXISTS {$quoted} WITH (FORCE)");
    } catch (PDOException $exception) {
        $pdo->exec(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '.
            'WHERE datname = '.$pdo->quote($target).' AND pid <> pg_backend_pid()'
        );
        $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
    }

    $pdo->exec("CREATE DATABASE {$quoted}");

    $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
    echo "recreated={$target} server_version={$version}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Khong dung lai duoc DB candidate: '.$exception->getMessage()."\n");
    exit(1);
}
