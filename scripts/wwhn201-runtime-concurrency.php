<?php

declare(strict_types=1);

/**
 * WWHN-201 runtime concurrency orchestrator (Post Issue).
 *
 * Same executed-proof methodology as scripts/wwhn219-runtime-concurrency.php
 * (reuses scripts/wwhn219-concurrency-worker.php, which already dispatches the
 * `post_issue` operation type against CurrentBaselineStockService::postStockDocument).
 * Answers latest WWHN-201 review findings DEV201-P1-01R / DEV201-P1-02R: proof
 * that Post Issue is safe under REAL multi-process, multi-PostgreSQL-connection
 * contention, not just HTTP-simulated stale-version replay (see the doc-block on
 * tests/Feature/PostIssueConcurrencyBehaviorTest.php explaining why SQLite
 * ":memory:" cannot provide that proof).
 *
 * A scenario only passes when all hold:
 *   1. distinct pg_backend_pid() per worker (real separate connections),
 *   2. an observed lock wait or overlapping critical sections (real contention),
 *   3. exactly the expected number of successes,
 *   4. non-negative balance invariant never violated,
 *   5. movement SUM == balance delta (ledger/balance reconciliation).
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN201_EVIDENCE_DIR') ?: 'storage/app/wwhn-201-evidence');
$runtimeDir = $evidenceDir.'/runtime-concurrency';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-201 runtime concurrency evidence requires DB_CONNECTION=pgsql.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn219-concurrency-worker.php';
$holdMs = (int) (getenv('WWHN201_LOCK_HOLD_MS') ?: 1500);

$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function actorId(): string
{
    $id = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
        ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
    if ($id === null) {
        fail('WWHN-201 runtime concurrency requires canonical AuthDatabaseSeeder principal keeper01/admin.');
    }

    return (string) $id;
}

/**
 * Isolated SCANNING issue document, fully scanned (scanned_qty = qty_planned).
 * DB trigger hn_guard_stock_line_sku_scope() rejects non-PRODUCT SKUs on issue
 * lines, so PRODUCT_ITEM_MATCH_REQUIRED (CurrentBaselineStockService.php:702-727,
 * assertMatchedProductRfids) is in scope: one matched item_instances row per unit
 * of qty. RFID/label PRINTED are controller-layer concerns
 * (CurrentBaselineStockFlowController::postDocument) not enforced by
 * postStockDocument() itself, so they are out of scope here — this script proves
 * the SERVICE-layer row-lock/non-negative invariant under real DB contention,
 * exactly like the accepted WWHN-219 scope.
 */
