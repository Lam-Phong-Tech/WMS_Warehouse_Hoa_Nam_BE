<?php

declare(strict_types=1);

/**
 * WWHN-219 runtime concurrency orchestrator.
 *
 * Replaces source-string inspection with executed proof. For each scenario it
 * builds an isolated fixture, spawns N real OS processes (each with its own
 * PostgreSQL backend), releases them through a filesystem barrier, samples
 * pg_locks/pg_stat_activity from an independent monitor connection while they
 * contend, and then asserts the committed ledger + balance + audit outcome.
 *
 * A scenario only passes when all four hold:
 *   1. distinct pg_backend_pid() per worker (real separate connections),
 *   2. an observed lock wait or overlapping critical sections (real contention),
 *   3. exactly the expected number of successes,
 *   4. the persisted ledger/balance/audit invariants.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN219_EVIDENCE_DIR') ?: 'storage/app/wwhn-219-evidence');
$runtimeDir = $evidenceDir.'/runtime-concurrency';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-219 runtime concurrency evidence requires DB_CONNECTION=pgsql.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn219-concurrency-worker.php';
$holdMs = (int) (getenv('WWHN219_LOCK_HOLD_MS') ?: 1500);

// Fixtures carry a per-run token so the proof is rerunnable against a database
// that already holds rows from an earlier run.
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
        fail('WWHN-219 runtime concurrency requires canonical AuthDatabaseSeeder principal keeper01/admin.');
    }

    return (string) $id;
}

/**
 * Build a self-contained SCANNING receipt document. Isolated per scenario so the
 * proof never depends on business seed ordering or on rows another test left behind.
 */
function buildReceiptFixture(string $tag, int $qty, ?string $warehouseId = null, ?string $skuId = null): array
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
            'code' => 'WWHN219-'.$tag,
            'name' => 'WWHN-219 runtime concurrency warehouse '.$tag,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    if ($skuId === null) {
        $skuId = (string) Str::uuid();
        DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'WWHN219-SKU-'.$tag,
            'sku_name' => 'WWHN-219 runtime concurrency SKU '.$tag,
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
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
            'doc_type' => 'receipt',
            'doc_no' => 'WWHN219-'.$tag.'-'.strtoupper(Str::random(6)),
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => null,
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'WWHN-219 runtime concurrency fixture',
            'note' => 'Isolated fixture for executed concurrency evidence',
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
        'on_hand_qty' => $row === null ? 0 : (int) $row->on_hand_qty,
        'available_qty' => $row === null ? 0 : (int) $row->available_qty,
        'version' => $row === null ? 0 : (int) $row->version,
    ];
}

/**
 * Spawn one OS process per worker plan, release the barrier once every worker has
 * registered its backend pid, and sample pg_locks while they are contending.
 */
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
            fail("Could not spawn WWHN-219 concurrency worker {$index}.");
        }
        $processes[$index] = $process;
    }

    // Wait until every worker has its own backend connection registered.
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

    // Release all workers simultaneously, then sample the contention window.
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

    // Fallback proof of genuine overlap: the loser's measured block time proves it
    // sat inside PostgreSQL waiting for the winner's row lock.
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
// Scenario 1 — concurrent Post Receipt on one document.
// Exactly one backend may create the stock effect; the loser must be rejected by
// the state guard, and the ledger/balance/audit must show a single application.
// ---------------------------------------------------------------------------
$fixture = buildReceiptFixture('POST', 5);
$before = balanceSnapshot($fixture['warehouse_id'], $fixture['sku_id']);
$race = runRace('concurrent-post-receipt', [
    [
        'type' => 'post_receipt',
        'document_id' => $fixture['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $fixture['actor_id'],
        'idempotency_key' => 'wwhn219-post-a-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn219-post-a'),
        'contended_table' => 'stock_documents',
    ],
    [
        'type' => 'post_receipt',
        'document_id' => $fixture['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $fixture['actor_id'],
        'idempotency_key' => 'wwhn219-post-b-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn219-post-b'),
        'contended_table' => 'stock_documents',
    ],
], $runtimeDir, $holdMs);

