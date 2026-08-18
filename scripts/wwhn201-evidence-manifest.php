<?php

declare(strict_types=1);

/**
 * WWHN-201 / DEV201-P1-02R — đóng gói bằng chứng runtime concurrency thành artifact
 * BẤT BIẾN, kiểm tra lại được.
 *
 * Finding gốc: "raw executable artifact chưa pin… publish immutable
 * runtime-concurrency-summary.json, worker results, pg-lock-samples, DB
 * version/migration/index evidence; map behavior SHA ↔ harness SHA".
 *
 * `wwhn201-runtime-concurrency.php` đã sinh summary + worker + lock samples nhưng
 * chúng nằm rời trong storage/, không có digest, không có bối cảnh DB, và không nói
 * được chúng được sinh ở SHA nào. Script này chạy NGAY SAU harness, gom mọi thứ vào
 * `docs/evidence/WWHN-201/` kèm:
 *   - sha256 của từng file bằng chứng (nên sửa một byte là verifier đỏ),
 *   - phiên bản PostgreSQL, số migration đã chạy + migration cuối,
 *   - index thực tế trên đúng ba bảng quyết định tính đúng của race
 *     (inventory_balances, stock_documents, idempotency_keys),
 *   - ánh xạ behavior SHA (code Post Issue) ↔ harness SHA (script bằng chứng),
 *     lấy từ git chứ không chép tay.
 *
 * Chạy:
 *   DB_CONNECTION=pgsql php scripts/wwhn201-runtime-concurrency.php storage/app/wwhn-201-evidence
 *   DB_CONNECTION=pgsql php scripts/wwhn201-evidence-manifest.php storage/app/wwhn-201-evidence
 * Kiểm tra: node scripts/verify-wwhn-201-evidence.mjs
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sourceDir = $argv[1] ?? (getenv('WWHN201_EVIDENCE_DIR') ?: 'storage/app/wwhn-201-evidence');
$targetDir = __DIR__.'/../docs/evidence/WWHN-201';
@mkdir($targetDir, 0777, true);

/**
 * File nguồn của HÀNH VI được chứng minh. behavior SHA = commit cuối chạm vào tập
 * này. Tách khỏi harness SHA vì review đòi đúng ánh xạ đó: sửa script bằng chứng
 * không được làm "mới" lại hành vi, và ngược lại.
 */
const BEHAVIOR_SOURCES = [
    'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
    'Modules/Warehouse/app/Services/CurrentBaselineStockService.php',
    'app/Support/IdempotencyStore.php',
    'Modules/Warehouse/routes/api.php',
];

/** File nguồn của BẰNG CHỨNG. */
const HARNESS_SOURCES = [
    'scripts/wwhn201-runtime-concurrency.php',
    'scripts/wwhn219-concurrency-worker.php',
    'scripts/wwhn201-evidence-manifest.php',
    'tests/Feature/Wwhn201PostIssueErrorContractTest.php',
    'tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php',
];

function git(string $args): ?string
{
    $out = @shell_exec('git -C '.escapeshellarg(realpath(__DIR__.'/..')).' '.$args.' 2>&1');
    $out = is_string($out) ? trim($out) : '';

    return $out === '' ? null : $out;
}

/** Commit cuối cùng chạm vào tập file — ổn định hơn HEAD (HEAD trôi mỗi lần push). */
function lastCommitTouching(array $paths): array
{
    $existing = array_values(array_filter($paths, static fn (string $p): bool => is_file(__DIR__.'/../'.$p)));
    $missing = array_values(array_diff($paths, $existing));
    $quoted = implode(' ', array_map('escapeshellarg', $existing));
    $sha = $existing === [] ? null : git('log -1 --format=%H -- '.$quoted);
    $subject = $sha === null ? null : git('log -1 --format=%s '.escapeshellarg($sha));

    return [
        'files' => $paths,
        'missing_files' => $missing,
        'last_commit' => $sha,
        'last_commit_subject' => $subject,
        'file_digests' => array_combine(
            $existing,
            array_map(static fn (string $p): string => hash_file('sha256', __DIR__.'/../'.$p), $existing)
        ),
    ];
}

/** Sao chép file bằng chứng sang docs/evidence và trả về bảng digest. */
function collectEvidence(string $sourceDir, string $targetDir): array
{
    $collected = [];
    if (! is_dir($sourceDir)) {
        return $collected;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (! $file->isFile()) {
            continue;
        }
        // barrier/plan là file điều phối tạm, không phải bằng chứng.
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
        if (str_contains($relative, '/barrier/') || str_starts_with(basename($relative), 'plan-')) {
            continue;
        }
        $destination = $targetDir.'/runtime/'.$relative;
        @mkdir(dirname($destination), 0777, true);
        copy($file->getPathname(), $destination);
        $collected['runtime/'.$relative] = hash_file('sha256', $destination);
    }
    ksort($collected);

    return $collected;
}

