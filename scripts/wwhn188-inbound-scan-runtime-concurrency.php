<?php

declare(strict_types=1);

/**
 * WWHN-188 (HN-IN-002, BE1) runtime concurrency orchestrator — executed proof for
 * reviewer finding DEV188-P1-02 ("chạy race thật PostgreSQL bằng >=2 connection/
 * process cho same doc/version, same line và same item/different doc; xác nhận 1
 * winner, loser rollback/no side effect").
 *
 * Three scenarios, each spawning two real OS processes (two independent PostgreSQL
 * backends) that race `CurrentBaselineStockFlowController::inboundScan()`:
 *
 *  1. same_document_same_line   — both writers target the SAME line of the SAME
 *     document at the SAME initial version. Exactly one must win; the loser must be
 *     rejected with no side effect on scanned_qty/inbound_scan_entries.
 *  2. same_document_different_line — both writers read the SAME document at the
 *     SAME initial version but target two DIFFERENT lines. Proves the optimistic
 *     lock is document-level, not line-level: the loser is rejected even though its
 *     own line was never touched by the winner.
 *  3. same_item_different_document — the SAME serialized item is scanned into two
 *     DIFFERENT documents concurrently. Contention is on the `item_instances` row
 *     lock (`resolveItem()`), not the document row; exactly one writer must win,
 *     the other must be rejected by ITEM_ALREADY_RECEIVING with no scan entry.
 *
 * Pattern mirrors the already-accepted `scripts/wwhn219-runtime-concurrency.php`.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN188_EVIDENCE_DIR') ?: 'storage/app/wwhn-188-evidence');
$runtimeDir = $evidenceDir.'/runtime-concurrency';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-188 runtime concurrency evidence requires DB_CONNECTION=pgsql.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn188-inbound-scan-worker.php';
$holdMs = (int) (getenv('WWHN188_LOCK_HOLD_MS') ?: 1200);
$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function fixtureActorId(string $runToken): string
{
    $user = User::create([
        'username' => 'wwhn188-'.strtolower($runToken),
        'display_name' => 'WWHN-188 runtime concurrency fixture actor',
        'password_hash' => Hash::make('wwhn188-runtime-concurrency'),
        'status' => 'ACTIVE',
        'version' => 1,
    ]);

    return (string) $user->user_id;
}

function buildWarehouse(string $tag): string
{
    // uk_warehouses_single_active allows only one ACTIVE warehouse at a time —
    // reuse it (same convention as PreparesOutboundFixtures::warehouseFixture())
    // instead of inserting a second one, which would fail on rerun.
    $existing = (string) DB::table('warehouses')->where('status', 'ACTIVE')->value('id');
    if ($existing !== '') {
        return $existing;
    }

    $id = (string) Str::uuid();
    DB::table('warehouses')->insert([
        'id' => $id,
        'code' => 'WWHN188-'.$tag,
        'name' => 'WWHN-188 runtime concurrency warehouse '.$tag,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function buildProductSku(string $tag): string
{
    $id = (string) Str::uuid();
    DB::table('sku')->insert([
        'sku_id' => $id,
        'sku_code' => 'WWHN188-SKU-'.$tag,
        'sku_name' => 'WWHN-188 runtime concurrency SKU '.$tag,
        'sku_type' => 'PRODUCT',
        'unit' => 'cai',
        'is_serial_tracked' => true,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function buildReceivableItem(string $skuId, string $tag): string
{
    $id = (string) Str::uuid();
    DB::table('item_instances')->insert([
        'item_id' => $id,
        'sku_id' => $skuId,
        'serial_number' => 'WWHN188-SN-'.$tag,
        'item_status' => 'NEW',
        'item_condition' => 'NORMAL',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  array<int,array<string,int>>  $lines  list of ['qty_planned' => n] per line
 * @return array{document_id:string,line_ids:array<int,string>}
 */