$after = balanceSnapshot($fixture['warehouse_id'], $fixture['sku_id']);
$successes = count(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$movementCount = DB::table('stock_movements')->where('stock_document_id', $fixture['document_id'])->count();
$auditCount = DB::table('audit_log')
    ->where('object_type', 'stock_document')
    ->where('object_id', $fixture['document_id'])
    ->where('action', 'POST_RECEIPT')
    ->count();
$documentStatus = (string) DB::table('stock_documents')->where('id', $fixture['document_id'])->value('status');

$checks = [
    'two_distinct_backend_connections' => $race['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race['real_contention_proven'],
    'exactly_one_success' => $successes === 1,
    'loser_rejected_not_errored' => count(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED')) === 1,
    'balance_increased_exactly_once' => ($after['quantity'] - $before['quantity']) === $fixture['qty'],
    'single_movement_written' => $movementCount === 1,
    'single_audit_entry' => $auditCount === 1,
    'document_posted' => $documentStatus === 'POSTED',
];
$scenarios['concurrent_post_receipt'] = $race + [
    'fixture' => $fixture,
    'balance_before' => $before,
    'balance_after' => $after,
    'movement_count' => $movementCount,
    'audit_count' => $auditCount,
    'document_status' => $documentStatus,
    'checks' => $checks,
    'passed' => ! in_array(false, $checks, true),
];
if (! $scenarios['concurrent_post_receipt']['passed']) {
    $failures[] = 'concurrent_post_receipt';
}

// ---------------------------------------------------------------------------
// Scenario 2 — concurrent Reversal of a posted receipt.
// Append-only correction must not double-reverse (BR-REV-09).
// ---------------------------------------------------------------------------
$revFixture = buildReceiptFixture('REV', 4);
app(\Modules\Warehouse\Services\CurrentBaselineStockService::class)->postStockDocument(
    $revFixture['document_id'],
    'receipt',
    $revFixture['actor_id'],
    'wwhn219-rev-seed-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
    sha1('wwhn219-rev-seed')
);

$revMovements = DB::table('stock_movements')
    ->where('stock_document_id', $revFixture['document_id'])
    ->where('movement_type', 'RECEIPT')
    ->get();
$reversePayload = [
    'reason_code' => 'WWHN219_RUNTIME_RACE',
    'reason' => 'WWHN-219 executed concurrent reversal evidence',
    'lines' => $revMovements->map(static fn ($m): array => [
        'movement_id' => (string) $m->id,
        'quantity' => abs((int) $m->quantity),
    ])->values()->all(),
];
$balanceBeforeReverse = balanceSnapshot($revFixture['warehouse_id'], $revFixture['sku_id']);

$revRace = runRace('concurrent-reversal', [
    [
        'type' => 'reverse',
        'document_id' => $revFixture['document_id'],
        'document_type' => 'receipt',
        'payload' => $reversePayload,
        'actor_id' => $revFixture['actor_id'],
        'idempotency_key' => 'wwhn219-rev-a-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn219-rev-a'),
        'contended_table' => 'stock_documents',
    ],
    [
        'type' => 'reverse',
        'document_id' => $revFixture['document_id'],
        'document_type' => 'receipt',
        'payload' => $reversePayload,
        'actor_id' => $revFixture['actor_id'],
        'idempotency_key' => 'wwhn219-rev-b-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn219-rev-b'),
        'contended_table' => 'stock_documents',
    ],
], $runtimeDir, $holdMs);

$balanceAfterReverse = balanceSnapshot($revFixture['warehouse_id'], $revFixture['sku_id']);
$revSuccesses = count(array_filter($revRace['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$postedReversals = DB::table('reversal_document')
    ->where('original_document_id', $revFixture['document_id'])
    ->where('status', 'POSTED')
    ->count();
$originalQty = (int) $revMovements->sum(static fn ($m): int => abs((int) $m->quantity));
$reversalIds = DB::table('reversal_document')->where('original_document_id', $revFixture['document_id'])->pluck('reversal_document_id')->all();
$reversedQty = $reversalIds === [] ? 0 : (int) DB::table('stock_movements')
    ->where('movement_type', 'REVERSAL')
    ->whereIn('source_id', $reversalIds)
    ->get()
    ->sum(static fn ($m): int => abs((int) $m->quantity));

$revChecks = [
    'two_distinct_backend_connections' => $revRace['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $revRace['real_contention_proven'],
    'exactly_one_success' => $revSuccesses === 1,
    'exactly_one_posted_reversal' => $postedReversals === 1,
    'no_over_reversal' => $reversedQty <= $originalQty,
    'balance_decreased_exactly_once' => ($balanceBeforeReverse['quantity'] - $balanceAfterReverse['quantity']) === $originalQty,
    'balance_never_negative' => $balanceAfterReverse['quantity'] >= 0,
];
$scenarios['concurrent_reversal'] = $revRace + [
    'fixture' => $revFixture,
    'balance_before' => $balanceBeforeReverse,
    'balance_after' => $balanceAfterReverse,
    'original_quantity' => $originalQty,
    'reversed_quantity' => $reversedQty,
    'posted_reversal_count' => $postedReversals,
    'checks' => $revChecks,
    'passed' => ! in_array(false, $revChecks, true),
];
if (! $scenarios['concurrent_reversal']['passed']) {
    $failures[] = 'concurrent_reversal';
}

// ---------------------------------------------------------------------------
// Scenario 3 — lost-update race on one shared inventory_balances row.
// Two distinct receipt documents for the same warehouse+SKU post simultaneously.
// Both are legitimate, so both must commit, and the row-level lock plus version
// check must make the effects additive. A lost update would show up as a balance
// that advanced by only one increment while two movements exist.
//
// Non-negative stock on the issue path is covered separately by
// Wwhn201PostIssueAtomicNonNegativeStockTest: the outbound path is PRODUCT-only
// at the database guard level and needs matched items with active RFID, which is
// a different fixture concern from the lock semantics proven here.
// ---------------------------------------------------------------------------
// Materialise the contended balance row first: the workers lock it by
// warehouse_id, so it must already exist for the race to be about that row.
$sharedSeed = buildReceiptFixture('SHARED-SEED', 2);
app(\Modules\Warehouse\Services\CurrentBaselineStockService::class)->postStockDocument(
    $sharedSeed['document_id'],
    'receipt',
    $sharedSeed['actor_id'],
    'wwhn219-shared-seed-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
    sha1('wwhn219-shared-seed')
);

$sharedFixtureA = buildReceiptFixture('SHARED-A', 4, $sharedSeed['warehouse_id'], $sharedSeed['sku_id']);
$sharedFixtureB = buildReceiptFixture('SHARED-B', 6, $sharedSeed['warehouse_id'], $sharedSeed['sku_id']);

$balanceBeforeShared = balanceSnapshot($sharedFixtureA['warehouse_id'], $sharedFixtureA['sku_id']);
$sharedRace = runRace('shared-balance-lost-update', [
    [
        'type' => 'post_receipt',
        'document_id' => $sharedFixtureA['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $sharedFixtureA['actor_id'],
        'idempotency_key' => 'wwhn219-shared-a-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn219-shared-a'),
        // Contend on the shared balance row, not on the two separate documents.
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $sharedFixtureA['warehouse_id'],
    ],
    [
        'type' => 'post_receipt',
        'document_id' => $sharedFixtureB['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $sharedFixtureB['actor_id'],
        'idempotency_key' => 'wwhn219-shared-b-'.(getenv('GITHUB_RUN_ID') ?: 'local'),
        'request_hash' => sha1('wwhn219-shared-b'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $sharedFixtureA['warehouse_id'],
    ],
], $runtimeDir, $holdMs);

$balanceAfterShared = balanceSnapshot($sharedFixtureA['warehouse_id'], $sharedFixtureA['sku_id']);
$sharedSuccesses = count(array_filter($sharedRace['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$expectedIncrease = $sharedFixtureA['qty'] + $sharedFixtureB['qty'];
$sharedMovementCount = DB::table('stock_movements')
    ->whereIn('stock_document_id', [$sharedFixtureA['document_id'], $sharedFixtureB['document_id']])
    ->count();

$sharedChecks = [
    'two_distinct_backend_connections' => $sharedRace['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $sharedRace['real_contention_proven'],
    'both_documents_committed' => $sharedSuccesses === 2,
    'no_lost_update' => ($balanceAfterShared['quantity'] - $balanceBeforeShared['quantity']) === $expectedIncrease,
    'both_movements_written' => $sharedMovementCount === 2,
    'balance_version_advanced_twice' => ($balanceAfterShared['version'] - $balanceBeforeShared['version']) === 2,
    'on_hand_matches_quantity' => $balanceAfterShared['on_hand_qty'] === $balanceAfterShared['quantity'],
];
$scenarios['shared_balance_lost_update'] = $sharedRace + [
    'fixtures' => [$sharedFixtureA, $sharedFixtureB],
    'balance_before' => $balanceBeforeShared,
    'balance_after' => $balanceAfterShared,
    'expected_increase' => $expectedIncrease,
    'movement_count' => $sharedMovementCount,
    'checks' => $sharedChecks,
    'passed' => ! in_array(false, $sharedChecks, true),
];
if (! $scenarios['shared_balance_lost_update']['passed']) {
    $failures[] = 'shared_balance_lost_update';
}

$summary = [
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-219',
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY',
    'note' => 'Each scenario spawns real OS processes with independent PostgreSQL backends; assertions cover ledger, balance and audit state, not HTTP status alone.',
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