/**
 * DB có thể không kết nối được ở máy soạn artifact (credential PostgreSQL do DevOps
 * giữ). Không được vì thế mà mất luôn phần SHA mapping/digest — nên phần DB fail-soft
 * và ghi rõ trạng thái. Verifier chỉ chấp nhận đóng gate khi db_evidence_status = OK,
 * nên fail-soft ở đây KHÔNG tạo ra một artifact "xanh giả".
 */
$driver = null;
$dbEvidenceStatus = 'OK';
$dbError = null;

try {
    $driver = DB::connection()->getDriverName();
    $dbEvidence = [
        'driver' => $driver,
        'server_version' => $driver === 'pgsql'
            ? (string) DB::selectOne('select version() as v')->v
            : (string) DB::selectOne('select sqlite_version() as v')->v,
        'migrations_ran' => (int) DB::table('migrations')->count(),
        'last_migration' => (string) (DB::table('migrations')->orderByDesc('id')->value('migration') ?? ''),
    ];
} catch (Throwable $exception) {
    $dbEvidenceStatus = 'UNAVAILABLE';
    $dbError = $exception->getMessage();
    $dbEvidence = ['driver' => $driver, 'server_version' => null, 'migrations_ran' => null, 'last_migration' => null];
}

/**
 * Index là điều kiện đủ để row-lock/uniqueness hoạt động đúng. Không chụp lại index
 * thì summary "PASS" chỉ chứng minh cho một schema không ai biết là schema nào.
 */
$indexTables = ['inventory_balances', 'stock_documents', 'idempotency_keys', 'stock_movements'];
$indexes = [];
foreach ($dbEvidenceStatus === 'OK' ? $indexTables : [] as $table) {
    if ($driver === 'pgsql') {
        $rows = DB::select('select indexname, indexdef from pg_indexes where tablename = ? order by indexname', [$table]);
        $indexes[$table] = array_map(static fn ($r): array => ['name' => $r->indexname, 'definition' => $r->indexdef], $rows);
    } else {
        $rows = DB::select("select name, sql from sqlite_master where type='index' and tbl_name = ? order by name", [$table]);
        $indexes[$table] = array_map(static fn ($r): array => ['name' => $r->name, 'definition' => (string) $r->sql], $rows);
    }
}

$evidenceFiles = collectEvidence($sourceDir, $targetDir);

$summaryPath = $sourceDir.'/runtime-concurrency-summary.json';
$summary = is_file($summaryPath)
    ? json_decode((string) file_get_contents($summaryPath), true, 512, JSON_THROW_ON_ERROR)
    : null;

$manifest = [
    'ticket' => 'WWHN-201',
    'findings_covered' => ['DEV201-P1-02R', 'DEV201-P1-03R'],
    'generated_at' => now()->toIso8601String(),
    'decision' => 'DEC-OUT-STOCK-HTTP-001',
    // Ánh xạ SHA mà review đòi. Hai SHA khác nhau là BÌNH THƯỜNG và đúng thiết kế:
    // một cái nói "hành vi này được chứng minh", cái kia nói "bằng cách nào".
    'sha_mapping' => [
        'behavior' => lastCommitTouching(BEHAVIOR_SOURCES),
        'harness' => lastCommitTouching(HARNESS_SOURCES),
        'head_at_generation' => git('rev-parse HEAD'),
        'note' => 'behavior SHA = commit cuối chạm code Post Issue/Idempotency; harness SHA = commit cuối chạm script + test bằng chứng. Đừng pin HEAD: HEAD trôi mỗi lần nhánh m1 nhận commit của task khác, còn hai SHA này chỉ đổi khi đúng phần liên quan đổi.',
    ],
    'db_evidence_status' => $dbEvidenceStatus,
    'db_evidence_error' => $dbError,
    'database' => $dbEvidence,
    'indexes' => $indexes,
    'runtime_summary_status' => $summary['status'] ?? 'NOT_RUN',
    'runtime_summary_scenarios' => $summary === null
        ? []
        : array_map(static fn (array $s): array => ['passed' => $s['passed'], 'checks' => $s['checks']], $summary['scenarios']),
    'evidence_files' => $evidenceFiles,
    'evidence_file_count' => count($evidenceFiles),
];

$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
file_put_contents($targetDir.'/MANIFEST.json', $manifestJson);
file_put_contents($targetDir.'/MANIFEST.sha256', hash('sha256', str_replace("\r\n", "\n", $manifestJson))."  MANIFEST.json\n");

echo json_encode([
    'manifest' => 'docs/evidence/WWHN-201/MANIFEST.json',
    'manifest_sha256' => hash('sha256', str_replace("\r\n", "\n", $manifestJson)),
    'runtime_summary_status' => $manifest['runtime_summary_status'],
    'evidence_file_count' => $manifest['evidence_file_count'],
    'db_evidence_status' => $dbEvidenceStatus,
    'behavior_sha' => $manifest['sha_mapping']['behavior']['last_commit'],
    'harness_sha' => $manifest['sha_mapping']['harness']['last_commit'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

exit(($manifest['runtime_summary_status'] === 'PASS' || $manifest['runtime_summary_status'] === 'NOT_RUN') ? 0 : 1);
