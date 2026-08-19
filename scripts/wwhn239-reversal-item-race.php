<?php

declare(strict_types=1);

/**
 * WWHN-239 / WWHN-241 — DEV239-P1-03R2 / DEV239-P1-04 case #8: "true competing reversal".
 *
 * Sequential PHPUnit (Wwhn239ReversalItemLevelClosureTest) already proves the guard logic.
 * This script proves the same invariant holds under REAL PostgreSQL concurrency: two OS
 * processes, two distinct pg_backend_pid(), both racing CurrentBaselineReversalService::execute()
 * for the SAME item_id on the SAME original movement, different Idempotency-Keys.
 *
 * Reuses the exact worker/orchestration harness already accepted for WWHN-205/219/198/199
 * (scripts/wwhn219-concurrency-worker.php) — no new race infrastructure, only a new fixture +
 * scenario definition, plus one additive 'reversal_execute' operation case in the shared worker.
 *
 * Run (isolated worktree, dedicated DB):
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn239_r2 \
 *   DB_USERNAME=postgres DB_PASSWORD=postgres php scripts/wwhn239-reversal-item-race.php [evidenceDir]
 *
 * Exit 0 = PASS, 1 = FAIL, 2 = wrong driver.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Services\CurrentBaselineStockService;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN239_EVIDENCE_DIR') ?: 'docs/evidence/WWHN-239/runtime');
$runtimeDir = $evidenceDir.'/reversal-item-race';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-239 reversal race evidence requires DB_CONNECTION=pgsql.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn219-concurrency-worker.php';
$holdMs = (int) (getenv('WWHN239_LOCK_HOLD_MS') ?: 1200);
$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function insertRow(string $table, array $row): void
{
    $filtered = array_filter(
        $row,
        static fn (string $column): bool => Schema::hasColumn($table, $column),
        ARRAY_FILTER_USE_KEY
    );
    DB::table($table)->insert($filtered);
}

function actorId(): string
{
    $id = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
        ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
    if ($id === null) {
        fail('WWHN-239 race cần principal chuẩn keeper01/admin của AuthDatabaseSeeder.');
    }

    return (string) $id;
}

function ensureWarehouse(string $tag): string
{
    $warehouseId = (string) DB::table('warehouses')->value('id');
    if ($warehouseId !== '') {
        return $warehouseId;
    }

    $warehouseId = (string) Str::uuid();
    insertRow('warehouses', [
        'id' => $warehouseId,
        'code' => 'WWHN239-'.$tag,
        'name' => 'WWHN-239 race warehouse '.$tag,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $warehouseId;
}

/**
 * Chứng từ RECEIPT serial-tracked N item, scan đủ rồi POST thật qua
 * CurrentBaselineStockService::postStockDocument() — cùng đường mã sản xuất, không viết
 * movement tay để tránh fixture tự bịa ra một invariant khác của chính nó.
 */
