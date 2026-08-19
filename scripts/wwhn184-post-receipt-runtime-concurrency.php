<?php

declare(strict_types=1);

/**
 * WWHN-184 (DEV184-P1-03R) runtime concurrency orchestrator cho Post Receipt.
 *
 * Reviewer: "DEV race chỉ SQLite stale replay" — `PostReceiptConcurrencyBehaviorTest`
 * phát lại tuần tự trên MỘT connection sqlite nên không chứng minh được gì về hai
 * backend tranh chấp thật. WWHN-188 đã dựng race thật cho `inboundScan()` (stock-neutral);
 * `postReceipt()` — luồng DUY NHẤT ghi `stock_movements`/`inventory_balances` — thì chưa.
 *
 * Ba kịch bản, mỗi kịch bản spawn hai tiến trình OS (hai PostgreSQL backend độc lập):
 *
 *  1. same_document_same_version_two_keys — hai writer cùng đọc phiếu ở CÙNG version,
 *     dùng HAI Idempotency-Key khác nhau. Đúng một writer được POSTED; writer thua
 *     phải bị từ chối (STATE_CONFLICT hoặc STALE_VERSION) và tồn chỉ tăng đúng một lần.
 *  2. shared_idempotency_key — hai writer dùng CÙNG một Idempotency-Key và cùng payload.
 *     Lease idempotency phải cho đúng một writer thực thi; writer còn lại replay hoặc
 *     nhận CONCURRENCY_CONFLICT. Tồn vẫn chỉ tăng một lần, key chỉ lưu một hàng.
 *  3. post_vs_cancel — một writer Post, một writer Cancel trên cùng phiếu ở cùng version.
 *     Chỉ một hành động được commit; trạng thái cuối là POSTED hoặc CANCELLED, không
 *     bao giờ là trạng thái lai, và stock effect phải nhất quán với trạng thái cuối đó.
 *
 * Cơ chế barrier/pre-lock/lấy mẫu pg_locks/timestamp thô sao đúng
 * `scripts/wwhn188-inbound-scan-runtime-concurrency.php` đã được reviewer chấp nhận.
 *
 * Fixture được dựng bằng chính production controller (create -> box -> scan) thay vì
 * INSERT tay, để trạng thái SCANNING/scan-entry/quantity luôn hợp lệ theo đúng
 * business rule đang chạy, không phụ thuộc hiểu biết schema của script này.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\CurrentBaselineDocumentController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineInboundBoxController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineStockFlowController;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = $argv[1] ?? (getenv('WWHN184_EVIDENCE_DIR') ?: 'storage/app/wwhn-184-evidence');
$runtimeDir = $evidenceDir.'/runtime-concurrency';
@mkdir($runtimeDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-184 runtime concurrency evidence requires DB_CONNECTION=pgsql.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn184-post-receipt-worker.php';
$holdMs = (int) (getenv('WWHN184_LOCK_HOLD_MS') ?: 1200);
$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

/** Actor fixture: gắn SUPER_ADMIN để controller-level call không vướng data scope. */
function fixtureActorId(string $runToken): string
{
    $user = User::create([
        'username' => 'wwhn184-'.strtolower($runToken),
        'display_name' => 'WWHN-184 post-receipt concurrency fixture actor',
        'password_hash' => Hash::make('wwhn184-runtime-concurrency'),
        'status' => 'ACTIVE',
        'version' => 1,
    ]);

    $role = Role::query()->where('role_code', 'SUPER_ADMIN')->first();
    if ($role !== null) {
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
    }

    return (string) $user->user_id;
}