function buildDraftDocument(string $warehouseId, string $skuId, string $actorId, string $tag, array $lines): array
{
    $documentId = (string) Str::uuid();
    $lineIds = [];

    DB::transaction(function () use ($documentId, $warehouseId, $skuId, $actorId, $tag, $lines, &$lineIds): void {
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => 'WWHN188-'.$tag.'-'.strtoupper(Str::random(6)),
            'doc_date' => now()->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => null,
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'WWHN-188 runtime concurrency fixture',
            'note' => 'Isolated fixture for DEV188-P1-02 executed concurrency evidence',
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            $lineId = (string) Str::uuid();
            $lineIds[] = $lineId;
            DB::table('stock_document_lines')->insert([
                'id' => $lineId,
                'stock_document_id' => $documentId,
                'line_no' => $index + 1,
                'product_id' => $skuId,
                'qty_planned' => $line['qty_planned'],
                'qty_actual' => $line['qty_planned'],
                'scanned_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    return ['document_id' => $documentId, 'line_ids' => $lineIds];
}

/**
 * Spawn one OS process per worker plan, release the barrier once every worker has
 * registered its backend pid, sample pg_locks while they contend, then collect results.
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
            fail("Could not spawn WWHN-188 concurrency worker {$index}.");
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
                 where a.application_name like 'wwhn188-worker-%'
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

$actorId = fixtureActorId($runToken);
$warehouseId = buildWarehouse($runToken);
$scenarios = [];
$failures = [];

// ---------------------------------------------------------------------------
// Scenario 1 — same document, same line, same initial version. Two different
// items race to fill the same 2-unit line. Exactly one must commit first.
// ---------------------------------------------------------------------------
$skuA = buildProductSku('S1-'.$runToken);
$itemA1 = buildReceivableItem($skuA, 'S1A-'.$runToken);
$itemA2 = buildReceivableItem($skuA, 'S1B-'.$runToken);
$docA = buildDraftDocument($warehouseId, $skuA, $actorId, 'S1', [['qty_planned' => 2]]);

$entriesBeforeS1 = DB::table('inbound_scan_entries')->where('inbound_line_id', $docA['line_ids'][0])->count();
$race1 = runRace('same_document_same_line', [
    [
        'document_id' => $docA['document_id'],
        'line_id' => $docA['line_ids'][0],
        'quantity' => 1,
        'item_id' => $itemA1,
        'if_match' => 1,
        'actor_id' => $actorId,
        'contended_table' => 'stock_documents',
        'contended_key' => 'id',
        'contended_value' => $docA['document_id'],
    ],
    [
        'document_id' => $docA['document_id'],
        'line_id' => $docA['line_ids'][0],
        'quantity' => 1,
        'item_id' => $itemA2,
        'if_match' => 1,
        'actor_id' => $actorId,
        'contended_table' => 'stock_documents',
        'contended_key' => 'id',
        'contended_value' => $docA['document_id'],
    ],
], $runtimeDir, $holdMs);

$successes1 = array_values(array_filter($race1['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejected1 = array_values(array_filter($race1['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$finalLineA = DB::table('stock_document_lines')->where('id', $docA['line_ids'][0])->first();
$finalDocA = DB::table('stock_documents')->where('id', $docA['document_id'])->first();
$entriesAfterS1 = DB::table('inbound_scan_entries')->where('inbound_line_id', $docA['line_ids'][0])->count();

$checks1 = [
    'two_distinct_backend_connections' => $race1['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race1['real_contention_proven'],
    'exactly_one_success' => count($successes1) === 1,
    'loser_rejected_with_stale_version' => count($rejected1) === 1 && ($rejected1[0]['error_code'] ?? null) === 'STALE_VERSION',
    'document_version_advanced_exactly_once' => (int) $finalDocA->version === 2,
    'scanned_qty_advanced_exactly_once' => (int) $finalLineA->scanned_qty === 1,
    'exactly_one_scan_entry_written' => ($entriesAfterS1 - $entriesBeforeS1) === 1,
];
$scenarios['same_document_same_line'] = $race1 + [
    'document_id' => $docA['document_id'],
    'line_id' => $docA['line_ids'][0],
    'final_document_version' => (int) $finalDocA->version,
    'final_scanned_qty' => (int) $finalLineA->scanned_qty,
    'checks' => $checks1,
    'passed' => ! in_array(false, $checks1, true),
];
if (! $scenarios['same_document_same_line']['passed']) {
    $failures[] = 'same_document_same_line';
}

// ---------------------------------------------------------------------------
// Scenario 2 — same document, same initial version, DIFFERENT lines. Proves
// the optimistic lock is document-level: the loser's own (untouched) line must
// still be rejected.
// ---------------------------------------------------------------------------
$skuB1 = buildProductSku('S2A-'.$runToken);
$skuB2 = buildProductSku('S2B-'.$runToken);
$itemB1 = buildReceivableItem($skuB1, 'S2A-'.$runToken);
$itemB2 = buildReceivableItem($skuB2, 'S2B-'.$runToken);
// Two-line document needs two distinct SKUs per line (product_id differs per line
// only if the SKU differs since each line pins a single product_id).
$docB = (function () use ($warehouseId, $skuB1, $skuB2, $actorId): array {
    $documentId = (string) Str::uuid();
    $lineIds = [];
    DB::transaction(function () use ($documentId, $warehouseId, $skuB1, $skuB2, $actorId, &$lineIds): void {
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => 'WWHN188-S2-'.strtoupper(Str::random(6)),
            'doc_date' => now()->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => null,
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'WWHN-188 runtime concurrency fixture',
            'note' => 'Isolated fixture for DEV188-P1-02 executed concurrency evidence',
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$skuB1, $skuB2] as $index => $sku) {
            $lineId = (string) Str::uuid();
            $lineIds[] = $lineId;
            DB::table('stock_document_lines')->insert([
                'id' => $lineId,
                'stock_document_id' => $documentId,
                'line_no' => $index + 1,
                'product_id' => $sku,
                'qty_planned' => 1,
                'qty_actual' => 1,
                'scanned_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    return ['document_id' => $documentId, 'line_ids' => $lineIds];
})();

$race2 = runRace('same_document_different_line', [
    [
        'document_id' => $docB['document_id'],
        'line_id' => $docB['line_ids'][0],
        'quantity' => 1,
        'item_id' => $itemB1,
        'if_match' => 1,
        'actor_id' => $actorId,
        'contended_table' => 'stock_documents',
        'contended_key' => 'id',
        'contended_value' => $docB['document_id'],
    ],
    [
        'document_id' => $docB['document_id'],
        'line_id' => $docB['line_ids'][1],
        'quantity' => 1,
        'item_id' => $itemB2,
        'if_match' => 1,
        'actor_id' => $actorId,
        'contended_table' => 'stock_documents',
        'contended_key' => 'id',
        'contended_value' => $docB['document_id'],
    ],
], $runtimeDir, $holdMs);

$successes2 = array_values(array_filter($race2['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejected2 = array_values(array_filter($race2['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$finalDocB = DB::table('stock_documents')->where('id', $docB['document_id'])->first();
$loserLine2 = $rejected2 === [] ? null : DB::table('stock_document_lines')->where('id', $docB['line_ids'][$rejected2[0]['worker_index']])->first();

$checks2 = [
    'two_distinct_backend_connections' => $race2['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race2['real_contention_proven'],
    'exactly_one_success' => count($successes2) === 1,
    'loser_rejected_with_stale_version' => count($rejected2) === 1 && ($rejected2[0]['error_code'] ?? null) === 'STALE_VERSION',
    'document_version_advanced_exactly_once' => (int) $finalDocB->version === 2,
    'losers_untouched_line_still_zero' => $loserLine2 !== null && (int) $loserLine2->scanned_qty === 0,
];
$scenarios['same_document_different_line'] = $race2 + [
    'document_id' => $docB['document_id'],
    'line_ids' => $docB['line_ids'],
    'final_document_version' => (int) $finalDocB->version,
    'checks' => $checks2,
    'passed' => ! in_array(false, $checks2, true),
];
if (! $scenarios['same_document_different_line']['passed']) {
    $failures[] = 'same_document_different_line';
}

// ---------------------------------------------------------------------------
// Scenario 3 — same item, two DIFFERENT documents. Contention is on the
// item_instances row lock inside resolveItem(), not the document row. Both
// documents start at their own version=1 (no version conflict between them);
// the guard that must fire is ITEM_ALREADY_RECEIVING.
// ---------------------------------------------------------------------------
$skuC = buildProductSku('S3-'.$runToken);
$sharedItem = buildReceivableItem($skuC, 'S3-'.$runToken);
$docC1 = buildDraftDocument($warehouseId, $skuC, $actorId, 'S3A', [['qty_planned' => 1]]);
$docC2 = buildDraftDocument($warehouseId, $skuC, $actorId, 'S3B', [['qty_planned' => 1]]);

$race3 = runRace('same_item_different_document', [
    [
        'document_id' => $docC1['document_id'],
        'line_id' => $docC1['line_ids'][0],
        'quantity' => 1,
        'item_id' => $sharedItem,
        'if_match' => 1,
        'actor_id' => $actorId,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $sharedItem,
    ],
    [
        'document_id' => $docC2['document_id'],
        'line_id' => $docC2['line_ids'][0],
        'quantity' => 1,
        'item_id' => $sharedItem,
        'if_match' => 1,
        'actor_id' => $actorId,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $sharedItem,
    ],
], $runtimeDir, $holdMs);

$successes3 = array_values(array_filter($race3['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejected3 = array_values(array_filter($race3['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$scanEntryCountForItem = DB::table('inbound_scan_entries')->where('item_id', $sharedItem)->count();

$checks3 = [
    'two_distinct_backend_connections' => $race3['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race3['real_contention_proven'],
    'exactly_one_success' => count($successes3) === 1,
    'loser_rejected_with_item_already_receiving' => count($rejected3) === 1 && ($rejected3[0]['error_code'] ?? null) === 'ITEM_ALREADY_RECEIVING',
    'exactly_one_scan_entry_for_shared_item' => $scanEntryCountForItem === 1,
];
$scenarios['same_item_different_document'] = $race3 + [
    'shared_item_id' => $sharedItem,
    'document_ids' => [$docC1['document_id'], $docC2['document_id']],
    'scan_entry_count_for_item' => $scanEntryCountForItem,
    'checks' => $checks3,
    'passed' => ! in_array(false, $checks3, true),
];
if (! $scenarios['same_item_different_document']['passed']) {
    $failures[] = 'same_item_different_document';
}

$summary = [
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-188',
    'reviewer_finding' => 'DEV188-P1-02',
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY',
    'note' => 'Each scenario spawns two real OS processes with independent PostgreSQL backends racing CurrentBaselineStockFlowController::inboundScan(); assertions cover version/scanned_qty/inbound_scan_entries state, not HTTP status alone.',
    'git_sha' => getenv('GITHUB_SHA') ?: null,
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