function postedSerialReceipt(string $tag, int $itemCount, string $warehouseId): array
{
    global $runToken;

    $actor = actorId();
    $now = now();
    $tag = $tag.'-'.$runToken;
    $skuId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    insertRow('sku', [
        'sku_id' => $skuId,
        'sku_code' => 'WWHN239-SKU-'.$tag,
        'sku_name' => 'WWHN-239 race SKU '.$tag,
        'sku_type' => 'PRODUCT',
        'unit' => 'cai',
        'is_serial_tracked' => true,
        'status' => 'ACTIVE',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $itemIds = [];
    DB::transaction(function () use ($documentId, $lineId, $warehouseId, $skuId, $actor, $now, $tag, $itemCount, &$itemIds): void {
        insertRow('stock_documents', [
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => 'WWHN239-R-'.$tag.'-'.strtoupper(Str::random(5)),
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => null,
            'dst_warehouse_id' => $warehouseId,
            'note' => 'WWHN-239 executed reversal-item race fixture',
            'source_name' => 'WWHN-239 race source '.$tag,
            'version' => 1,
            'created_by' => $actor,
            'updated_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        insertRow('stock_document_lines', [
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $skuId,
            'qty_planned' => $itemCount,
            'qty_actual' => $itemCount,
            'scanned_qty' => $itemCount,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            $itemId = (string) Str::uuid();
            $itemIds[] = $itemId;
            insertRow('item_instances', [
                'item_id' => $itemId,
                'sku_id' => $skuId,
                'serial_number' => 'WWHN239-SN-'.$tag.'-'.$i,
                'item_status' => 'NEW',
                'item_condition' => 'NORMAL',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            insertRow('inbound_scan_entries', [
                'id' => (string) Str::uuid(),
                'inbound_line_id' => $lineId,
                'item_id' => $itemId,
                'product_id' => $skuId,
                'quantity' => 1,
                'scan_source' => 'CAMERA_OR_MANUAL_CODE',
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    });

    $post = app(CurrentBaselineStockService::class)->postStockDocument(
        $documentId,
        'receipt',
        $actor,
        'wwhn239-race-post-'.$tag,
        sha1('wwhn239-race-post-'.$tag)
    );

    $movementId = (string) DB::table('stock_movements')
        ->where('stock_document_id', $documentId)
        ->where('movement_type', 'RECEIPT')
        ->value('id');
    if ($movementId === '') {
        fail("Fixture {$tag}: khong tim thay movement RECEIPT sau khi post.");
    }

    $version = (int) DB::table('stock_documents')->where('id', $documentId)->value('version');

    return [
        'document_id' => $documentId,
        'line_id' => $lineId,
        'movement_id' => $movementId,
        'warehouse_id' => $warehouseId,
        'sku_id' => $skuId,
        'item_ids' => $itemIds,
        'actor_id' => $actor,
        'version' => $version,
        'post_result' => $post,
    ];
}

function balanceSnapshot(string $warehouseId, string $skuId): array
{
    $row = DB::table('inventory_balances')
        ->where('warehouse_id', $warehouseId)
        ->where(function ($q) use ($skuId): void {
            $q->where('sku_id', $skuId)->orWhere('product_id', $skuId);
        })
        ->where('stock_status', 'AVAILABLE')
        ->first();

    return [
        'exists' => $row !== null,
        'quantity' => $row === null ? 0 : (int) $row->quantity,
        'available_qty' => $row === null ? 0 : (int) $row->available_qty,
        'version' => $row === null ? 0 : (int) $row->version,
    ];
}

/** Barrier + spawn + lấy mẫu pg_locks — cùng orchestration đã dùng ở WWHN-205/219. */
function runRace(string $scenario, array $workerPlans, string $runtimeDir, int $holdMs): array
{
    global $phpBinary, $workerScript;

    $scenarioDir = $runtimeDir.'/'.$scenario;
    $barrierDir = $scenarioDir.'/barrier';
    @mkdir($barrierDir, 0777, true);
    array_map('unlink', glob($barrierDir.'/*') ?: []);

    $processes = [];
    $outFiles = [];
    foreach ($workerPlans as $index => $operation) {
        $planFile = $scenarioDir.'/plan-'.$index.'.json';
        $outFile = $scenarioDir.'/worker-'.$index.'.json';
        $outFiles[$index] = $outFile;
        @unlink($outFile);

        file_put_contents($planFile, json_encode([
            'scenario' => $scenario,
            'worker_index' => $index,
            'barrier_dir' => $barrierDir,
            'out_file' => $outFile,
            'hold_ms' => $holdMs,
            'operation' => $operation,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $descriptors = [
            1 => ['file', $scenarioDir.'/worker-'.$index.'.stdout', 'w'],
            2 => ['file', $scenarioDir.'/worker-'.$index.'.stderr', 'w'],
        ];
        $process = proc_open([$phpBinary, $workerScript, $planFile], $descriptors, $pipes);
        if (! is_resource($process)) {
            fail("Khong spawn duoc worker {$index} cua {$scenario}.");
        }
        $processes[$index] = $process;
    }

    $expected = count($workerPlans);
    $deadline = microtime(true) + 120.0;
    while (count(glob($barrierDir.'/ready-*') ?: []) < $expected) {
        if (microtime(true) > $deadline) {
            fail("Worker khong toi duoc barrier cua {$scenario}.");
        }
        usleep(5000);
    }

    $lockSamples = [];
    $sampleLocks = static function () use (&$lockSamples): void {
        $lockSamples[] = [
            'sampled_at' => now()->toIso8601String(),
            'rows' => DB::select(
                "select a.pid, a.application_name, a.state, a.wait_event_type, a.wait_event, l.granted, l.locktype, l.mode
                 from pg_stat_activity a
                 left join pg_locks l on l.pid = a.pid and l.granted = false
                 where a.application_name like 'wwhn219-worker-%'
                 order by a.pid"
            ),
        ];
    };

    $releasedAt = microtime(true);
    touch($barrierDir.'/go');
    $sampleDeadline = microtime(true) + max(4.0, ($holdMs / 1000) * (float) $expected + 3.0);
    while (microtime(true) < $sampleDeadline) {
        $sampleLocks();
        usleep(120000);
    }

    foreach ($processes as $process) {
        proc_close($process);
    }

    $workers = [];
    foreach ($outFiles as $index => $outFile) {
        if (! is_file($outFile)) {
            fail("Worker {$index} cua {$scenario} khong sinh file ket qua.");
        }
        $workers[$index] = json_decode((string) file_get_contents($outFile), true, 512, JSON_THROW_ON_ERROR);
    }

    file_put_contents(
        $scenarioDir.'/pg-lock-samples.json',
        json_encode($lockSamples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
    );

    $pids = array_values(array_unique(array_map(static fn (array $w): int => (int) $w['backend_pid'], $workers)));
    $observedWait = false;
    foreach ($lockSamples as $sample) {
        foreach ($sample['rows'] as $row) {
            if ($row->granted === false || $row->wait_event_type === 'Lock') {
                $observedWait = true;
                break 2;
            }
        }
    }

    $maxLockWaitMs = max(array_map(static fn (array $w): int => (int) ($w['lock_wait_ms'] ?? 0), $workers));

    $overlapProven = false;
    foreach ($workers as $a) {
        foreach ($workers as $b) {
            if ($a['worker_index'] === $b['worker_index']) {
                continue;
            }
            $aAcquired = (float) ($a['timeline']['lock_acquired_at'] ?? 0);
            $aReleased = (float) ($a['timeline']['hold_released_at'] ?? 0);
            $bReleasedFromBarrier = (float) ($b['timeline']['released_at'] ?? 0);
            if ($aAcquired > 0 && $aReleased > $aAcquired && $bReleasedFromBarrier > 0
                && $bReleasedFromBarrier < $aReleased) {
                $overlapProven = true;
                break 2;
            }
        }
    }

    return [
        'workers' => array_values($workers),
        'worker_count' => $expected,
        'distinct_backend_pids' => $pids,
        'distinct_backend_pid_count' => count($pids),
        'observed_pg_lock_wait' => $observedWait,
        'overlapping_critical_sections' => $overlapProven,
        'max_worker_lock_wait_ms' => $maxLockWaitMs,
        'lock_hold_ms' => $holdMs,
        'barrier_released_at' => $releasedAt,
        'real_contention_proven' => $observedWait || $overlapProven || $maxLockWaitMs >= (int) ($holdMs * 0.5),
        'lock_sample_count' => count($lockSamples),
    ];
}

// ---------------------------------------------------------------------------
// Case #8 QA1_TEST_PACK_WWHN-241 §3 — true competing reversal: hai process thật cùng
// gọi CurrentBaselineReversalService::execute() cho CÙNG item_id trên CÙNG movement gốc,
// khác Idempotency-Key. Đúng một phải POSTED, phía kia phải bị chặn ở tầng DB
// (partial unique index ux_reversal_line_posted_item_per_movement), không phụ thuộc thứ
// tự lock của tầng ứng dụng.
// ---------------------------------------------------------------------------
$warehouseId = ensureWarehouse('R8');
$fixture = postedSerialReceipt('R8', 2, $warehouseId);
$targetItemId = $fixture['item_ids'][0];
$beforeBalance = balanceSnapshot($warehouseId, $fixture['sku_id']);

$race = runRace('case8-true-competing-reversal-same-item', [
    [
        'type' => 'reversal_execute',
        'document_id' => $fixture['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $fixture['actor_id'],
        'idempotency_key' => 'wwhn239-race-a-'.$runToken,
        'request_hash' => sha1('wwhn239-race-a'),
        'expected_version' => $fixture['version'],
        'contended_table' => 'stock_documents',
        'contended_key' => 'id',
        'contended_value' => $fixture['document_id'],
        'payload' => [
            'reason' => 'WWHN-239 race worker A',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
                'item_id' => $targetItemId,
            ]],
        ],
    ],
    [
        'type' => 'reversal_execute',
        'document_id' => $fixture['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $fixture['actor_id'],
        'idempotency_key' => 'wwhn239-race-b-'.$runToken,
        'request_hash' => sha1('wwhn239-race-b'),
        'expected_version' => $fixture['version'],
        'contended_table' => 'stock_documents',
        'contended_key' => 'id',
        'contended_value' => $fixture['document_id'],
        'payload' => [
            'reason' => 'WWHN-239 race worker B',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
                'item_id' => $targetItemId,
            ]],
        ],
    ],
], $runtimeDir, $holdMs);

$afterBalance = balanceSnapshot($warehouseId, $fixture['sku_id']);
$successWorkers = array_values(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejectedWorkers = array_values(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$reversalMovementsForItem = DB::table('stock_movements')
    ->where('movement_type', 'REVERSAL')
    ->where('item_id', $targetItemId)
    ->count();
$postedReversalLinesForItem = DB::table('reversal_line')
    ->where('item_id', $targetItemId)
    ->whereNotNull('posted_movement_id')
    ->count();
$q7 = DB::select("select indexname from pg_indexes where tablename = 'reversal_line' and indexname = 'ux_reversal_line_posted_item_per_movement'");
if ($q7 === []) {
    // Bảng vật lý có thể là danh từ số nhiều tuỳ driver — dò lại theo bảng reversal_lines.
    $q7 = DB::select("select indexname from pg_indexes where tablename = 'reversal_lines' and indexname = 'ux_reversal_line_posted_item_per_movement'");
}

$checks = [
    'two_distinct_backend_connections' => $race['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race['real_contention_proven'],
    'exactly_one_success' => count($successWorkers) === 1,
    'loser_rejected_with_expected_error' => count($rejectedWorkers) === 1
        && in_array($rejectedWorkers[0]['error_code'] ?? null, ['CONCURRENCY_CONFLICT', 'REVERSAL_ITEM_ALREADY_REVERSED'], true),
    'exactly_one_reversal_movement_for_item' => $reversalMovementsForItem === 1,
    'exactly_one_posted_reversal_line_for_item' => $postedReversalLinesForItem === 1,
    'db_level_unique_index_present' => count($q7) === 1,
    'balance_decreased_by_exactly_one' => $afterBalance['quantity'] === $beforeBalance['quantity'] - 1,
    'balance_never_negative' => $afterBalance['quantity'] >= 0 && $afterBalance['available_qty'] >= 0,
];

$scenario = $race + [
    'intent' => 'Hai process that (2 pg_backend_pid rieng) cung goi CurrentBaselineReversalService::execute() cho CUNG item_id tren CUNG movement goc, khac Idempotency-Key.',
    'fixture' => $fixture,
    'target_item_id' => $targetItemId,
    'balance_before' => $beforeBalance,
    'balance_after' => $afterBalance,
    'reversal_movements_for_item' => $reversalMovementsForItem,
    'posted_reversal_lines_for_item' => $postedReversalLinesForItem,
    'checks' => $checks,
    'passed' => ! in_array(false, $checks, true),
];

$failures = $scenario['passed'] ? [] : ['case8_true_competing_reversal_same_item'];

$summary = [
    'schema_version' => '1.0.0',
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-239',
    'sibling_ticket' => 'WWHN-241',
    'answers_review_findings' => ['DEV239-P1-03R2', 'DEV239-P1-04R2 case #8'],
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY_REVERSAL_SAME_ITEM',
    'note' => 'Muc dich: chung minh guard 3 lop (draft-time, lockForUpdate re-check, DB partial unique index) giu dung mot movement REVERSAL cho mot serial du hai request that cung tranh mot row trong PostgreSQL. Doi tuong goi la CurrentBaselineReversalService::execute() - dung code path ma controller CurrentBaselineStockFlowController::reverse() goi qua $this->reversal, KHONG PHAI CurrentBaselineStockService::reverseStockDocument (khong con route nao tro toi).',
    'git_sha' => trim((string) @shell_exec('git rev-parse HEAD')) ?: null,
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'database' => (string) DB::selectOne('select current_database() as d')->d,
    'lock_hold_ms' => $holdMs,
    'run_token' => $runToken,
    'scenario' => $scenario,
    'failed_scenarios' => $failures,
    'status' => $failures === [] ? 'PASS' : 'FAIL',
];

file_put_contents(
    $evidenceDir.'/reversal-item-race-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode([
    'status' => $summary['status'],
    'postgres_version' => $summary['postgres_version'],
    'checks' => $checks,
    'workers' => array_map(static fn (array $w): array => [
        'worker_index' => $w['worker_index'],
        'backend_pid' => $w['backend_pid'],
        'outcome' => $w['outcome'],
        'http_status' => $w['http_status'],
        'error_code' => $w['error_code'],
    ], $race['workers']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($failures === [] ? 0 : 1);
