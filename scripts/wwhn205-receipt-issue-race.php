<?php

declare(strict_types=1);

/**
 * WWHN-205 / WWHN-214 — P1-03R: Receipt/Issue concurrency, EXECUTED on PostgreSQL.
 *
 * Vòng review trước chấm 70/100 vì mục "Receipt/Issue concurrency" chỉ có lời văn và
 * test SQLite ":memory:" (một connection, một process — không thể có race thật).
 * Register mới đòi đúng bốn thứ, và script này sinh bằng chứng cho từng thứ:
 *
 *   1. "≥2 request/process thực sự đồng thời trên PostgreSQL"
 *      -> mỗi worker là một OS process riêng (proc_open) với pg_backend_pid() riêng,
 *         đồng bộ qua file barrier nên tất cả cùng lao vào một row lock.
 *   2. "competing Issue và Receipt scenarios"
 *      -> S1 Receipt vs Issue trên CÙNG một inventory_balances row (cross-direction),
 *         S2 Receipt + 2 Issue tranh nhau cùng một số dư (mixed oversell),
 *         S3 hai process cùng Post MỘT chứng từ receipt (no-double-movement).
 *         Bộ WWHN-201 trước đó chỉ có Issue×Issue — đây là phần còn thiếu.
 *   3. "verify lock behavior, no-negative, no-double movement, no drift"
 *      -> lock behavior: pg_locks/pg_stat_activity được lấy mẫu trong lúc đua;
 *         no-negative: mọi qty_before/qty_after của mọi movement đều >= 0;
 *         no-double-movement: business_key duy nhất, số movement == số worker SUCCESS;
 *         no-drift: multiset {qty_before} ∪ {final} == {qty_after} ∪ {initial}
 *         (path-consistency) — bắt được lost update mà tổng delta không bắt được.
 *   4. "post-race reconcile"
 *      -> sau khi mọi scenario chạy xong, đối soát lại ledger==balance trên mọi
 *         dimension (warehouse, sku, stock_status) đã bị chạm.
 *
 * Chạy:
 *   DB_CONNECTION=pgsql ... php scripts/wwhn205-receipt-issue-race.php [evidenceDir]
 *
 * Exit code 0 = mọi scenario PASS; 1 = có scenario FAIL; 2 = sai môi trường.
 * Script KHÔNG tự nhận "đã verify" — nó chỉ ghi ra dữ liệu thô để QA1 đọc lại.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN205_EVIDENCE_DIR') ?: 'docs/evidence/WWHN-205/runtime');
$runtimeDir = $evidenceDir.'/receipt-issue-race';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-205 receipt/issue race evidence requires DB_CONNECTION=pgsql (SQLite :memory: không thể có 2 connection).\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
// Tái dùng nguyên worker đã được chấp nhận ở WWHN-219/201 (một process, một
// backend, barrier, hold lock) — không viết worker thứ hai để tránh hai định
// nghĩa "thế nào là race" trôi khỏi nhau.
$workerScript = __DIR__.'/wwhn219-concurrency-worker.php';
$holdMs = (int) (getenv('WWHN205_LOCK_HOLD_MS') ?: 1500);
$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

/** Chỉ ghi các cột thực sự tồn tại — schema m1 đang được nhiều nhánh sửa song song. */
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
        fail('WWHN-205 race cần principal chuẩn keeper01/admin của AuthDatabaseSeeder.');
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
        'code' => 'WWHN205-'.$tag,
        'name' => 'WWHN-205 race warehouse '.$tag,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $warehouseId;
}

