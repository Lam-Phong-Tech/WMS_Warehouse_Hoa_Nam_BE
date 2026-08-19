<?php

declare(strict_types=1);

/**
 * WWHN-206 / WWHN-205 — P1-02R: rebuild + reconciliation trên exact candidate.
 *
 * Register: "QA1 độc lập thực hiện rebuild/reconciliation trên exact candidate:
 * ledger=balance, duplicate_source=0, mismatch_count=0, rollback/orphan=0.
 * Không dùng Quang tự xác nhận."
 *
 * Vì vậy script này CỐ Ý không tự viết phép đối soát nào:
 *
 *  - Bước REBUILD gọi đúng lệnh sản phẩm `php artisan inventory:reconcile --rebuild`
 *    (WWHN-204 / DEV204-P1-03, InventoryProjectionRebuildService). Bằng chứng phải nói
 *    về code chạy thật của hệ thống, không phải về một script riêng của DEV.
 *  - Bước KHẲNG ĐỊNH đọc nguyên văn scripts/wwhn206-ledger-reconciliation.sql — SQL
 *    thuần mà QA1 có thể tự chạy bằng psql, không qua PHP/Laravel. Ở đây chỉ bỏ các
 *    lệnh meta của psql (dòng bắt đầu bằng "\") rồi thực thi đúng các câu SELECT đó,
 *    nên hai đường (QA1 chạy tay và CI chạy máy) không thể trôi khỏi nhau.
 *
 * Chạy:
 *   DB_CONNECTION=pgsql ... php scripts/wwhn206-ledger-reconciliation.php [evidenceDir]
 *
 * Exit 0 = mọi metric PASS; 1 = có metric FAIL; 2 = sai môi trường.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

// FAIL-CLOSED. Nếu không bọc, một Throwable ở giữa script sẽ được
// HandleExceptions của Laravel render ra console rồi tiến trình vẫn thoát 0 —
// tức là bước CI đỏ mà báo cáo xanh, đúng điều register cấm. Đã bị đúng lỗi này
// một lần: `--rebuild` không tồn tại ở candidate cũ nhưng script vẫn exit 0.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    fwrite(STDERR, 'WWHN-206 bootstrap thất bại: '.$e->getMessage()."\n");
    exit(2);
}

$evidenceDir = $argv[1] ?? (getenv('WWHN206_EVIDENCE_DIR') ?: 'docs/evidence/WWHN-205/runtime');
@mkdir($evidenceDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-206 reconciliation evidence phải chạy trên PostgreSQL (DB tham chiếu theo docs).\n");
    exit(2);
}

$sqlPath = __DIR__.'/wwhn206-ledger-reconciliation.sql';
if (! is_file($sqlPath)) {
    fwrite(STDERR, "Thiếu scripts/wwhn206-ledger-reconciliation.sql — tầng khẳng định độc lập.\n");
    exit(2);
}

$rawSql = (string) file_get_contents($sqlPath);

/** Bỏ comment/meta của psql, tách thành từng câu lệnh thực thi được qua PDO. */
$statements = array_values(array_filter(array_map(
    static fn (string $chunk): string => trim($chunk),
    preg_split(
        '/;\s*\n/',
        implode("\n", array_filter(
            preg_split('/\R/', $rawSql) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '\\')
                && ! str_starts_with(ltrim($line), '--')
        ))
    ) ?: []
), static fn (string $chunk): bool => $chunk !== ''));

if (count($statements) !== 3) {
    fwrite(STDERR, 'Kỳ vọng 3 câu SELECT trong wwhn206-ledger-reconciliation.sql (environment, metrics, verdict), thấy '.count($statements)."\n");
    exit(2);
}

[$environmentSql, $metricsSql, $verdictSql] = $statements;

$asArray = static fn (array $rows): array => array_map(static fn ($r): array => (array) $r, $rows);

$readMetrics = static function () use ($metricsSql, $verdictSql, $asArray): array {
    return [
        'metrics' => $asArray(DB::select($metricsSql)),
        'verdict' => $asArray(DB::select($verdictSql))[0] ?? [],
    ];
};

// ---------------------------------------------------------------------------
// 1. Trạng thái TRƯỚC rebuild. Nếu ở đây đã có lệch thì phần rebuild mới có ý
//    nghĩa; nếu đã sạch thì rebuild phải là phép biến đổi đồng nhất (idempotent)
//    — cả hai kết cục đều được ghi lại, không kết luận trước.
// ---------------------------------------------------------------------------
$before = $readMetrics();
$environment = $asArray(DB::select($environmentSql))[0] ?? [];

