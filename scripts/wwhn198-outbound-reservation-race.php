<?php

declare(strict_types=1);

/**
 * WWHN-198 / DEV198-P1-02R — orchestrator race reservation outbound trên PostgreSQL thật.
 *
 * Đóng đúng yêu cầu của register: "Chạy real multi-connection PostgreSQL race cho
 * same item/cross request; chứng minh một winner, một conflict, không double
 * ACTIVE match/effect và rollback sạch" + "index evidence + reconciliation".
 *
 * Ba kịch bản:
 *   1. cross_request_same_item — cùng một item vật lý, hai toa/hai phiếu xuất khác
 *      nhau, hai backend. Đúng một winner; loser 409 ITEM_ALREADY_MATCHED.
 *   2. same_request_same_item — cùng một dòng của cùng một phiếu, hai backend.
 *      Đúng một winner; loser 409 DUPLICATE_ITEM_MATCH (đây cũng là bằng chứng
 *      reachability cho DEV198-P2-01: guard này KHÔNG phải dead code, nó là hàng
 *      rào duy nhất khi hai request đồng thời cùng vượt qua tầng controller).
 *   3. unique_index_guard — bypass toàn bộ tầng application, hai backend insert
 *      thẳng match ACTIVE cho cùng item. PostgreSQL phải chặn bằng unique partial
 *      index (SQLSTATE 23505).
 *
 * Mỗi scenario chỉ PASS khi đủ bốn điều kiện:
 *   1. pg_backend_pid() khác nhau cho từng worker (connection thật sự tách rời),
 *   2. quan sát được lock wait / block time thật (contention không rỗng),
 *   3. đúng số lượng winner mong đợi,
 *   4. trạng thái đã commit: đúng 1 match ACTIVE, scanned_qty của loser = 0,
 *      tồn kho không đổi (scan stock-neutral), không có stock_movements.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN198_EVIDENCE_DIR') ?: 'storage/app/wwhn-198-evidence');
$runtimeDir = $evidenceDir.'/reservation-race';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-198 reservation race evidence requires DB_CONNECTION=pgsql.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn198-outbound-reservation-worker.php';
$holdMs = (int) (getenv('WWHN198_LOCK_HOLD_MS') ?: 1500);
$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function actorId(): string
{
    $id = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
        ?? DB::table('app_user')->where('username', 'admin')->value('user_id')
        ?? DB::table('app_user')->value('user_id');
    if ($id === null) {
        fail('WWHN-198 reservation race requires at least one app_user principal (AuthDatabaseSeeder).');
    }

    return (string) $id;
}

/** REQ-SRS-001 + uk_warehouses_single_active: chỉ được có một warehouse ACTIVE. */
function activeWarehouseId(): string
{
    $id = DB::table('warehouses')->where('status', 'ACTIVE')->value('id');
    if ($id === null) {
        fail('WWHN-198 reservation race requires an ACTIVE warehouse (business seeder).');
    }

    return (string) $id;
}