function buildIssueFixture(string $tag, int $qty, ?string $warehouseId = null, ?string $skuId = null): array
{
    global $runToken;

    $actor = actorId();
    $now = now();
    $tag = $tag.'-'.$runToken;

    $warehouseId ??= (string) DB::table('warehouses')->value('id');
    if ($warehouseId === null || $warehouseId === '') {
        $warehouseId = (string) Str::uuid();
        DB::table('warehouses')->insert([
            'id' => $warehouseId,
            'code' => 'WWHN201-'.$tag,
            'name' => 'WWHN-201 runtime concurrency warehouse '.$tag,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    if ($skuId === null) {
        $skuId = (string) Str::uuid();
        DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'WWHN201-SKU-'.$tag,
            'sku_name' => 'WWHN-201 runtime concurrency SKU '.$tag,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $documentId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    DB::transaction(function () use ($documentId, $lineId, $warehouseId, $skuId, $actor, $now, $tag, $qty): void {
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'issue',
            'doc_no' => 'WWHN201-'.$tag.'-'.strtoupper(Str::random(6)),
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => $warehouseId,
            'dst_warehouse_id' => null,
            'note' => 'Isolated fixture for WWHN-201 executed concurrency evidence',
            'recipient_name' => 'WWHN-201 runtime concurrency recipient '.$tag,
            'version' => 1,
            'created_by' => $actor,
            'updated_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $skuId,
            'qty_planned' => $qty,
            'qty_actual' => $qty,
            'scanned_qty' => $qty,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        for ($i = 0; $i < $qty; $i++) {
            $itemId = (string) Str::uuid();
            DB::table('item_instances')->insert([
                'item_id' => $itemId,
                'sku_id' => $skuId,
                'serial_number' => 'WWHN201-SN-'.$tag.'-'.$i,
                'item_status' => 'IN_STOCK',
                'item_condition' => 'NORMAL',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('outbound_item_matches')->insert([
                'id' => (string) Str::uuid(),
                'outbound_line_id' => $lineId,
                'item_id' => $itemId,
                'quantity' => 1,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    });

    return [
        'document_id' => $documentId,
        'line_id' => $lineId,
        'warehouse_id' => $warehouseId,
        'sku_id' => $skuId,
        'qty' => $qty,
        'actor_id' => $actor,
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

/** Same barrier/spawn/lock-sample orchestration as wwhn219-runtime-concurrency.php. */
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

        $descriptors = [1 => ['file', $scenarioDir.'/worker-'.$index.'.stdout', 'w'], 2 => ['file', $scenarioDir.'/worker-'.$index.'.stderr', 'w']];
        $process = proc_open([$phpBinary, $workerScript, $planFile], $descriptors, $pipes);
        if (! is_resource($process)) {
            fail("Could not spawn WWHN-201 concurrency worker {$index}.");
        }
        $processes[$index] = $process;
    }

    $expected = count($workerPlans);
    $deadline = microtime(true) + 90.0;
    while (count(glob($barrierDir.'/ready-*') ?: []) < $expected) {
        if (microtime(true) > $deadline) {
            fail("Workers never reached the {$scenario} barrier.");
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

    touch($barrierDir.'/go');
    $sampleDeadline = microtime(true) + max(3.0, ($holdMs / 1000) + 2.0);
    while (microtime(true) < $sampleDeadline) {
        $sampleLocks();
        usleep(150000);
    }

    foreach ($processes as $process) {
        proc_close($process);
    }

    $workers = [];
    foreach ($outFiles as $index => $outFile) {
        if (! is_file($outFile)) {
            fail("Worker {$index} of {$scenario} produced no result file.");
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

    return [
        'workers' => array_values($workers),
        'distinct_backend_pids' => $pids,
        'distinct_backend_pid_count' => count($pids),
        'observed_pg_lock_wait' => $observedWait,
        'max_worker_lock_wait_ms' => $maxLockWaitMs,
        'lock_hold_ms' => $holdMs,
        'real_contention_proven' => $observedWait || $maxLockWaitMs >= (int) ($holdMs * 0.5),
        'lock_sample_count' => count($lockSamples),
    ];
}

$scenarios = [];
$failures = [];

// ---------------------------------------------------------------------------
// Scenario 1 — concurrent Post Issue on ONE document (DEV201-P1-01R: "simultaneous
// Post Issue bằng ≥2 process/DB connection"). Exactly one backend may transition
// SCANNING -> POSTED; the loser must be rejected by the document-state guard
// (lockDocument + status check in CurrentBaselineStockService::postStockDocument),
// and only one stock_movement/business_key may exist afterwards.
// ---------------------------------------------------------------------------
$fixture = buildIssueFixture('SAME-DOC', 3);
$before = balanceSnapshot($fixture['warehouse_id'], $fixture['sku_id']);
DB::table('inventory_balances')->insert([
    'id' => (string) Str::uuid(),
    'warehouse_id' => $fixture['warehouse_id'],
    'product_id' => $fixture['sku_id'],
    'sku_id' => $fixture['sku_id'],
    'stock_status' => 'AVAILABLE',
    'quantity' => 10,
    'on_hand_qty' => 10,
    'available_qty' => 10,
    'reserved_qty' => 0,
    'version' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
$before = balanceSnapshot($fixture['warehouse_id'], $fixture['sku_id']);

$race = runRace('concurrent-post-issue-same-document', [
    [
        'type' => 'post_issue',
        'document_id' => $fixture['document_id'],
        'document_type' => 'issue',
        'actor_id' => $fixture['actor_id'],
        'idempotency_key' => 'wwhn201-issue-a-'.$runToken.'-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn201-issue-a'),
        'contended_table' => 'stock_documents',
    ],
    [
        'type' => 'post_issue',
        'document_id' => $fixture['document_id'],
        'document_type' => 'issue',
        'actor_id' => $fixture['actor_id'],
        'idempotency_key' => 'wwhn201-issue-b-'.$runToken.'-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn201-issue-b'),
        'contended_table' => 'stock_documents',
    ],
], $runtimeDir, $holdMs);

$after = balanceSnapshot($fixture['warehouse_id'], $fixture['sku_id']);
$successes = count(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$movements = DB::table('stock_movements')->where('stock_document_id', $fixture['document_id'])->get();
$documentStatus = (string) DB::table('stock_documents')->where('id', $fixture['document_id'])->value('status');
$movementSum = (int) $movements->sum(static fn ($m) => abs((int) $m->quantity));
$balanceDelta = $before['quantity'] - $after['quantity'];

$checks = [
    'two_distinct_backend_connections' => $race['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race['real_contention_proven'],
    'exactly_one_success' => $successes === 1,
    'loser_rejected_state_conflict' => count(array_filter(
        $race['workers'],
        static fn (array $w): bool => $w['outcome'] === 'REJECTED' && $w['error_code'] === 'STATE_CONFLICT'
    )) === 1,
    'single_movement_written' => $movements->count() === 1,
    'business_key_matches_expected' => $movements->count() === 1
        && $movements->first()->business_key === sha1('ISSUE|'.$fixture['document_id'].'|'.$fixture['line_id']),
    'document_posted' => $documentStatus === 'POSTED',
    'movement_sum_equals_balance_delta' => $movementSum === $balanceDelta,
    'balance_never_negative' => $after['quantity'] >= 0 && $after['available_qty'] >= 0,
];
$scenarios['concurrent_post_issue_same_document'] = $race + [
    'fixture' => $fixture,
    'balance_before' => $before,
    'balance_after' => $after,
    'movement_count' => $movements->count(),
    'movement_sum' => $movementSum,
    'document_status' => $documentStatus,
    'checks' => $checks,
    'passed' => ! in_array(false, $checks, true),
];
if (! $scenarios['concurrent_post_issue_same_document']['passed']) {
    $failures[] = 'concurrent_post_issue_same_document';
}

// ---------------------------------------------------------------------------
// Scenario 2 — DEV201-P1-02R core ask: "row-lock race... non-negative invariant"
// on real PostgreSQL. Two DISTINCT issue documents for the same warehouse+SKU,
// both requesting qty that individually fit but jointly EXCEED available stock
// (available=5, A wants 3, B wants 3). Real concurrent connections contend on the
// shared inventory_balances row; the loser must be rejected by mutateBalance()'s
// non-negative check (422 INSUFFICIENT_AVAILABLE_STOCK/INSUFFICIENT_STOCK), never
// allowed to drive available_qty below zero — proving the lockForUpdate() + check
// window in CurrentBaselineStockService::mutateBalance() (lines ~161-179) holds
// under genuine multi-connection contention, not just sequential HTTP replay.
// ---------------------------------------------------------------------------
$sharedA = buildIssueFixture('NONNEG-A', 3);
$sharedB = buildIssueFixture('NONNEG-B', 3, $sharedA['warehouse_id'], $sharedA['sku_id']);
DB::table('inventory_balances')->insert([
    'id' => (string) Str::uuid(),
    'warehouse_id' => $sharedA['warehouse_id'],
    'product_id' => $sharedA['sku_id'],
    'sku_id' => $sharedA['sku_id'],
    'stock_status' => 'AVAILABLE',
    'quantity' => 5,
    'on_hand_qty' => 5,
    'available_qty' => 5,
    'reserved_qty' => 0,
    'version' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
$beforeShared = balanceSnapshot($sharedA['warehouse_id'], $sharedA['sku_id']);

$sharedRace = runRace('concurrent-post-issue-non-negative-shared-balance', [
    [
        'type' => 'post_issue',
        'document_id' => $sharedA['document_id'],
        'document_type' => 'issue',
        'actor_id' => $sharedA['actor_id'],
        'idempotency_key' => 'wwhn201-nonneg-a-'.$runToken.'-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn201-nonneg-a'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $sharedA['warehouse_id'],
    ],
    [
        'type' => 'post_issue',
        'document_id' => $sharedB['document_id'],
        'document_type' => 'issue',
        'actor_id' => $sharedB['actor_id'],
        'idempotency_key' => 'wwhn201-nonneg-b-'.$runToken.'-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn201-nonneg-b'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $sharedA['warehouse_id'],
    ],
], $runtimeDir, $holdMs);

$afterShared = balanceSnapshot($sharedA['warehouse_id'], $sharedA['sku_id']);
$sharedSuccesses = count(array_filter($sharedRace['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$sharedRejected = count(array_filter(
    $sharedRace['workers'],
    static fn (array $w): bool => $w['outcome'] === 'REJECTED'
        && in_array($w['error_code'], ['INSUFFICIENT_AVAILABLE_STOCK', 'INSUFFICIENT_STOCK'], true)
));
$sharedMovements = DB::table('stock_movements')
    ->whereIn('stock_document_id', [$sharedA['document_id'], $sharedB['document_id']])
    ->get();
$sharedMovementSum = (int) $sharedMovements->sum(static fn ($m) => abs((int) $m->quantity));
$sharedBalanceDelta = $beforeShared['quantity'] - $afterShared['quantity'];

$sharedChecks = [
    'two_distinct_backend_connections' => $sharedRace['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $sharedRace['real_contention_proven'],
    'exactly_one_success' => $sharedSuccesses === 1,
    'loser_rejected_insufficient_stock' => $sharedRejected === 1,
    'single_movement_written' => $sharedMovements->count() === 1,
    'movement_sum_equals_balance_delta' => $sharedMovementSum === $sharedBalanceDelta,
    'balance_never_negative' => $afterShared['quantity'] >= 0 && $afterShared['available_qty'] >= 0,
    'balance_matches_winner_qty_only' => $afterShared['quantity'] === ($beforeShared['quantity'] - 3),
];
$scenarios['concurrent_post_issue_non_negative_shared_balance'] = $sharedRace + [
    'fixtures' => [$sharedA, $sharedB],
    'balance_before' => $beforeShared,
    'balance_after' => $afterShared,
    'movement_count' => $sharedMovements->count(),
    'movement_sum' => $sharedMovementSum,
    'checks' => $sharedChecks,
    'passed' => ! in_array(false, $sharedChecks, true),
];
if (! $scenarios['concurrent_post_issue_non_negative_shared_balance']['passed']) {
    $failures[] = 'concurrent_post_issue_non_negative_shared_balance';
}

$summary = [
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-201',
    'answers_review_findings' => ['DEV201-P1-01R', 'DEV201-P1-02R'],
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY',
    'note' => 'Each scenario spawns real OS processes with independent PostgreSQL backends against CurrentBaselineStockService::postStockDocument (issue path). Scope mirrors the accepted WWHN-219 pattern: service-layer row-lock/non-negative proof, not controller-layer If-Match/Idempotency-Key HTTP replay (that remains a QA1 HTTP-level execution per DEV201-P1-01R ownership).',
    'git_sha' => getenv('GITHUB_SHA') ?: null,
    'github_run_id' => getenv('GITHUB_RUN_ID') ?: null,
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'lock_hold_ms' => $holdMs,
    'scenarios' => $scenarios,
    'failed_scenarios' => $failures,
    'status' => $failures === [] ? 'PASS' : 'FAIL',
];

file_put_contents(
    $evidenceDir.'/runtime-concurrency-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode([
    'status' => $summary['status'],
    'failed_scenarios' => $failures,
    'scenarios' => array_map(static fn (array $s): array => ['passed' => $s['passed'], 'checks' => $s['checks']], $scenarios),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($failures === [] ? 0 : 1);