// ---------------------------------------------------------------------------
// 2. Rebuild bằng ĐÚNG lệnh sản phẩm. --dry-run trước để có bản báo cáo divergence
//    không ghi gì, rồi mới rebuild thật.
//
// Kiểm tiền đề TƯỜNG MINH: `--rebuild` chỉ có từ WWHN-204 (DEV204-P1-03) trở đi.
// Nếu candidate được pin vào một SHA cũ hơn thì P1-02R KHÔNG thể thoả trên SHA đó,
// và điều phải xảy ra là báo lỗi rõ ràng — không phải một exception trần rồi thoát 0.
// ---------------------------------------------------------------------------
$reconcileCommand = Artisan::all()['inventory:reconcile'] ?? null;
if ($reconcileCommand === null || ! $reconcileCommand->getDefinition()->hasOption('rebuild')) {
    fwrite(STDERR,
        "WWHN-206 P1-02R KHÔNG thoả tại SHA này: lệnh `inventory:reconcile` không có option `--rebuild`.\n"
        ."`--rebuild` được thêm ở WWHN-204 (DEV204-P1-03, InventoryProjectionRebuildService).\n"
        ."Phải pin chosen_candidate vào một commit CÓ chứa thay đổi đó rồi chạy lại.\n"
    );
    exit(1);
}

$dryRunExit = Artisan::call('inventory:reconcile', ['--dry-run' => true]);
$dryRunOutput = Artisan::output();

$rebuildExit = Artisan::call('inventory:reconcile', [
    '--rebuild' => true,
    '--reason' => 'WWHN-206 P1-02R QA1 independent rebuild/reconciliation',
]);
$rebuildOutput = Artisan::output();

// ---------------------------------------------------------------------------
// 3. Trạng thái SAU rebuild — đây mới là tập số liệu register yêu cầu.
// ---------------------------------------------------------------------------
$after = $readMetrics();

$failedMetrics = array_values(array_filter(
    $after['metrics'],
    static fn (array $row): bool => ($row['status'] ?? 'FAIL') !== 'PASS'
));

// Rebuild là idempotent theo thiết kế: chạy lần hai không được đổi gì nữa.
$secondRebuildExit = Artisan::call('inventory:reconcile', [
    '--rebuild' => true,
    '--reason' => 'WWHN-206 P1-02R idempotency probe',
]);
$secondRebuildOutput = Artisan::output();
$afterSecond = $readMetrics();
$rebuildIdempotent = $after['metrics'] === $afterSecond['metrics'];

$gitSha = getenv('GITHUB_SHA') ?: (trim((string) @shell_exec('git rev-parse HEAD')) ?: null);
$gitDirty = trim((string) @shell_exec('git status --porcelain')) !== '';

$report = [
    'schema_version' => '1.0.0',
    'ticket' => 'WWHN-206',
    'parent_ticket' => 'WWHN-205',
    'answers_review_findings' => ['P1-02R'],
    'generated_at_utc' => gmdate('c'),
    'assertion_layer' => [
        'file' => 'scripts/wwhn206-ledger-reconciliation.sql',
        'sha256' => hash('sha256', $rawSql),
        'note' => 'SQL thuần; QA1 chạy độc lập bằng psql -f, không cần chạy script PHP này.',
        'qa1_command' => 'psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f scripts/wwhn206-ledger-reconciliation.sql',
    ],
    'environment' => $environment + [
        'db_connection' => (string) config('database.default'),
        'db_host' => (string) config('database.connections.pgsql.host'),
        'db_database' => (string) config('database.connections.pgsql.database'),
        'app_env' => (string) config('app.env'),
        'git_sha' => $gitSha,
        'git_working_tree_dirty' => $gitDirty,
        'github_run_id' => getenv('GITHUB_RUN_ID') ?: null,
        'php_version' => PHP_VERSION,
    ],
    'rebuild' => [
        'command' => 'php artisan inventory:reconcile --rebuild',
        'owner_of_command' => 'WWHN-204 / DEV204-P1-03 (InventoryProjectionRebuildService)',
        'dry_run_exit_code' => $dryRunExit,
        'dry_run_output' => trim($dryRunOutput),
        'rebuild_exit_code' => $rebuildExit,
        'rebuild_output' => trim($rebuildOutput),
        'second_rebuild_exit_code' => $secondRebuildExit,
        'second_rebuild_output' => trim($secondRebuildOutput),
        'rebuild_is_idempotent' => $rebuildIdempotent,
    ],
    'before_rebuild' => $before,
    'after_rebuild' => $after,
    'after_second_rebuild' => $afterSecond,
    'failed_metrics' => $failedMetrics,
    'status' => $failedMetrics === [] && $rebuildIdempotent ? 'PASS' : 'FAIL',
];

file_put_contents(
    rtrim($evidenceDir, '/\\').'/wwhn206-ledger-reconciliation.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode([
    'status' => $report['status'],
    'postgres_version' => $environment['postgres_version'] ?? null,
    'database' => $environment['database'] ?? null,
    'git_sha' => $gitSha,
    'git_working_tree_dirty' => $gitDirty,
    'rebuild_is_idempotent' => $rebuildIdempotent,
    'verdict' => $after['verdict'],
    'failed_metrics' => array_map(static fn (array $r): string => (string) $r['metric'], $failedMetrics),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($report['status'] === 'PASS' ? 0 : 1);