function ensureProductSku(string $tag): string
{
    $skuId = (string) Str::uuid();
    insertRow('sku', [
        'sku_id' => $skuId,
        'sku_code' => 'WWHN205-SKU-'.$tag,
        'sku_name' => 'WWHN-205 race SKU '.$tag,
        'sku_type' => 'PRODUCT',
        'unit' => 'cai',
        'is_serial_tracked' => true,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $skuId;
}

/**
 * Chứng từ ISSUE đã scan đủ (scanned_qty == qty_planned) với đúng qty item match.
 * Trigger hn_guard_stock_line_sku_scope() chặn SKU không phải PRODUCT trên dòng
 * issue, nên assertMatchedProductRfids() nằm trong phạm vi: mỗi đơn vị qty phải có
 * đúng một outbound_item_matches ACTIVE.
 */
function buildIssueFixture(string $tag, int $qty, string $warehouseId, string $skuId, ?array $itemIds = null): array
{
    global $runToken;

    $actor = actorId();
    $now = now();
    $tag = $tag.'-'.$runToken;
    $documentId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    if ($itemIds !== null && count($itemIds) !== $qty) {
        fail("Fixture issue {$tag}: nhận ".count($itemIds)." item cho qty {$qty}.");
    }

    DB::transaction(function () use ($documentId, $lineId, $warehouseId, $skuId, $actor, $now, $tag, $qty, $itemIds): void {
        insertRow('stock_documents', [
            'id' => $documentId,
            'doc_type' => 'issue',
            'doc_no' => 'WWHN205-I-'.$tag.'-'.strtoupper(Str::random(5)),
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => $warehouseId,
            'dst_warehouse_id' => null,
            'note' => 'WWHN-205 executed receipt/issue race fixture',
            'recipient_name' => 'WWHN-205 race recipient '.$tag,
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
            'qty_planned' => $qty,
            'qty_actual' => $qty,
            'scanned_qty' => $qty,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        for ($i = 0; $i < $qty; $i++) {
            // Mặc định (itemIds = null) tạo item mới: dùng cho kịch bản oversell CỐ Ý,
            // nơi tổng nhu cầu vượt số dư. Khi truyền itemIds, fixture TIÊU THỤ đúng
            // các item IN_STOCK đang backing số dư mở đầu — nhờ đó serial subledger
            // (SRS §7.5) vẫn khớp sau khi đua, thay vì lệch vì lỗi của chính fixture.
            $itemId = $itemIds === null ? (string) Str::uuid() : (string) $itemIds[$i];
            if ($itemIds === null) {
                insertRow('item_instances', [
                    'item_id' => $itemId,
                    'sku_id' => $skuId,
                    'serial_number' => 'WWHN205-SN-I-'.$tag.'-'.$i,
                    'item_status' => 'IN_STOCK',
                    'item_condition' => 'NORMAL',
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            insertRow('outbound_item_matches', [
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
        'kind' => 'issue',
        'document_id' => $documentId,
        'line_id' => $lineId,
        'warehouse_id' => $warehouseId,
        'sku_id' => $skuId,
        'qty' => $qty,
        'actor_id' => $actor,
    ];
}

/**
 * Chứng từ RECEIPT đã scan đủ. postStockDocument() dùng dst_warehouse_id cho
 * receipt; inbound_scan_entries là nguồn cho resolveLineItemId() (fix của
 * WWHN-205) và applyReceiptSubledger().
 */
function buildReceiptFixture(string $tag, int $qty, string $warehouseId, string $skuId): array
{
    global $runToken;

    $actor = actorId();
    $now = now();
    $tag = $tag.'-'.$runToken;
    $documentId = (string) Str::uuid();
    $lineId = (string) Str::uuid();
    $itemIds = [];

    DB::transaction(function () use ($documentId, $lineId, $warehouseId, $skuId, $actor, $now, $tag, $qty, &$itemIds): void {
        insertRow('stock_documents', [
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => 'WWHN205-R-'.$tag.'-'.strtoupper(Str::random(5)),
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => null,
            'dst_warehouse_id' => $warehouseId,
            'note' => 'WWHN-205 executed receipt/issue race fixture',
            'source_name' => 'WWHN-205 race source '.$tag,
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
            'qty_planned' => $qty,
            'qty_actual' => $qty,
            'scanned_qty' => $qty,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        for ($i = 0; $i < $qty; $i++) {
            $itemId = (string) Str::uuid();
            $itemIds[] = $itemId;
            insertRow('item_instances', [
                'item_id' => $itemId,
                'sku_id' => $skuId,
                'serial_number' => 'WWHN205-SN-R-'.$tag.'-'.$i,
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

    return [
        'kind' => 'receipt',
        'document_id' => $documentId,
        'line_id' => $lineId,
        'warehouse_id' => $warehouseId,
        'sku_id' => $skuId,
        'qty' => $qty,
        'item_ids' => $itemIds,
        'actor_id' => $actor,
    ];
}

/**
 * Số dư mở đầu PHẢI đi kèm một movement OPENING.
 *
 * Nếu chỉ insert thẳng inventory_balances thì fixture tự tạo ra một khoản lệch
 * ledger↔balance đúng bằng số dư mở đầu, và phép "post-race reconcile" ở cuối sẽ
 * đỏ vì lỗi của fixture chứ không phải của sản phẩm — đúng loại bẫy làm bằng chứng
 * mất giá trị. DEC-INV-001 coi stock_movements là source of truth, nên mọi số dư
 * đều phải truy được về ít nhất một movement. 'OPENING' là một trong năm giá trị
 * hợp lệ của chk_stock_movement_type_current.
 */
function seedBalance(string $warehouseId, string $skuId, int $quantity): array
{
    global $runToken;

    insertRow('inventory_balances', [
        'id' => (string) Str::uuid(),
        'warehouse_id' => $warehouseId,
        'product_id' => $skuId,
        'sku_id' => $skuId,
        'stock_status' => 'AVAILABLE',
        'quantity' => $quantity,
        'on_hand_qty' => $quantity,
        'available_qty' => $quantity,
        'reserved_qty' => 0,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($quantity <= 0) {
        // chk_stock_movement_positive_quantity: quantity > 0. Số dư mở đầu bằng 0
        // không cần movement nào để ledger vẫn khớp.
        return [];
    }

    insertRow('stock_movements', [
        'id' => (string) Str::uuid(),
        'movement_no' => 'MV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
        'business_key' => sha1('OPENING|'.$warehouseId.'|'.$skuId.'|'.$runToken),
        'movement_type' => 'OPENING',
        'warehouse_id' => $warehouseId,
        'product_id' => $skuId,
        'sku_id' => $skuId,
        'stock_status' => 'AVAILABLE',
        'quantity' => $quantity,
        'qty_before' => 0,
        'qty_delta' => $quantity,
        'qty_after' => $quantity,
        'source_type' => 'OPENING_BALANCE',
        'source_id' => $skuId,
        'created_by' => actorId(),
        'occurred_at' => now(),
        'created_at' => now(),
    ]);

    // SRS §7.5 Serial subledger: "Balance available = count eligible Item_Instance
    // IN_STOCK". Số dư mở đầu vì vậy phải có item thật đứng sau, nếu không chính
    // fixture tạo ra một khoản lệch subledger rồi đổ cho sản phẩm.
    $openingItemIds = [];
    for ($i = 0; $i < $quantity; $i++) {
        $itemId = (string) Str::uuid();
        $openingItemIds[] = $itemId;
        insertRow('item_instances', [
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'serial_number' => 'WWHN205-SN-OPEN-'.$runToken.'-'.substr($skuId, 0, 8).'-'.$i,
            'item_status' => 'IN_STOCK',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $openingItemIds;
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

/** Barrier + spawn + lấy mẫu pg_locks — cùng một orchestration đã được duyệt ở WWHN-219/201. */
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
            fail("Không spawn được worker {$index} của {$scenario}.");
        }
        $processes[$index] = $process;
    }

    $expected = count($workerPlans);
    $deadline = microtime(true) + 120.0;
    while (count(glob($barrierDir.'/ready-*') ?: []) < $expected) {
        if (microtime(true) > $deadline) {
            fail("Worker không tới được barrier của {$scenario}.");
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
            fail("Worker {$index} của {$scenario} không sinh file kết quả.");
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

    // Overlap thật: worker thắng còn đang giữ lock trong khi worker thua đã được
    // release khỏi barrier — chứng minh critical section chồng nhau theo wall-clock.
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

/**
 * Ledger của đúng một dimension: mọi movement chạm (warehouse, sku, AVAILABLE).
 * Trả về cả chuỗi qty_before/qty_after để kiểm path-consistency (no drift).
 */
function ledgerRows(string $warehouseId, string $skuId, bool $excludeOpening = true): array
{
    return DB::table('stock_movements')
        ->where('warehouse_id', $warehouseId)
        ->where(function ($q) use ($skuId): void {
            $q->where('sku_id', $skuId)->orWhere('product_id', $skuId);
        })
        ->where('stock_status', 'AVAILABLE')
        // Số dư mở đầu do fixture dựng nằm NGOÀI cuộc đua: snapshot `before` đã
        // tính nó rồi, nên đưa vào path-consistency sẽ đếm hai lần.
        ->when($excludeOpening, fn ($q) => $q->where('movement_type', '!=', 'OPENING'))
        ->orderBy('occurred_at')
        ->get(['id', 'business_key', 'movement_type', 'quantity', 'qty_before', 'qty_delta', 'qty_after', 'item_id', 'stock_document_id', 'occurred_at'])
        ->map(static fn ($m): array => (array) $m)
        ->all();
}

/**
 * No-drift theo nghĩa mạnh. Tổng delta khớp là điều kiện CẦN nhưng chưa ĐỦ: hai
 * lost update đối xứng vẫn cho tổng đúng. Path-consistency thì không — nếu một
 * writer đọc số dư cũ (lost update) thì sẽ có một qty_before lặp lại và multiset
 * hai vế lệch nhau ngay.
 */
function pathConsistency(array $rows, int $initial, int $final): array
{
    $befores = array_map(static fn (array $r): int => (int) $r['qty_before'], $rows);
    $afters = array_map(static fn (array $r): int => (int) $r['qty_after'], $rows);

    $left = $befores;
    $left[] = $final;
    $right = $afters;
    $right[] = $initial;
    sort($left);
    sort($right);

    $deltaConsistent = true;
    foreach ($rows as $row) {
        if ((int) $row['qty_after'] !== (int) $row['qty_before'] + (int) $row['qty_delta']) {
            $deltaConsistent = false;
            break;
        }
    }

    return [
        'edge_multiset_matches' => $left === $right,
        'per_row_delta_consistent' => $deltaConsistent,
        'sum_delta' => array_sum(array_map(static fn (array $r): int => (int) $r['qty_delta'], $rows)),
        'expected_sum_delta' => $final - $initial,
        'no_negative_intermediate' => ! in_array(true, array_map(
            static fn (array $r): bool => (int) $r['qty_before'] < 0 || (int) $r['qty_after'] < 0,
            $rows
        ), true),
    ];
}

$scenarios = [];
$failures = [];
$touchedDimensions = [];

// ---------------------------------------------------------------------------
// S1 — Receipt vs Issue tranh CÙNG một inventory_balances row (cross-direction).
// Đây chính là kịch bản register đòi mà WWHN-201 không có: hai chiều ngược nhau,
// cả hai đều HỢP LỆ nên cả hai PHẢI thành công, và kết quả phải độc lập thứ tự.
// Số dư đầu 5, receipt +4, issue -3 => 6 dù ai thắng lock trước.
// ---------------------------------------------------------------------------
$warehouseId = ensureWarehouse('S1');
$skuS1 = ensureProductSku('S1-'.$runToken);
$openingS1 = seedBalance($warehouseId, $skuS1, 5);
$touchedDimensions[] = ['warehouse_id' => $warehouseId, 'sku_id' => $skuS1];

$receiptS1 = buildReceiptFixture('S1R', 4, $warehouseId, $skuS1);
// Issue TIÊU THỤ 3 trong 5 item mở đầu (không tạo serial mới), nên sau đua
// serial subledger vẫn khớp: IN_STOCK = 5 - 3 + 4 = 6 = số dư cuối.
$issueS1 = buildIssueFixture('S1I', 3, $warehouseId, $skuS1, array_slice($openingS1, 0, 3));
$beforeS1 = balanceSnapshot($warehouseId, $skuS1);

$raceS1 = runRace('s1-competing-receipt-and-issue-same-balance', [
    [
        'type' => 'post_receipt',
        'document_id' => $receiptS1['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $receiptS1['actor_id'],
        'idempotency_key' => 'wwhn205-s1-receipt-'.$runToken,
        'request_hash' => sha1('wwhn205-s1-receipt'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $warehouseId,
    ],
    [
        'type' => 'post_issue',
        'document_id' => $issueS1['document_id'],
        'document_type' => 'issue',
        'actor_id' => $issueS1['actor_id'],
        'idempotency_key' => 'wwhn205-s1-issue-'.$runToken,
        'request_hash' => sha1('wwhn205-s1-issue'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $warehouseId,
    ],
], $runtimeDir, $holdMs);

$afterS1 = balanceSnapshot($warehouseId, $skuS1);
$rowsS1 = ledgerRows($warehouseId, $skuS1);
$successS1 = count(array_filter($raceS1['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$pathS1 = pathConsistency($rowsS1, $beforeS1['quantity'], $afterS1['quantity']);
$businessKeysS1 = array_map(static fn (array $r): string => (string) $r['business_key'], $rowsS1);

$checksS1 = [
    'two_distinct_backend_connections' => $raceS1['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $raceS1['real_contention_proven'],
    'both_directions_succeeded' => $successS1 === 2,
    'exactly_two_movements' => count($rowsS1) === 2,
    'no_duplicate_business_key' => count($businessKeysS1) === count(array_unique($businessKeysS1)),
    'final_balance_order_independent' => $afterS1['quantity'] === $beforeS1['quantity'] + 4 - 3,
    'balance_version_bumped_once_per_writer' => $afterS1['version'] === $beforeS1['version'] + 2,
    'no_lost_update_path_consistent' => $pathS1['edge_multiset_matches'] && $pathS1['per_row_delta_consistent'],
    'ledger_sum_equals_balance_delta' => $pathS1['sum_delta'] === $pathS1['expected_sum_delta'],
    'balance_never_negative' => $pathS1['no_negative_intermediate'] && $afterS1['quantity'] >= 0 && $afterS1['available_qty'] >= 0,
    // WWHN-205 §2: line gộp nhiều item thì item_id PHẢI null (một movement không
    // thể tự quy về một trong bốn item). Đây là bất biến của chính fix, nên đua
    // xong vẫn phải đúng — không được vì contention mà gán bừa một item_id.
    'aggregated_line_keeps_item_id_null' => count(array_filter(
        $rowsS1,
        static fn (array $r): bool => $r['item_id'] !== null
    )) === 0,
];
$scenarios['s1_competing_receipt_and_issue_same_balance'] = $raceS1 + [
    'intent' => 'Receipt và Issue ngược chiều trên cùng một số dư: cả hai hợp lệ nên cả hai phải thành công, kết quả độc lập thứ tự thắng lock.',
    'fixtures' => [$receiptS1, $issueS1],
    'balance_before' => $beforeS1,
    'balance_after' => $afterS1,
    'ledger_rows' => $rowsS1,
    'path_consistency' => $pathS1,
    'checks' => $checksS1,
    'passed' => ! in_array(false, $checksS1, true),
];
if (! $scenarios['s1_competing_receipt_and_issue_same_balance']['passed']) {
    $failures[] = 's1_competing_receipt_and_issue_same_balance';
}

// ---------------------------------------------------------------------------
// S2 — Mixed oversell: Receipt(+5) + Issue(5) + Issue(5) trên số dư 5.
// Kết quả phụ thuộc thứ tự thắng lock (0 hoặc 5), nên mọi assertion ở đây phải
// độc lập thứ tự. Điều PHẢI đúng trong MỌI interleaving: không bao giờ âm, số
// movement bằng đúng số worker SUCCESS, số dư cuối bằng đúng tổng delta, receipt
// không bao giờ bị từ chối vì thiếu tồn, issue thua bị chặn bằng INSUFFICIENT_*.
// ---------------------------------------------------------------------------
$skuS2 = ensureProductSku('S2-'.$runToken);
$openingS2 = seedBalance($warehouseId, $skuS2, 5);
$touchedDimensions[] = ['warehouse_id' => $warehouseId, 'sku_id' => $skuS2];

$receiptS2 = buildReceiptFixture('S2R', 5, $warehouseId, $skuS2);
// issueA tiêu thụ đúng 5 item mở đầu. issueB CỐ Ý tạo serial mới: đây là kịch bản
// oversell — tổng nhu cầu (10) vượt số dư (5). Hệ quả có chủ đích: riêng SKU này,
// serial subledger §7.5 sẽ lệch sau đua, vì oversell theo đúng định nghĩa là "sổ nói
// ít hơn số hàng có mặt". Với SKU serial-tracked mà subledger khớp tuyệt đối thì
// oversell KHÔNG THỂ xảy ra, nên không có cách nào vừa giữ kịch bản này vừa giữ
// subledger khớp. Phần post-race vì vậy assert lệch đó bị GIAM đúng trong SKU này.
$issueS2a = buildIssueFixture('S2IA', 5, $warehouseId, $skuS2, $openingS2);
$issueS2b = buildIssueFixture('S2IB', 5, $warehouseId, $skuS2);
$beforeS2 = balanceSnapshot($warehouseId, $skuS2);

$raceS2 = runRace('s2-mixed-receipt-and-two-issues-non-negative', [
    [
        'type' => 'post_receipt',
        'document_id' => $receiptS2['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $receiptS2['actor_id'],
        'idempotency_key' => 'wwhn205-s2-receipt-'.$runToken,
        'request_hash' => sha1('wwhn205-s2-receipt'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $warehouseId,
    ],
    [
        'type' => 'post_issue',
        'document_id' => $issueS2a['document_id'],
        'document_type' => 'issue',
        'actor_id' => $issueS2a['actor_id'],
        'idempotency_key' => 'wwhn205-s2-issue-a-'.$runToken,
        'request_hash' => sha1('wwhn205-s2-issue-a'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $warehouseId,
    ],
    [
        'type' => 'post_issue',
        'document_id' => $issueS2b['document_id'],
        'document_type' => 'issue',
        'actor_id' => $issueS2b['actor_id'],
        'idempotency_key' => 'wwhn205-s2-issue-b-'.$runToken,
        'request_hash' => sha1('wwhn205-s2-issue-b'),
        'contended_table' => 'inventory_balances',
        'contended_key' => 'warehouse_id',
        'contended_value' => $warehouseId,
    ],
], $runtimeDir, $holdMs);

$afterS2 = balanceSnapshot($warehouseId, $skuS2);
$rowsS2 = ledgerRows($warehouseId, $skuS2);
$pathS2 = pathConsistency($rowsS2, $beforeS2['quantity'], $afterS2['quantity']);
$successWorkersS2 = array_values(array_filter($raceS2['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejectedIssuesS2 = array_values(array_filter(
    $raceS2['workers'],
    static fn (array $w): bool => $w['operation'] === 'post_issue' && $w['outcome'] === 'REJECTED'
));
$receiptWorkerS2 = array_values(array_filter($raceS2['workers'], static fn (array $w): bool => $w['operation'] === 'post_receipt'))[0] ?? null;
$businessKeysS2 = array_map(static fn (array $r): string => (string) $r['business_key'], $rowsS2);
$issueSuccessesS2 = count(array_filter($successWorkersS2, static fn (array $w): bool => $w['operation'] === 'post_issue'));
$receiptSuccessS2 = $receiptWorkerS2 !== null && $receiptWorkerS2['outcome'] === 'SUCCESS' ? 1 : 0;

$checksS2 = [
    'three_distinct_backend_connections' => $raceS2['distinct_backend_pid_count'] === 3,
    'real_contention_proven' => $raceS2['real_contention_proven'],
    'receipt_never_rejected_for_stock' => $receiptSuccessS2 === 1,
    'movement_count_equals_success_count' => count($rowsS2) === count($successWorkersS2),
    'no_duplicate_business_key' => count($businessKeysS2) === count(array_unique($businessKeysS2)),
    'final_balance_matches_signed_ledger' => $afterS2['quantity'] === $beforeS2['quantity'] + (5 * $receiptSuccessS2) - (5 * $issueSuccessesS2),
    'no_negative_at_any_point' => $pathS2['no_negative_intermediate'] && $afterS2['quantity'] >= 0 && $afterS2['available_qty'] >= 0,
    'no_lost_update_path_consistent' => $pathS2['edge_multiset_matches'] && $pathS2['per_row_delta_consistent'],
    'balance_version_bumped_once_per_writer' => $afterS2['version'] === $beforeS2['version'] + count($successWorkersS2),
    'rejected_issues_carry_insufficient_stock_code' => count(array_filter(
        $rejectedIssuesS2,
        static fn (array $w): bool => in_array($w['error_code'], ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK'], true)
    )) === count($rejectedIssuesS2),
];
$scenarios['s2_mixed_receipt_and_two_issues_non_negative'] = $raceS2 + [
    'intent' => 'Ba writer thật tranh một số dư (1 receipt + 2 issue oversell). Kết quả phụ thuộc thứ tự, nên chỉ khẳng định các bất biến đúng trong mọi interleaving.',
    'fixtures' => [$receiptS2, $issueS2a, $issueS2b],
    'balance_before' => $beforeS2,
    'balance_after' => $afterS2,
    'ledger_rows' => $rowsS2,
    'path_consistency' => $pathS2,
    'issue_successes' => $issueSuccessesS2,
    'receipt_success' => $receiptSuccessS2,
    'checks' => $checksS2,
    'passed' => ! in_array(false, $checksS2, true),
];
if (! $scenarios['s2_mixed_receipt_and_two_issues_non_negative']['passed']) {
    $failures[] = 's2_mixed_receipt_and_two_issues_non_negative';
}

// ---------------------------------------------------------------------------
// S3 — No-double-movement cho đường RECEIPT: hai process cùng Post MỘT chứng từ.
// WWHN-201 đã chứng minh điều này cho Issue; register đòi cả hai chiều.
// Đúng một backend được chuyển SCANNING -> POSTED, kẻ thua phải nhận
// STATE_CONFLICT và bảng ledger chỉ được có đúng một movement.
// ---------------------------------------------------------------------------
$skuS3 = ensureProductSku('S3-'.$runToken);
seedBalance($warehouseId, $skuS3, 0);
$touchedDimensions[] = ['warehouse_id' => $warehouseId, 'sku_id' => $skuS3];

$receiptS3 = buildReceiptFixture('S3R', 1, $warehouseId, $skuS3);
$beforeS3 = balanceSnapshot($warehouseId, $skuS3);

$raceS3 = runRace('s3-same-receipt-document-double-post', [
    [
        'type' => 'post_receipt',
        'document_id' => $receiptS3['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $receiptS3['actor_id'],
        'idempotency_key' => 'wwhn205-s3-a-'.$runToken,
        'request_hash' => sha1('wwhn205-s3-a'),
        'contended_table' => 'stock_documents',
    ],
    [
        'type' => 'post_receipt',
        'document_id' => $receiptS3['document_id'],
        'document_type' => 'receipt',
        'actor_id' => $receiptS3['actor_id'],
        'idempotency_key' => 'wwhn205-s3-b-'.$runToken,
        'request_hash' => sha1('wwhn205-s3-b'),
        'contended_table' => 'stock_documents',
    ],
], $runtimeDir, $holdMs);

$afterS3 = balanceSnapshot($warehouseId, $skuS3);
$rowsS3 = ledgerRows($warehouseId, $skuS3);
$pathS3 = pathConsistency($rowsS3, $beforeS3['quantity'], $afterS3['quantity']);
$successS3 = count(array_filter($raceS3['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$documentStatusS3 = (string) DB::table('stock_documents')->where('id', $receiptS3['document_id'])->value('status');
$expectedKeyS3 = sha1('RECEIPT|'.$receiptS3['document_id'].'|'.$receiptS3['line_id']);

$checksS3 = [
    'two_distinct_backend_connections' => $raceS3['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $raceS3['real_contention_proven'],
    'exactly_one_success' => $successS3 === 1,
    'loser_rejected_state_conflict' => count(array_filter(
        $raceS3['workers'],
        static fn (array $w): bool => $w['outcome'] === 'REJECTED' && $w['error_code'] === 'STATE_CONFLICT'
    )) === 1,
    'exactly_one_movement_no_double' => count($rowsS3) === 1,
    'business_key_matches_canonical_formula' => count($rowsS3) === 1 && $rowsS3[0]['business_key'] === $expectedKeyS3,
    'item_id_set_for_single_item_line' => count($rowsS3) === 1
        && $rowsS3[0]['item_id'] === ($receiptS3['item_ids'][0] ?? null),
    'document_posted_once' => $documentStatusS3 === 'POSTED',
    'ledger_sum_equals_balance_delta' => $pathS3['sum_delta'] === $pathS3['expected_sum_delta'],
    'balance_never_negative' => $pathS3['no_negative_intermediate'] && $afterS3['quantity'] >= 0,
];
$scenarios['s3_same_receipt_document_double_post'] = $raceS3 + [
    'intent' => 'Hai process cùng Post một chứng từ receipt: chống double movement ở chiều nhập, đối xứng với bằng chứng đã có ở chiều xuất (WWHN-201).',
    'fixture' => $receiptS3,
    'balance_before' => $beforeS3,
    'balance_after' => $afterS3,
    'ledger_rows' => $rowsS3,
    'path_consistency' => $pathS3,
    'document_status' => $documentStatusS3,
    'checks' => $checksS3,
    'passed' => ! in_array(false, $checksS3, true),
];
if (! $scenarios['s3_same_receipt_document_double_post']['passed']) {
    $failures[] = 's3_same_receipt_document_double_post';
}

// ---------------------------------------------------------------------------
// Post-race reconcile (yêu cầu cuối của P1-03R). Không chỉ trên các dimension vừa
// chạm mà trên TOÀN BỘ inventory_balances/stock_movements của DB sau khi đua —
// một race làm hỏng số dư ở chỗ khác vẫn bị bắt.
// ---------------------------------------------------------------------------
$postRace = [
    'touched_dimensions' => array_map(static function (array $dim): array {
        // Ở đây lấy TOÀN BỘ ledger kể cả OPENING: đối soát cuối phải khớp số dư
        // tuyệt đối, không phải chỉ khớp phần delta của cuộc đua.
        $rows = ledgerRows($dim['warehouse_id'], $dim['sku_id'], false);
        $balance = balanceSnapshot($dim['warehouse_id'], $dim['sku_id']);
        $signed = array_sum(array_map(static fn (array $r): int => (int) $r['qty_delta'], $rows));

        return $dim + [
            'balance_quantity' => $balance['quantity'],
            'signed_ledger_qty' => $signed,
            'movement_count' => count($rows),
            'reconciled' => $balance['quantity'] === $signed,
        ];
    }, $touchedDimensions),
];

$globalMismatch = DB::select(<<<'SQL'
    select b.warehouse_id,
           coalesce(b.sku_id, b.product_id) as sku_id,
           b.stock_status,
           b.quantity::bigint          as balance_quantity,
           coalesce(m.signed_qty, 0)   as signed_ledger_qty,
           coalesce(m.movement_count, 0) as movement_count
      from inventory_balances b
      left join (
            select warehouse_id,
                   coalesce(sku_id, product_id) as sku_id,
                   stock_status,
                   sum(qty_delta)::bigint as signed_qty,
                   count(*)               as movement_count
              from stock_movements
             group by 1, 2, 3
      ) m on m.warehouse_id = b.warehouse_id
         and m.sku_id = coalesce(b.sku_id, b.product_id)
         and m.stock_status = b.stock_status
     where b.quantity::bigint <> coalesce(m.signed_qty, 0)
SQL);

$duplicateBusinessKeys = DB::select(
    'select business_key, count(*) as hits from stock_movements group by business_key having count(*) > 1'
);
$negativeBalances = DB::select(
    'select id, warehouse_id, quantity, available_qty from inventory_balances where quantity < 0 or available_qty < 0'
);
$orphanMovements = DB::select(<<<'SQL'
    select m.id, m.stock_document_id
      from stock_movements m
      left join stock_documents d on d.id = m.stock_document_id
     where m.stock_document_id is not null and d.id is null
SQL);

$postRace['global'] = [
    'ledger_balance_mismatch_count' => count($globalMismatch),
    'ledger_balance_mismatch_rows' => array_map(static fn ($r): array => (array) $r, $globalMismatch),
    'duplicate_business_key_count' => count($duplicateBusinessKeys),
    'duplicate_business_key_rows' => array_map(static fn ($r): array => (array) $r, $duplicateBusinessKeys),
    'negative_balance_count' => count($negativeBalances),
    'negative_balance_rows' => array_map(static fn ($r): array => (array) $r, $negativeBalances),
    'orphan_movement_count' => count($orphanMovements),
    'orphan_movement_rows' => array_map(static fn ($r): array => (array) $r, $orphanMovements),
];
// SRS §7.5 Serial subledger — cùng định nghĩa với
// OperationalReportController::inventoryReconciliation() (serialSubledgerCounts).
// Chỉ SKU oversell cố ý của S2 được phép lệch; lệch ở bất kỳ chỗ nào khác là defect thật.
$serialDivergence = DB::select(<<<'SQL'
    select b.warehouse_id,
           coalesce(b.sku_id, b.product_id) as sku_id,
           b.available_qty::bigint          as available_qty,
           s.serial_qty::bigint             as serial_qty
      from inventory_balances b
      join lateral (
            select count(*)::numeric as serial_qty
              from item_instances i
             where i.sku_id = coalesce(b.sku_id, b.product_id)
               and i.item_status = 'IN_STOCK'
      ) s on true
     where b.stock_status = 'AVAILABLE'
       and s.serial_qty > 0
       and abs(b.available_qty::numeric - s.serial_qty) >= 0.001
SQL);
$divergentSkus = array_values(array_unique(array_map(static fn ($r): string => (string) $r->sku_id, $serialDivergence)));

$postRace['serial_subledger'] = [
    'rule' => 'SRS §7.5 Serial subledger: available_qty = count(item_instances IN_STOCK)',
    'intentional_oversell_sku' => $skuS2,
    'divergent_skus' => $divergentSkus,
    'divergent_rows' => array_map(static fn ($r): array => (array) $r, $serialDivergence),
    'note' => 'S2 cố ý oversell nên SKU của S2 được phép lệch. Lệch ở SKU khác là defect thật.',
];

$postRace['checks'] = [
    'serial_divergence_confined_to_intentional_oversell_sku' => array_values(array_filter(
        $divergentSkus,
        static fn (string $sku): bool => $sku !== $skuS2
    )) === [],
    'touched_dimensions_all_reconciled' => ! in_array(false, array_map(
        static fn (array $d): bool => (bool) $d['reconciled'],
        $postRace['touched_dimensions']
    ), true),
    'ledger_equals_balance_globally' => count($globalMismatch) === 0,
    'no_duplicate_business_key_globally' => count($duplicateBusinessKeys) === 0,
    'no_negative_balance_globally' => count($negativeBalances) === 0,
    'no_orphan_movement_globally' => count($orphanMovements) === 0,
];
$postRace['passed'] = ! in_array(false, $postRace['checks'], true);
if (! $postRace['passed']) {
    $failures[] = 'post_race_reconciliation';
}

$summary = [
    'schema_version' => '1.0.0',
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-205',
    'sibling_ticket' => 'WWHN-214',
    'answers_review_findings' => ['P1-03R'],
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY_RECEIPT_VS_ISSUE',
    'note' => 'Mỗi worker là một OS process riêng với pg_backend_pid() riêng, đồng bộ qua barrier. Phạm vi là service layer (CurrentBaselineStockService::postStockDocument) đúng như phạm vi đã được duyệt ở WWHN-219/201; tầng HTTP If-Match/Idempotency-Key do QA1 chạy riêng.',
    'git_sha' => getenv('GITHUB_SHA') ?: (trim((string) @shell_exec('git rev-parse HEAD')) ?: null),
    'github_run_id' => getenv('GITHUB_RUN_ID') ?: null,
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'database' => (string) DB::selectOne('select current_database() as d')->d,
    'lock_hold_ms' => $holdMs,
    'run_token' => $runToken,
    'scenarios' => $scenarios,
    'post_race_reconciliation' => $postRace,
    'failed_scenarios' => $failures,
    'status' => $failures === [] ? 'PASS' : 'FAIL',
];

file_put_contents(
    $evidenceDir.'/receipt-issue-race-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode([
    'status' => $summary['status'],
    'postgres_version' => $summary['postgres_version'],
    'failed_scenarios' => $failures,
    'scenarios' => array_map(static fn (array $s): array => ['passed' => $s['passed'], 'checks' => $s['checks']], $scenarios),
    'post_race_reconciliation' => $postRace['checks'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($failures === [] ? 0 : 1);