function productSku(string $tag): string
{
    $skuId = (string) Str::uuid();
    DB::table('sku')->insert([
        'sku_id' => $skuId,
        'sku_code' => 'WWHN198-'.$tag,
        'sku_name' => 'WWHN-198 reservation race SKU '.$tag,
        'sku_type' => 'PRODUCT',
        'unit' => 'cai',
        'is_serial_tracked' => true,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $skuId;
}

function seedBalance(string $warehouseId, string $skuId, int $qty): void
{
    DB::table('inventory_balances')->insert([
        'id' => (string) Str::uuid(),
        'warehouse_id' => $warehouseId,
        'product_id' => $skuId,
        'sku_id' => $skuId,
        'stock_status' => 'AVAILABLE',
        'quantity' => $qty,
        'on_hand_qty' => $qty,
        'available_qty' => $qty,
        'reserved_qty' => 0,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedItemInStock(string $skuId, string $tag): string
{
    $itemId = (string) Str::uuid();
    DB::table('item_instances')->insert([
        'item_id' => $itemId,
        'sku_id' => $skuId,
        'serial_number' => 'SN-WWHN198-'.$tag,
        'item_status' => 'IN_STOCK',
        'item_condition' => 'NORMAL',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $itemId;
}

/**
 * Dựng một phiếu xuất SCANNING sinh từ toa (§8.1: không có POST /outbound-documents
 * trực tiếp), đúng shape mà confirm toa tạo ra.
 *
 * @return array{document_id:string, line_id:string, request_id:string}
 */
function buildOutboundDocument(string $warehouseId, string $skuId, int $qty, string $tag): array
{
    global $runToken;

    $actor = actorId();
    $now = now();
    $importId = (string) Str::uuid();
    $checksum = hash('sha256', 'wwhn198:'.$tag.':'.$runToken);

    DB::table('outbound_request_imports')->insert([
        'id' => $importId,
        'import_no' => 'OBI-198-'.$runToken.'-'.$tag,
        'source_file_name' => 'wwhn198-race.xlsx',
        'source_checksum' => $checksum,
        'source_key' => 'CHECKSUM:'.$checksum,
        'status' => 'CONSUMED',
        'total_rows' => 1,
        'valid_rows' => 1,
        'error_rows' => 0,
        'consumed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
        'version' => 0,
    ]);

    $requestId = (string) Str::uuid();
    DB::table('outbound_requests')->insert([
        'id' => $requestId,
        'request_no' => 'TOA-198-'.$runToken.'-'.$tag,
        'import_id' => $importId,
        'source_checksum' => $checksum,
        'customer_name' => 'Đại lý race WWHN-198',
        'customer_address' => 'Race fixture',
        'recipient_name' => 'Đại lý race WWHN-198',
        'recipient_address' => 'Race fixture',
        'request_date' => $now->toDateString(),
        'status' => 'CONSUMED',
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $requestLineId = (string) Str::uuid();
    DB::table('outbound_request_lines')->insert([
        'id' => $requestLineId,
        'outbound_request_id' => $requestId,
        'line_no' => 1,
        'sku_id' => $skuId,
        'required_qty' => $qty,
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $documentId = (string) Str::uuid();
    $lineId = (string) Str::uuid();
    DB::transaction(function () use ($documentId, $lineId, $warehouseId, $skuId, $requestId, $requestLineId, $actor, $now, $tag, $qty): void {
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'issue',
            'doc_no' => 'PX-198-'.strtoupper(Str::random(8)).'-'.$tag,
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => $warehouseId,
            'dst_warehouse_id' => null,
            'outbound_request_id' => $requestId,
            'recipient_name' => 'Đại lý race WWHN-198',
            'recipient_address' => 'Race fixture',
            'version' => 1,
            'created_by' => $actor,
            'updated_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'outbound_request_line_id' => $requestLineId,
            'line_no' => 1,
            'product_id' => $skuId,
            'qty_planned' => $qty,
            'qty_actual' => $qty,
            'scanned_qty' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });

    return ['document_id' => $documentId, 'line_id' => $lineId, 'request_id' => $requestId];
}

function balanceSnapshot(string $warehouseId, string $skuId): array
{
    $row = DB::table('inventory_balances')
        ->where('warehouse_id', $warehouseId)
        ->where('sku_id', $skuId)
        ->where('stock_status', 'AVAILABLE')
        ->first();

    return [
        'quantity' => (int) ($row->quantity ?? 0),
        'on_hand_qty' => (int) ($row->on_hand_qty ?? 0),
        'available_qty' => (int) ($row->available_qty ?? 0),
        'reserved_qty' => (int) ($row->reserved_qty ?? 0),
        'version' => (int) ($row->version ?? 0),
    ];
}

function activeMatchCount(string $itemId): int
{
    return DB::table('outbound_item_matches')
        ->where('item_id', $itemId)
        ->where(function ($query): void {
            $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
        })
        ->count();
}

/**
 * Chạy một race: spawn N process thật, thả đồng thời qua filesystem barrier, lấy
 * mẫu pg_locks/pg_stat_activity từ một connection monitor độc lập.
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

        $descriptors = [
            1 => ['file', $scenarioDir.'/worker-'.$index.'.stdout', 'w'],
            2 => ['file', $scenarioDir.'/worker-'.$index.'.stderr', 'w'],
        ];
        $process = proc_open([$phpBinary, $workerScript, $planFile], $descriptors, $pipes);
        if (! is_resource($process)) {
            fail("Could not spawn WWHN-198 reservation race worker {$index}.");
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
                 where a.application_name like 'wwhn198-worker-%'
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

$warehouseId = activeWarehouseId();
$actor = actorId();
$scenarios = [];
$failures = [];

// ---------------------------------------------------------------------------
// Scenario 1 — same item, cross request/cross document.
// ---------------------------------------------------------------------------
$skuA = productSku('CROSS-'.$runToken);
seedBalance($warehouseId, $skuA, 2);
$itemA = seedItemInStock($skuA, 'CROSS-'.$runToken);
$docA = buildOutboundDocument($warehouseId, $skuA, 1, 'CROSSA');
$docB = buildOutboundDocument($warehouseId, $skuA, 1, 'CROSSB');

$balanceBefore = balanceSnapshot($warehouseId, $skuA);
$race = runRace('cross-request-same-item', [
    [
        'type' => 'outbound_scan',
        'document_id' => $docA['document_id'],
        'line_id' => $docA['line_id'],
        'item_id' => $itemA,
        'actor_id' => $actor,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $itemA,
    ],
    [
        'type' => 'outbound_scan',
        'document_id' => $docB['document_id'],
        'line_id' => $docB['line_id'],
        'item_id' => $itemA,
        'actor_id' => $actor,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $itemA,
    ],
], $runtimeDir, $holdMs);
$balanceAfter = balanceSnapshot($warehouseId, $skuA);

$successes = array_values(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejected = array_values(array_filter($race['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$loserDocumentIds = array_map(static fn (array $w): string => (string) $w['document_id'], $rejected);
$loserScannedQty = $loserDocumentIds === [] ? null : (int) DB::table('stock_document_lines')
    ->whereIn('stock_document_id', $loserDocumentIds)
    ->sum('scanned_qty');
$movementCount = DB::table('stock_movements')
    ->whereIn('stock_document_id', [$docA['document_id'], $docB['document_id']])
    ->count();

$checks = [
    'two_distinct_backend_connections' => $race['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race['real_contention_proven'],
    'exactly_one_winner' => count($successes) === 1,
    'exactly_one_conflict' => count($rejected) === 1,
    'conflict_is_item_already_matched' => ($rejected[0]['error_code'] ?? null) === 'ITEM_ALREADY_MATCHED',
    'conflict_http_409' => ((int) ($rejected[0]['http_status'] ?? 0)) === 409,
    'no_double_active_match' => activeMatchCount($itemA) === 1,
    'loser_rolled_back_clean' => $loserScannedQty === 0,
    'scan_is_stock_neutral' => $balanceAfter === $balanceBefore,
    'no_stock_movement_created' => $movementCount === 0,
];
$scenarios['cross_request_same_item'] = $race + [
    'fixture' => ['sku_id' => $skuA, 'item_id' => $itemA, 'document_a' => $docA, 'document_b' => $docB],
    'balance_before' => $balanceBefore,
    'balance_after' => $balanceAfter,
    'active_match_count' => activeMatchCount($itemA),
    'loser_scanned_qty' => $loserScannedQty,
    'movement_count' => $movementCount,
    'checks' => $checks,
    'passed' => ! in_array(false, $checks, true),
];
if (! $scenarios['cross_request_same_item']['passed']) {
    $failures[] = 'cross_request_same_item';
}

// ---------------------------------------------------------------------------
// Scenario 2 — same item, same request/same line (dedup trong cùng phiếu).
// Đây là bằng chứng reachability của DUPLICATE_ITEM_MATCH (DEV198-P2-01).
// ---------------------------------------------------------------------------
$skuB = productSku('SAME-'.$runToken);
seedBalance($warehouseId, $skuB, 2);
$itemB = seedItemInStock($skuB, 'SAME-'.$runToken);
$docC = buildOutboundDocument($warehouseId, $skuB, 2, 'SAMEC');

$balanceBeforeSame = balanceSnapshot($warehouseId, $skuB);
$sameRace = runRace('same-request-same-item', [
    [
        'type' => 'outbound_scan',
        'document_id' => $docC['document_id'],
        'line_id' => $docC['line_id'],
        'item_id' => $itemB,
        'actor_id' => $actor,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $itemB,
    ],
    [
        'type' => 'outbound_scan',
        'document_id' => $docC['document_id'],
        'line_id' => $docC['line_id'],
        'item_id' => $itemB,
        'actor_id' => $actor,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $itemB,
    ],
], $runtimeDir, $holdMs);
$balanceAfterSame = balanceSnapshot($warehouseId, $skuB);

$sameSuccesses = array_values(array_filter($sameRace['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$sameRejected = array_values(array_filter($sameRace['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$sameScannedQty = (int) DB::table('stock_document_lines')->where('id', $docC['line_id'])->value('scanned_qty');

$sameChecks = [
    'two_distinct_backend_connections' => $sameRace['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $sameRace['real_contention_proven'],
    'exactly_one_winner' => count($sameSuccesses) === 1,
    'exactly_one_conflict' => count($sameRejected) === 1,
    'conflict_is_duplicate_item_match' => ($sameRejected[0]['error_code'] ?? null) === 'DUPLICATE_ITEM_MATCH',
    'conflict_http_409' => ((int) ($sameRejected[0]['http_status'] ?? 0)) === 409,
    'no_double_active_match' => activeMatchCount($itemB) === 1,
    'scanned_qty_counted_once' => $sameScannedQty === 1,
    'scan_is_stock_neutral' => $balanceAfterSame === $balanceBeforeSame,
];
$scenarios['same_request_same_item'] = $sameRace + [
    'fixture' => ['sku_id' => $skuB, 'item_id' => $itemB, 'document' => $docC],
    'balance_before' => $balanceBeforeSame,
    'balance_after' => $balanceAfterSame,
    'active_match_count' => activeMatchCount($itemB),
    'scanned_qty' => $sameScannedQty,
    'checks' => $sameChecks,
    'passed' => ! in_array(false, $sameChecks, true),
];
if (! $scenarios['same_request_same_item']['passed']) {
    $failures[] = 'same_request_same_item';
}

// ---------------------------------------------------------------------------
// Scenario 3 — unique partial index là hàng rào cuối cùng (bypass application).
// ---------------------------------------------------------------------------
$skuC = productSku('IDX-'.$runToken);
seedBalance($warehouseId, $skuC, 2);
$itemC = seedItemInStock($skuC, 'IDX-'.$runToken);
$docD = buildOutboundDocument($warehouseId, $skuC, 1, 'IDXD');
$docE = buildOutboundDocument($warehouseId, $skuC, 1, 'IDXE');

$indexDefinitions = array_map(
    static fn ($row): array => ['index' => (string) $row->indexname, 'definition' => (string) $row->indexdef],
    DB::select(
        "select indexname, indexdef from pg_indexes
         where tablename in ('outbound_item_matches','inbound_scan_entries')
           and indexdef ilike '%unique%' and indexdef ilike '%ACTIVE%'
         order by indexname"
    )
);

$idxRace = runRace('unique-index-guard', [
    [
        'type' => 'raw_active_match_insert',
        'document_id' => $docD['document_id'],
        'line_id' => $docD['line_id'],
        'item_id' => $itemC,
        'actor_id' => $actor,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $itemC,
    ],
    [
        'type' => 'raw_active_match_insert',
        'document_id' => $docE['document_id'],
        'line_id' => $docE['line_id'],
        'item_id' => $itemC,
        'actor_id' => $actor,
        'contended_table' => 'item_instances',
        'contended_key' => 'item_id',
        'contended_value' => $itemC,
    ],
], $runtimeDir, $holdMs);

$idxSuccesses = array_values(array_filter($idxRace['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$idxBlocked = array_values(array_filter($idxRace['workers'], static fn (array $w): bool => $w['outcome'] === 'DB_CONSTRAINT_REJECTED'));

$idxChecks = [
    'two_distinct_backend_connections' => $idxRace['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $idxRace['real_contention_proven'],
    'partial_unique_index_present' => $indexDefinitions !== [],
    'exactly_one_winner' => count($idxSuccesses) === 1,
    'loser_blocked_by_database' => count($idxBlocked) === 1,
    'loser_sqlstate_is_unique_violation' => ($idxBlocked[0]['sqlstate'] ?? null) === '23505',
    'no_double_active_match' => activeMatchCount($itemC) === 1,
];
$scenarios['unique_index_guard'] = $idxRace + [
    'fixture' => ['sku_id' => $skuC, 'item_id' => $itemC, 'document_d' => $docD, 'document_e' => $docE],
    'index_definitions' => $indexDefinitions,
    'active_match_count' => activeMatchCount($itemC),
    'checks' => $idxChecks,
    'passed' => ! in_array(false, $idxChecks, true),
];
if (! $scenarios['unique_index_guard']['passed']) {
    $failures[] = 'unique_index_guard';
}

$summary = [
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-198',
    'register_item' => 'DEV198-P1-02R',
    'parent' => 'WWHN-46 / HN-OUT-002',
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY',
    'note' => 'Mỗi scenario spawn OS process thật với backend PostgreSQL độc lập; assertion phủ match ACTIVE, scanned_qty, tồn kho và stock_movements chứ không chỉ HTTP status.',
    'git_sha' => getenv('GITHUB_SHA') ?: trim((string) @shell_exec('git rev-parse HEAD')) ?: null,
    'github_run_id' => getenv('GITHUB_RUN_ID') ?: null,
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'database' => (string) DB::selectOne('select current_database() as d')->d,
    'lock_hold_ms' => $holdMs,
    'run_token' => $runToken,
    'scenarios' => $scenarios,
    'failed_scenarios' => $failures,
    'status' => $failures === [] ? 'PASS' : 'FAIL',
];

file_put_contents(
    $evidenceDir.'/reservation-race-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode([
    'status' => $summary['status'],
    'failed_scenarios' => $failures,
    'scenarios' => array_map(static fn (array $s): array => ['passed' => $s['passed'], 'checks' => $s['checks']], $scenarios),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($failures === [] ? 0 : 1);