function activeWarehouseId(): string
{
    // uk_warehouses_single_active chỉ cho phép một warehouse ACTIVE — tái sử dụng
    // bản đang có (thường là KHO-TONG-HN từ seed) thay vì chèn bản thứ hai.
    $existing = (string) DB::table('warehouses')->where('status', 'ACTIVE')->value('id');
    if ($existing !== '') {
        return $existing;
    }

    $id = (string) Str::uuid();
    DB::table('warehouses')->insert([
        'id' => $id,
        'code' => 'KHO-TONG-HN',
        'name' => 'Kho tổng Hòa Nam',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** COMPONENT không serial-tracked: Post theo số lượng, không cần item định danh. */
function buildComponentSku(string $tag): string
{
    $id = (string) Str::uuid();
    DB::table('sku')->insert([
        'sku_id' => $id,
        'sku_code' => 'WWHN184-CMP-'.$tag,
        'sku_name' => 'WWHN-184 post-receipt concurrency component '.$tag,
        'sku_type' => 'COMPONENT',
        'unit' => 'cai',
        'is_serial_tracked' => false,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Gọi controller trong process orchestrator với một Request dựng sẵn. */
function callController(callable $invoke, Request $request): array
{
    app()->instance('request', $request);
    $response = $invoke($request);
    $status = $response->getStatusCode();
    $decoded = json_decode((string) $response->getContent(), true);

    if ($status < 200 || $status >= 300) {
        fail('Fixture step failed with HTTP '.$status.': '.json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Dựng một phiếu nhập ở trạng thái SCANNING, đã quét đủ số lượng — tức là đủ điều
 * kiện Post Receipt theo REQ-SRS-007 (full-post).
 *
 * @return array{document_id:string, line_id:string, sku_id:string, version:int, qty:int}
 */
function buildReadyToPostDocument(string $actorId, string $warehouseId, string $tag, int $qty): array
{
    $user = User::query()->findOrFail($actorId);
    Auth::guard('api')->setUser($user);

    $skuId = buildComponentSku($tag);

    $create = callController(
        static fn (Request $r) => app(CurrentBaselineDocumentController::class)->storeInbound($r),
        Request::create('/api/v1/inbound-documents', 'POST', [
            'doc_no' => 'PN-W184-RACE-'.$tag,
            'dst_warehouse_id' => $warehouseId,
            'source_reference' => 'WWHN-184 runtime concurrency '.$tag,
            'lines' => [['product_id' => $skuId, 'qty_planned' => $qty]],
        ])
    );

    $documentId = (string) data_get($create, 'data.id');
    $lineId = (string) data_get($create, 'data.lines.0.id');

    $box = callController(
        static fn (Request $r) => app(CurrentBaselineInboundBoxController::class)->store($r, $documentId),
        Request::create('/api/v1/inbound-documents/'.$documentId.'/boxes', 'POST', [
            'line_id' => $lineId,
            'box_code' => 'BOX-W184-RACE-'.$tag,
        ])
    );
    $boxId = (string) data_get($box, 'data.container_id');

    $scanRequest = Request::create('/api/v1/inbound-documents/'.$documentId.'/scan', 'POST', [
        'line_id' => $lineId,
        'quantity' => $qty,
        'container_id' => $boxId,
    ]);
    $scanRequest->headers->set('If-Match', '1');
    $scan = callController(
        static fn (Request $r) => app(CurrentBaselineStockFlowController::class)->inboundScan($r, $documentId),
        $scanRequest
    );

    $version = (int) data_get($scan, 'data.version');
    $status = (string) DB::table('stock_documents')->where('id', $documentId)->value('status');
    if ($status !== 'SCANNING') {
        fail("Fixture document {$documentId} phải ở SCANNING trước khi race, đang là {$status}.");
    }

    return [
        'document_id' => $documentId,
        'line_id' => $lineId,
        'sku_id' => $skuId,
        'version' => $version,
        'qty' => $qty,
    ];
}

/** Ảnh chụp trạng thái commit được, dùng để so trước/sau race. */
function snapshot(string $documentId, string $skuId): array
{
    $document = DB::table('stock_documents')->where('id', $documentId)->first();

    return [
        'document_status' => $document?->status,
        'document_version' => (int) ($document?->version ?? 0),
        'stock_movement_count' => (int) DB::table('stock_movements')->count(),
        'movements_for_document' => (int) DB::table('stock_movements')->where('stock_document_id', $documentId)->count(),
        'on_hand_qty' => (int) (DB::table('inventory_balances')->where('sku_id', $skuId)->sum('on_hand_qty') ?? 0),
    ];
}

/**
 * Spawn một tiến trình OS cho mỗi worker plan, thả barrier khi tất cả đã đăng ký
 * backend pid, lấy mẫu pg_locks trong lúc tranh chấp rồi thu kết quả.
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
            fail("Could not spawn WWHN-184 concurrency worker {$index}.");
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
                 where a.application_name like 'wwhn184-worker-%'
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

/**
 * Bằng chứng hai tiến trình thực sự chồng lấn trong thời gian thực.
 *
 * Cửa sổ đo là cửa sổ ENGAGE DB của worker: bắt đầu từ lúc worker chạm hàng
 * tranh chấp (`lock_wait_started_at` — mode locked) hoặc lúc phát request
 * (`request_started_at` — mode plain), kết thúc tại `request_finished_at`.
 *
 * Vì sao không đo bằng cửa sổ request thuần (fix tại retest R4, DEV184-P1-03R4):
 * ở mode `locked`, hai worker pre-lock CÙNG một hàng, nên bên thua chỉ stamp
 * được `request_started_at` SAU khi bên thắng commit rồi hold thêm `hold_ms` —
 * hai cửa sổ request bị serialize bởi CHÍNH cái lock đang được chứng minh, và
 * assertion "chồng lấn request" là bất khả thi về cấu trúc (evidence R3 cũng
 * ghi `workers_in_flight_simultaneously=false` cho kịch bản locked; test
 * phpunit khi đó bị skip vì thiếu WWHN184_RUNTIME_CONCURRENCY=1 nên assertion
 * chưa từng chạy). Chồng lấn thật của mode locked = bên thua blocked-on-lock
 * trong PostgreSQL suốt lúc bên thắng chạy request — được chứng minh độc lập
 * bằng pg_locks sample (`real_contention_proven`), và cửa sổ engage phản ánh
 * đúng khoảng đó.
 */
function overlapEvidence(array $workers): array
{
    $toMicros = static function (?string $value): ?float {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value);

        return $parsed === false ? null : (float) $parsed->format('U.u');
    };

    $starts = [];
    $ends = [];
    foreach ($workers as $worker) {
        $start = $toMicros($worker['lock_wait_started_at'] ?? null)
            ?? $toMicros($worker['request_started_at'] ?? null)
            ?? $toMicros($worker['barrier_released_at'] ?? null);
        $end = $toMicros($worker['request_finished_at'] ?? null);
        if ($start === null || $end === null) {
            return ['measurable' => false, 'overlap_ms' => null, 'windows' => []];
        }
        $starts[] = $start;
        $ends[] = $end;
    }

    $overlap = (min($ends) - max($starts)) * 1000;

    return [
        'measurable' => true,
        'window_semantics' => 'db-engagement: [lock_wait_started_at ?? request_started_at, request_finished_at]',
        'overlap_ms' => round($overlap, 3),
        'workers_in_flight_simultaneously' => $overlap > 0,
        'windows' => array_map(static fn (array $w): array => [
            'worker_index' => $w['worker_index'] ?? null,
            'action' => $w['action'] ?? null,
            'os_pid' => $w['os_pid'] ?? null,
            'backend_pid' => $w['backend_pid'] ?? null,
            'barrier_released_at' => $w['barrier_released_at'] ?? null,
            'lock_wait_started_at' => $w['lock_wait_started_at'] ?? null,
            'lock_acquired_at' => $w['lock_acquired_at'] ?? null,
            'request_started_at' => $w['request_started_at'] ?? null,
            'request_finished_at' => $w['request_finished_at'] ?? null,
        ], array_values($workers)),
    ];
}

function gitEvidenceMeta(): array
{
    $run = static function (string $command): ?string {
        $output = @shell_exec($command.' 2>'.(DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));

        return $output === null ? null : (trim($output) === '' ? null : trim($output));
    };

    $root = dirname(__DIR__);
    $prefix = 'git -C '.escapeshellarg($root).' ';
    $status = $run($prefix.'status --porcelain');

    $artifacts = [
        'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
        'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
        'app/Support/IdempotencyStore.php',
        'scripts/wwhn184-post-receipt-runtime-concurrency.php',
        'scripts/wwhn184-post-receipt-worker.php',
        'tests/Feature/Wwhn184PostReceiptRuntimeConcurrencyTest.php',
        'tests/Feature/Wwhn184InboundContractParityTest.php',
    ];
    $blobs = [];
    foreach ($artifacts as $path) {
        $blobs[$path] = $run($prefix.'hash-object '.escapeshellarg($root.'/'.$path));
    }

    return [
        'git_sha' => $run($prefix.'rev-parse HEAD'),
        'git_branch' => $run($prefix.'rev-parse --abbrev-ref HEAD'),
        'git_working_tree_clean' => $status === null,
        'git_dirty_paths' => $status === null ? [] : preg_split('/\r?\n/', $status),
        'artifact_blob_sha1' => $blobs,
    ];
}

$actorId = fixtureActorId($runToken);
$warehouseId = activeWarehouseId();
$scenarios = [];
$failures = [];

/* ---------------------------------------------------------------------------
 * Kịch bản 1 — cùng phiếu, cùng version, HAI Idempotency-Key khác nhau.
 * Đây chính là race "double post" mà DEV184-P1-03R yêu cầu chứng minh thật.
 * ------------------------------------------------------------------------- */
$docA = buildReadyToPostDocument($actorId, $warehouseId, 'A'.$runToken, 4);
$beforeS1 = snapshot($docA['document_id'], $docA['sku_id']);
$race1 = runRace('same_document_same_version_two_keys', [
    [
        'action' => 'post_receipt', 'mode' => 'locked', 'actor_id' => $actorId,
        'document_id' => $docA['document_id'], 'if_match' => (string) $docA['version'],
        'idempotency_key' => 'w184-race-a1-'.$runToken,
    ],
    [
        'action' => 'post_receipt', 'mode' => 'locked', 'actor_id' => $actorId,
        'document_id' => $docA['document_id'], 'if_match' => (string) $docA['version'],
        'idempotency_key' => 'w184-race-a2-'.$runToken,
    ],
], $runtimeDir, $holdMs);
$afterS1 = snapshot($docA['document_id'], $docA['sku_id']);

$successes1 = array_values(array_filter($race1['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejected1 = array_values(array_filter($race1['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$checks1 = [
    'exactly_one_winner' => count($successes1) === 1,
    'exactly_one_loser' => count($rejected1) === 1,
    'no_worker_errored' => count(array_filter($race1['workers'], static fn (array $w): bool => $w['outcome'] === 'ERROR')) === 0,
    'loser_rejected_with_contract_code' => count($rejected1) === 1
        && in_array((string) $rejected1[0]['error_code'], ['STATE_CONFLICT', 'STALE_VERSION', 'CONCURRENCY_CONFLICT'], true),
    'document_posted' => $afterS1['document_status'] === 'POSTED',
    'document_version_advanced_exactly_once' => $afterS1['document_version'] === $beforeS1['document_version'] + 1,
    'stock_increased_exactly_once' => $afterS1['on_hand_qty'] === $beforeS1['on_hand_qty'] + $docA['qty'],
    'exactly_one_movement_for_document' => $afterS1['movements_for_document'] === 1,
    'two_distinct_backend_connections' => $race1['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race1['real_contention_proven'],
];
$scenarios['same_document_same_version_two_keys'] = $race1 + [
    'document_id' => $docA['document_id'],
    'sku_id' => $docA['sku_id'],
    'db_before' => $beforeS1,
    'db_after' => $afterS1,
    'timing' => overlapEvidence($race1['workers']),
    'checks' => $checks1,
    'passed' => ! in_array(false, $checks1, true),
];
if (! $scenarios['same_document_same_version_two_keys']['passed']) {
    $failures[] = 'same_document_same_version_two_keys';
}

/* ---------------------------------------------------------------------------
 * Kịch bản 2 — CÙNG một Idempotency-Key, cùng payload, hai tiến trình.
 * Điểm tranh chấp là lease `idempotency_keys`, nên chạy mode `plain`.
 * ------------------------------------------------------------------------- */
$docB = buildReadyToPostDocument($actorId, $warehouseId, 'B'.$runToken, 3);
$sharedKey = 'w184-race-shared-'.$runToken;
$beforeS2 = snapshot($docB['document_id'], $docB['sku_id']);
$race2 = runRace('shared_idempotency_key', [
    [
        'action' => 'post_receipt', 'mode' => 'plain', 'actor_id' => $actorId,
        'document_id' => $docB['document_id'], 'if_match' => (string) $docB['version'],
        'idempotency_key' => $sharedKey,
    ],
    [
        'action' => 'post_receipt', 'mode' => 'plain', 'actor_id' => $actorId,
        'document_id' => $docB['document_id'], 'if_match' => (string) $docB['version'],
        'idempotency_key' => $sharedKey,
    ],
], $runtimeDir, $holdMs);
$afterS2 = snapshot($docB['document_id'], $docB['sku_id']);

$errored2 = array_filter($race2['workers'], static fn (array $w): bool => $w['outcome'] === 'ERROR');
$rejected2 = array_values(array_filter($race2['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$checks2 = [
    'no_worker_errored' => count($errored2) === 0,
    'document_posted' => $afterS2['document_status'] === 'POSTED',
    'stock_increased_exactly_once' => $afterS2['on_hand_qty'] === $beforeS2['on_hand_qty'] + $docB['qty'],
    'exactly_one_movement_for_document' => $afterS2['movements_for_document'] === 1,
    'document_version_advanced_exactly_once' => $afterS2['document_version'] === $beforeS2['document_version'] + 1,
    'shared_key_stored_once' => (int) DB::table('idempotency_keys')->where('idem_key', $sharedKey)->count() === 1,
    'loser_replayed_or_conflicted' => $rejected2 === []
        || in_array((string) $rejected2[0]['error_code'], ['CONCURRENCY_CONFLICT', 'IDEMPOTENCY_CONFLICT'], true),
    'two_distinct_backend_connections' => $race2['distinct_backend_pid_count'] === 2,
];
$scenarios['shared_idempotency_key'] = $race2 + [
    'document_id' => $docB['document_id'],
    'sku_id' => $docB['sku_id'],
    'shared_idempotency_key' => $sharedKey,
    'db_before' => $beforeS2,
    'db_after' => $afterS2,
    'timing' => overlapEvidence($race2['workers']),
    'checks' => $checks2,
    'passed' => ! in_array(false, $checks2, true),
];
if (! $scenarios['shared_idempotency_key']['passed']) {
    $failures[] = 'shared_idempotency_key';
}

/* ---------------------------------------------------------------------------
 * Kịch bản 3 — Post đua với Cancel trên cùng phiếu, cùng version.
 * Hai transition terminal loại trừ nhau: chỉ một được commit, và stock effect
 * phải khớp đúng với transition thắng.
 * ------------------------------------------------------------------------- */
$docC = buildReadyToPostDocument($actorId, $warehouseId, 'C'.$runToken, 2);
$beforeS3 = snapshot($docC['document_id'], $docC['sku_id']);
$race3 = runRace('post_vs_cancel', [
    [
        'action' => 'post_receipt', 'mode' => 'locked', 'actor_id' => $actorId,
        'document_id' => $docC['document_id'], 'if_match' => (string) $docC['version'],
        'idempotency_key' => 'w184-race-c-post-'.$runToken,
    ],
    [
        'action' => 'cancel', 'mode' => 'locked', 'actor_id' => $actorId,
        'document_id' => $docC['document_id'], 'if_match' => (string) $docC['version'],
        'reason' => 'WWHN-184 race: Cancel đua với Post trên cùng version',
    ],
], $runtimeDir, $holdMs);
$afterS3 = snapshot($docC['document_id'], $docC['sku_id']);

$successes3 = array_values(array_filter($race3['workers'], static fn (array $w): bool => $w['outcome'] === 'SUCCESS'));
$rejected3 = array_values(array_filter($race3['workers'], static fn (array $w): bool => $w['outcome'] === 'REJECTED'));
$finalStatus3 = (string) $afterS3['document_status'];
$checks3 = [
    'exactly_one_winner' => count($successes3) === 1,
    'exactly_one_loser' => count($rejected3) === 1,
    'no_worker_errored' => count(array_filter($race3['workers'], static fn (array $w): bool => $w['outcome'] === 'ERROR')) === 0,
    'loser_rejected_with_contract_code' => count($rejected3) === 1
        && in_array((string) $rejected3[0]['error_code'], ['STATE_CONFLICT', 'STALE_VERSION', 'CONCURRENCY_CONFLICT'], true),
    'final_state_is_terminal_and_single' => in_array($finalStatus3, ['POSTED', 'CANCELLED'], true),
    'document_version_advanced_exactly_once' => $afterS3['document_version'] === $beforeS3['document_version'] + 1,
    'stock_effect_matches_winning_transition' => $finalStatus3 === 'POSTED'
        ? ($afterS3['on_hand_qty'] === $beforeS3['on_hand_qty'] + $docC['qty'] && $afterS3['movements_for_document'] === 1)
        : ($afterS3['on_hand_qty'] === $beforeS3['on_hand_qty'] && $afterS3['movements_for_document'] === 0),
    'two_distinct_backend_connections' => $race3['distinct_backend_pid_count'] === 2,
    'real_contention_proven' => $race3['real_contention_proven'],
];
$scenarios['post_vs_cancel'] = $race3 + [
    'document_id' => $docC['document_id'],
    'sku_id' => $docC['sku_id'],
    'final_status' => $finalStatus3,
    'db_before' => $beforeS3,
    'db_after' => $afterS3,
    'timing' => overlapEvidence($race3['workers']),
    'checks' => $checks3,
    'passed' => ! in_array(false, $checks3, true),
];
if (! $scenarios['post_vs_cancel']['passed']) {
    $failures[] = 'post_vs_cancel';
}

$summary = [
    'generated_at' => now()->toIso8601String(),
    'ticket' => 'WWHN-184',
    'reviewer_finding' => ['DEV184-P1-03R'],
    'evidence_type' => 'EXECUTED_RUNTIME_CONCURRENCY',
    'note' => 'Mỗi kịch bản spawn hai tiến trình OS với hai PostgreSQL backend độc lập đua trên postReceipt()/cancelInbound(); assertion đối chiếu status/version/stock_movements/inventory_balances commit được trước và sau race, không chỉ HTTP status.',
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'database' => (string) DB::selectOne('select current_database() as d')->d,
    'lock_hold_ms' => $holdMs,
    'run_token' => $runToken,
    'scenarios' => $scenarios,
    'failed_scenarios' => $failures,
    'status' => $failures === [] ? 'PASS' : 'FAIL',
] + gitEvidenceMeta();

file_put_contents(
    $evidenceDir.'/post-receipt-concurrency-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
);

echo json_encode([
    'status' => $summary['status'],
    'git_sha' => $summary['git_sha'],
    'git_working_tree_clean' => $summary['git_working_tree_clean'],
    'postgres_version' => $summary['postgres_version'],
    'failed_scenarios' => $failures,
    'scenarios' => array_map(static fn (array $s): array => ['passed' => $s['passed'], 'checks' => $s['checks']], $scenarios),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

exit($failures === [] ? 0 : 1);
