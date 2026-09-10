<?php

declare(strict_types=1);

/**
 * WWHN-190 / DEV190-P1-02R2 — true concurrency trên PostgreSQL.
 *
 * Yêu cầu review: "Chạy true concurrency tối thiểu 2 connection/process:
 *   (1) same key/same payload, (2) different key same doc, (3) stale version,
 *   (4) same key/different payload, (5) forced rollback."
 * Bằng chứng phải là "Exact SHA/env + raw concurrent logs + SQL/DB before-after".
 *
 * Kịch bản ở đây KHÔNG mô phỏng: mỗi worker là một OS process riêng với backend
 * PostgreSQL riêng, bắn HTTP thật qua HTTP kernel (xem wwhn190-concurrency-worker.php),
 * được thả đồng thời qua barrier filesystem. Một connection monitor độc lập lấy mẫu
 * pg_locks/pg_stat_activity trong lúc hai worker đang tranh chấp.
 *
 * Một kịch bản chỉ PASS khi đủ bốn điều kiện:
 *   1. hai backend pid khác nhau (đúng là hai kết nối thật),
 *   2. hai cửa sổ request chồng nhau về wall-clock hoặc quan sát được lock wait,
 *   3. đúng số lần thành công mong đợi + đúng mã lỗi của bên thua,
 *   4. bất biến dữ liệu sau cùng: movement/balance/posting_event/idempotency lease.
 *
 * Dùng: DB_CONNECTION=pgsql php scripts/wwhn190-postgres-concurrency.php [evidenceDir]
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/lib/assert-non-production.php';

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "WWHN-190 concurrency evidence yêu cầu DB_CONNECTION=pgsql.\n");
    exit(2);
}

$evidenceDir = $argv[1] ?? (getenv('WWHN190_EVIDENCE_DIR') ?: 'docs/evidence/WWHN-190/concurrency');
@mkdir($evidenceDir, 0777, true);

$phpBinary = PHP_BINARY;
$workerScript = __DIR__.'/wwhn190-concurrency-worker.php';
$runToken = strtoupper(substr(sha1((getenv('GITHUB_RUN_ID') ?: '').microtime(true).getmypid()), 0, 8));
$holdMs = (int) (getenv('WWHN190_LOCK_HOLD_MS') ?: 1200);

$rawLog = [];
function logLine(string $line): void
{
    global $rawLog;
    $stamp = date('Y-m-d H:i:s');
    $rawLog[] = "[{$stamp}] {$line}";
    echo $line."\n";
}

function fail(string $message): never
{
    logLine('FATAL: '.$message);
    fwrite(STDERR, $message."\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Actor: SUPER_ADMIN thật, đi qua đủ middleware permission + data scope.
// ---------------------------------------------------------------------------
function ensureActor(): string
{
    $username = 'wwhn190-runner';
    $user = User::query()->where('username', $username)->first();
    if ($user !== null) {
        return $username;
    }

    $roleId = DB::table('role')->where('role_code', 'SUPER_ADMIN')->value('role_id');
    if ($roleId === null) {
        $roleId = (string) Str::uuid();
        DB::table('role')->insert([
            'role_id' => $roleId,
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $userId = (string) Str::uuid();
    DB::table('app_user')->insert([
        'user_id' => $userId,
        'username' => $username,
        'display_name' => 'WWHN-190 concurrency runner',
        'password_hash' => password_hash('wwhn190-'.Str::random(16), PASSWORD_BCRYPT),
        'status' => 'ACTIVE',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_role')->insert([
        'user_role_id' => (string) Str::uuid(),
        'user_id' => $userId,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $username;
}

// ---------------------------------------------------------------------------
// Fixture: phiếu nhập SCANNING đã scan đủ (DEC-FULL-POST-001), cô lập theo scenario.
// ---------------------------------------------------------------------------
function buildReceiptFixture(string $tag, int $qty): array
{
    global $runToken;

    $now = now();
    $tag = $tag.'-'.$runToken;
    $actorId = (string) DB::table('app_user')->where('username', 'wwhn190-runner')->value('user_id');

    $warehouseId = (string) DB::table('warehouses')->where('status', 'ACTIVE')->value('id');
    if ($warehouseId === '') {
        $warehouseId = (string) Str::uuid();
        DB::table('warehouses')->insert([
            'id' => $warehouseId,
            'code' => 'WWHN190-'.$tag,
            'name' => 'WWHN-190 concurrency warehouse '.$tag,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $skuId = (string) Str::uuid();
    DB::table('sku')->insert([
        'sku_id' => $skuId,
        'sku_code' => 'WWHN190-SKU-'.$tag,
        'sku_name' => 'WWHN-190 concurrency SKU '.$tag,
        'sku_type' => 'COMPONENT',
        'unit' => 'cai',
        'is_serial_tracked' => false,
        'status' => 'ACTIVE',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $documentId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    DB::transaction(function () use ($documentId, $lineId, $warehouseId, $skuId, $actorId, $now, $tag, $qty): void {
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => 'WWHN190-'.$tag.'-'.strtoupper(Str::random(6)),
            'doc_date' => $now->toDateString(),
            'status' => 'SCANNING',
            'src_warehouse_id' => null,
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'WWHN-190 true concurrency fixture',
            'note' => 'DEV190-P1-02R2 executed evidence',
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
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
        'actor_id' => $actorId,
    ];
}

// ---------------------------------------------------------------------------
// Ảnh chụp DB trước/sau — kèm nguyên văn SQL để QA chạy lại được.
// ---------------------------------------------------------------------------
function dbSnapshot(array $fixture, array $keys): array
{
    $documentId = $fixture['document_id'];

    $sql = [
        'document' => "select id, status, version, posted_at from stock_documents where id = '{$documentId}'",
        'movements' => "select id, movement_type, business_key, qty_delta, qty_before, qty_after from stock_movements where stock_document_id = '{$documentId}' order by created_at",
        'balance' => "select warehouse_id, sku_id, stock_status, quantity, on_hand_qty, available_qty, version from inventory_balances where warehouse_id = '{$fixture['warehouse_id']}' and (sku_id = '{$fixture['sku_id']}' or product_id = '{$fixture['sku_id']}')",
        'posting_events' => "select count(*) as c from posting_events where source_id = '{$documentId}' or reference_id = '{$documentId}'",
        'idempotency' => 'select idem_key, status, request_hash from idempotency_keys where idem_key in ('.implode(',', array_map(fn ($k) => "'".$k."'", $keys ?: ['-'])).')',
    ];

    $document = DB::table('stock_documents')->where('id', $documentId)->first();
    $movements = DB::table('stock_movements')->where('stock_document_id', $documentId)->orderBy('created_at')->get();
    $balance = DB::table('inventory_balances')
        ->where('warehouse_id', $fixture['warehouse_id'])
        ->where(fn ($q) => $q->where('sku_id', $fixture['sku_id'])->orWhere('product_id', $fixture['sku_id']))
        ->get();
    $idem = $keys === []
        ? collect()
        : DB::table('idempotency_keys')->whereIn('idem_key', $keys)->get(['idem_key', 'status', 'request_hash', 'locked_until']);

    $postingEvents = postingEventCount($documentId);

    return [
        'sql' => $sql,
        'document' => $document === null ? null : [
            'status' => $document->status,
            'version' => (int) $document->version,
            'posted_at' => $document->posted_at,
        ],
        'movement_count' => $movements->count(),
        'movement_business_keys' => $movements->pluck('business_key')->all(),
        'movement_qty_delta_sum' => (int) $movements->sum('qty_delta'),
        'balance' => $balance->map(fn ($b) => [
            'stock_status' => $b->stock_status,
            'quantity' => (int) $b->quantity,
            'on_hand_qty' => (int) $b->on_hand_qty,
            'available_qty' => (int) $b->available_qty,
            'version' => (int) $b->version,
        ])->all(),
        'balance_on_hand_total' => (int) $balance->sum('on_hand_qty'),
        'posting_event_count' => $postingEvents,
        'idempotency_rows' => $idem->map(fn ($r) => [
            'idem_key' => $r->idem_key,
            'status' => $r->status,
            'request_hash' => substr((string) $r->request_hash, 0, 24).'...',
        ])->all(),
    ];
}

function postingEventCount(string $documentId): int
{
    if (! DB::getSchemaBuilder()->hasTable('posting_events')) {
        return -1;
    }
    $columns = ['source_id', 'reference_id', 'stock_document_id', 'document_id'];
    foreach ($columns as $column) {
        if (DB::getSchemaBuilder()->hasColumn('posting_events', $column)) {
            return (int) DB::table('posting_events')->where($column, $documentId)->count();
        }
    }

    return -1;
}

// ---------------------------------------------------------------------------
// Chạy đua: spawn worker + blocker + monitor.
// ---------------------------------------------------------------------------
function runRace(string $scenario, array $workerRequests, string $evidenceDir, ?string $blockDocumentId, string $actorUsername): array
{
    global $phpBinary, $workerScript, $holdMs;

    $scenarioDir = $evidenceDir.'/'.$scenario;
    $barrierDir = $scenarioDir.'/barrier';
    @mkdir($barrierDir, 0777, true);
    array_map('unlink', glob($barrierDir.'/*') ?: []);

    // Blocker giữ row lock trên chứng từ để hai worker chắc chắn xếp hàng trong PG.
    // Blocker trung lập: không ghi gì, chỉ SELECT ... FOR UPDATE rồi rollback.
    $blockerProcess = null;
    if ($blockDocumentId !== null) {
        $blockerScript = $scenarioDir.'/blocker.php';
        file_put_contents($blockerScript, <<<PHP
<?php
use Illuminate\\Contracts\\Console\\Kernel;
use Illuminate\\Support\\Facades\\DB;
require '{$GLOBALS['repoRoot']}/vendor/autoload.php';
\$app = require '{$GLOBALS['repoRoot']}/bootstrap/app.php';
\$app->make(Kernel::class)->bootstrap();
DB::statement("SET application_name = 'wwhn190-blocker'");
DB::beginTransaction();
DB::table('stock_documents')->where('id', '{$blockDocumentId}')->lockForUpdate()->get();
file_put_contents('{$barrierDir}/blocker-ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
usleep({$holdMs} * 1000);
DB::rollBack();
PHP);
        $blockerProcess = proc_open(
            [$phpBinary, $blockerScript],
            [1 => ['file', $scenarioDir.'/blocker.stdout.txt', 'w'], 2 => ['file', $scenarioDir.'/blocker.stderr.txt', 'w']],
            $pipes
        );
        $deadline = microtime(true) + 30;
        while (! is_file($barrierDir.'/blocker-ready')) {
            if (microtime(true) > $deadline) {
                fail("Blocker của {$scenario} không lấy được lock.");
            }
            usleep(2000);
        }
    }

    $processes = [];
    foreach ($workerRequests as $index => $request) {
        $planFile = $scenarioDir.'/plan-'.$index.'.json';
        $outFile = $scenarioDir.'/worker-'.$index.'.json';
        @unlink($outFile);
        file_put_contents($planFile, json_encode([
            'worker_index' => $index,
            'scenario' => $scenario,
            'barrier_dir' => $barrierDir,
            'out_file' => $outFile,
            'actor_username' => $actorUsername,
            'request' => $request,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $processes[$index] = [
            'proc' => proc_open(
                [$phpBinary, $workerScript, $planFile],
                [
                    1 => ['file', $scenarioDir.'/worker-'.$index.'.stdout.txt', 'w'],
                    2 => ['file', $scenarioDir.'/worker-'.$index.'.stderr.txt', 'w'],
                ],
                $pipes
            ),
            'out' => $outFile,
        ];
    }

    // Chờ mọi worker đăng ký backend pid trước khi thả barrier.
    $deadline = microtime(true) + 90;
    while (count(glob($barrierDir.'/ready-*') ?: []) < count($workerRequests)) {
        if (microtime(true) > $deadline) {
            fail("Worker của {$scenario} không tới được barrier.");
        }
        usleep(2000);
    }

    $readyPids = array_map(fn ($f) => (int) file_get_contents($f), glob($barrierDir.'/ready-*') ?: []);
    file_put_contents($barrierDir.'/go', '1');
    $releasedAt = microtime(true);

    // Monitor: connection độc lập lấy mẫu pg_locks/pg_stat_activity trong lúc tranh chấp.
    $samples = [];
    $sampleDeadline = microtime(true) + 20;
    $running = true;
    while ($running && microtime(true) < $sampleDeadline) {
        $samples[] = [
            'at' => microtime(true),
            'locks' => DB::select(
                "select l.pid, a.application_name, l.locktype, l.relation::regclass::text as relation, l.mode, l.granted
                 from pg_locks l join pg_stat_activity a on a.pid = l.pid
                 where a.application_name like 'wwhn190-%' and l.locktype in ('transactionid','tuple','relation')
                 order by l.granted, l.pid"
            ),
            'activity' => DB::select(
                "select pid, application_name, state, wait_event_type, wait_event, left(query, 120) as query
                 from pg_stat_activity where application_name like 'wwhn190-%'"
            ),
        ];
        usleep(60000);

        $running = false;
        foreach ($processes as $p) {
            if (! is_file($p['out'])) {
                $running = true;
            }
        }
    }

    foreach ($processes as $p) {
        proc_close($p['proc']);
    }
    if ($blockerProcess !== null) {
        proc_close($blockerProcess);
    }

    $results = [];
    foreach ($processes as $index => $p) {
        if (! is_file($p['out'])) {
            fail("Worker {$index} của {$scenario} không sinh kết quả.");
        }
        $results[$index] = json_decode((string) file_get_contents($p['out']), true, 512, JSON_THROW_ON_ERROR);
    }

    file_put_contents(
        $scenarioDir.'/pg-lock-samples.json',
        json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    // Bằng chứng tranh chấp: hoặc hai cửa sổ request chồng nhau, hoặc quan sát được
    // ít nhất một lock chưa granted của worker WWHN-190.
    $windows = array_map(
        fn (array $r) => [$r['timeline']['request_started_at'] ?? 0.0, $r['timeline']['request_finished_at'] ?? 0.0],
        $results
    );
    $overlap = false;
    $values = array_values($windows);
    for ($i = 0; $i < count($values); $i++) {
        for ($j = $i + 1; $j < count($values); $j++) {
            if ($values[$i][0] < $values[$j][1] && $values[$j][0] < $values[$i][1]) {
                $overlap = true;
            }
        }
    }
    $observedWait = false;
    foreach ($samples as $sample) {
        foreach ($sample['locks'] as $lock) {
            if (! $lock->granted) {
                $observedWait = true;
            }
        }
        foreach ($sample['activity'] as $activity) {
            if (($activity->wait_event_type ?? null) === 'Lock') {
                $observedWait = true;
            }
        }
    }

    $pids = array_values(array_unique(array_map(fn ($r) => $r['backend_pid'], $results)));

    return [
        'results' => $results,
        'distinct_backend_pids' => $pids,
        'ready_pids' => $readyPids,
        'released_at' => $releasedAt,
        'overlapping_request_windows' => $overlap,
        'observed_lock_wait' => $observedWait,
        'blocker_used' => $blockDocumentId !== null,
        'lock_sample_count' => count($samples),
    ];
}

/** Gọi tuần tự (không đua) để chốt các mã lỗi xác định sau khi cuộc đua kết thúc. */
function sequentialCall(string $scenario, array $request, string $evidenceDir, string $actorUsername, string $label): array
{
    $race = runRace($scenario.'/followup-'.$label, [$request], $evidenceDir, null, $actorUsername);

    return $race['results'][0];
}

$GLOBALS['repoRoot'] = str_replace('\\', '/', dirname(__DIR__));

// ---------------------------------------------------------------------------
// Chạy 5 kịch bản bắt buộc.
// ---------------------------------------------------------------------------
$actorUsername = ensureActor();
logLine('Actor: '.$actorUsername.'; run token '.$runToken.'; hold '.$holdMs.'ms');
logLine('DB: '.DB::connection()->getDatabaseName().' @ '.config('database.connections.pgsql.host'));

$scenarios = [];
$failures = [];

$uri = fn (string $documentId) => '/api/v1/inbound-documents/'.$documentId.'/post-receipt';

// ---- S1: same key / same payload -------------------------------------------
$fx = buildReceiptFixture('S1', 5);
$key = 'wwhn190-s1-'.$runToken;
$before = dbSnapshot($fx, [$key]);
$race = runRace('01-same-key-same-payload', [
    ['method' => 'POST', 'uri' => $uri($fx['document_id']), 'if_match' => '1', 'idempotency_key' => $key, 'body' => ['note' => 'same-payload']],
    ['method' => 'POST', 'uri' => $uri($fx['document_id']), 'if_match' => '1', 'idempotency_key' => $key, 'body' => ['note' => 'same-payload']],
], $evidenceDir, null, $actorUsername);
$after = dbSnapshot($fx, [$key]);

$statuses = array_map(fn ($r) => $r['http_status'], $race['results']);
$successes = array_values(array_filter($race['results'], fn ($r) => $r['http_status'] === 200 && ($r['body_replayed_flag'] ?? null) === false));
$replayOrConflict = array_values(array_filter(
    $race['results'],
    fn ($r) => ($r['http_status'] === 200 && ($r['body_replayed_flag'] ?? null) === true)
        || ($r['http_status'] === 409 && in_array($r['error_code'], ['CONCURRENCY_CONFLICT', 'IDEMPOTENCY_CONFLICT'], true))
));
$followup = sequentialCall('01-same-key-same-payload', [
    'method' => 'POST', 'uri' => $uri($fx['document_id']), 'if_match' => '1', 'idempotency_key' => $key, 'body' => ['note' => 'same-payload'],
], $evidenceDir, $actorUsername, 'replay');

$checks = [
    'two_distinct_backend_pids' => count($race['distinct_backend_pids']) === 2,
    'real_contention_observed' => $race['overlapping_request_windows'] || $race['observed_lock_wait'],
    'exactly_one_fresh_success' => count($successes) === 1,
    'loser_is_replay_or_conflict' => count($replayOrConflict) === 1,
    'movement_count_is_one' => $after['movement_count'] === 1,
    'balance_delta_equals_qty' => ($after['balance_on_hand_total'] - $before['balance_on_hand_total']) === $fx['qty'],
    'posting_event_count_is_one' => $after['posting_event_count'] === 1,
    'document_posted_version_2' => ($after['document']['status'] ?? null) === 'POSTED' && ($after['document']['version'] ?? null) === 2,
    'single_idempotency_row_done' => count($after['idempotency_rows']) === 1 && ($after['idempotency_rows'][0]['status'] ?? null) === 'done',
    'sequential_retry_replays_200' => $followup['http_status'] === 200 && $followup['idempotent_replay_header'] === 'true' && ($followup['body_replayed_flag'] ?? null) === true,
    'no_extra_movement_after_retry' => dbSnapshot($fx, [$key])['movement_count'] === 1,
];
$scenarios['01-same-key-same-payload'] = compact('checks', 'statuses', 'before', 'after') + ['race' => $race, 'followup' => $followup, 'fixture' => $fx];
logLine('S1 same key/same payload: statuses='.implode(',', $statuses).' -> '.(in_array(false, $checks, true) ? 'FAIL' : 'PASS'));

// ---- S2: different key / same document -------------------------------------
$fx2 = buildReceiptFixture('S2', 3);
$keyA = 'wwhn190-s2a-'.$runToken;
$keyB = 'wwhn190-s2b-'.$runToken;
$before2 = dbSnapshot($fx2, [$keyA, $keyB]);
$race2 = runRace('02-different-key-same-document', [
    ['method' => 'POST', 'uri' => $uri($fx2['document_id']), 'if_match' => '1', 'idempotency_key' => $keyA, 'body' => ['note' => 'A']],
    ['method' => 'POST', 'uri' => $uri($fx2['document_id']), 'if_match' => '1', 'idempotency_key' => $keyB, 'body' => ['note' => 'B']],
], $evidenceDir, $fx2['document_id'], $actorUsername);
$after2 = dbSnapshot($fx2, [$keyA, $keyB]);

$statuses2 = array_map(fn ($r) => $r['http_status'], $race2['results']);
$success2 = array_values(array_filter($race2['results'], fn ($r) => $r['http_status'] === 200));
$loser2 = array_values(array_filter($race2['results'], fn ($r) => $r['http_status'] !== 200));
// Sau khi phiếu đã POSTED, một key mới với version ĐÚNG phải bị chặn bằng STATE_CONFLICT.
$followup2 = sequentialCall('02-different-key-same-document', [
    'method' => 'POST', 'uri' => $uri($fx2['document_id']), 'if_match' => (string) ($after2['document']['version'] ?? 2),
    'idempotency_key' => 'wwhn190-s2c-'.$runToken, 'body' => ['note' => 'C'],
], $evidenceDir, $actorUsername, 'state-conflict');

$checks2 = [
    'two_distinct_backend_pids' => count($race2['distinct_backend_pids']) === 2,
    'real_contention_observed' => $race2['overlapping_request_windows'] || $race2['observed_lock_wait'],
    'exactly_one_success' => count($success2) === 1,
    'loser_rejected_with_412_or_409' => count($loser2) === 1
        && (($loser2[0]['http_status'] === 412 && $loser2[0]['error_code'] === 'STALE_VERSION')
            || ($loser2[0]['http_status'] === 409 && in_array($loser2[0]['error_code'], ['STATE_CONFLICT', 'CONCURRENCY_CONFLICT'], true))),
    'movement_count_is_one' => $after2['movement_count'] === 1,
    'balance_delta_equals_qty' => ($after2['balance_on_hand_total'] - $before2['balance_on_hand_total']) === $fx2['qty'],
    'posting_event_count_is_one' => $after2['posting_event_count'] === 1,
    'loser_key_left_no_done_row' => count(array_filter($after2['idempotency_rows'], fn ($r) => $r['status'] === 'done')) === 1,
    'followup_new_key_correct_version_is_state_conflict' => $followup2['http_status'] === 409 && $followup2['error_code'] === 'STATE_CONFLICT',
    'no_extra_movement_after_followup' => dbSnapshot($fx2, [])['movement_count'] === 1,
];
$scenarios['02-different-key-same-document'] = ['checks' => $checks2, 'statuses' => $statuses2, 'before' => $before2, 'after' => $after2, 'race' => $race2, 'followup' => $followup2, 'fixture' => $fx2];
logLine('S2 different key/same doc: statuses='.implode(',', $statuses2).' -> '.(in_array(false, $checks2, true) ? 'FAIL' : 'PASS'));

// ---- S3: stale version ------------------------------------------------------
$fx3 = buildReceiptFixture('S3', 4);
$keyOk = 'wwhn190-s3ok-'.$runToken;
$keyStale = 'wwhn190-s3stale-'.$runToken;
$before3 = dbSnapshot($fx3, [$keyOk, $keyStale]);
$race3 = runRace('03-stale-version', [
    ['method' => 'POST', 'uri' => $uri($fx3['document_id']), 'if_match' => '1', 'idempotency_key' => $keyOk, 'body' => ['note' => 'fresh-version']],
    ['method' => 'POST', 'uri' => $uri($fx3['document_id']), 'if_match' => '99', 'idempotency_key' => $keyStale, 'body' => ['note' => 'stale-version']],
], $evidenceDir, $fx3['document_id'], $actorUsername);
$after3 = dbSnapshot($fx3, [$keyOk, $keyStale]);

$statuses3 = array_map(fn ($r) => $r['http_status'], $race3['results']);
$staleWorker = $race3['results'][1];
$missingIfMatch = sequentialCall('03-stale-version', [
    'method' => 'POST', 'uri' => $uri($fx3['document_id']), 'if_match' => null,
    'idempotency_key' => 'wwhn190-s3nomatch-'.$runToken, 'body' => ['note' => 'no-if-match'],
], $evidenceDir, $actorUsername, 'missing-if-match');
$badIfMatch = sequentialCall('03-stale-version', [
    'method' => 'POST', 'uri' => $uri($fx3['document_id']), 'if_match' => 'not-a-number',
    'idempotency_key' => 'wwhn190-s3bad-'.$runToken, 'body' => ['note' => 'bad-if-match'],
], $evidenceDir, $actorUsername, 'bad-if-match');

$checks3 = [
    'two_distinct_backend_pids' => count($race3['distinct_backend_pids']) === 2,
    'real_contention_observed' => $race3['overlapping_request_windows'] || $race3['observed_lock_wait'],
    'stale_worker_rejected_412_stale_version' => $staleWorker['http_status'] === 412 && $staleWorker['error_code'] === 'STALE_VERSION',
    'fresh_worker_succeeded' => $race3['results'][0]['http_status'] === 200,
    'movement_count_is_one' => $after3['movement_count'] === 1,
    'balance_delta_equals_qty' => ($after3['balance_on_hand_total'] - $before3['balance_on_hand_total']) === $fx3['qty'],
    'stale_key_left_no_row' => count(array_filter($after3['idempotency_rows'], fn ($r) => str_contains($r['idem_key'], 's3stale'))) === 0,
    'missing_if_match_is_428' => $missingIfMatch['http_status'] === 428 && $missingIfMatch['error_code'] === 'PRECONDITION_REQUIRED',
    'bad_if_match_is_422_invalid_version' => $badIfMatch['http_status'] === 422 && $badIfMatch['error_code'] === 'INVALID_VERSION',
    'no_extra_movement_after_followups' => dbSnapshot($fx3, [])['movement_count'] === 1,
];
$scenarios['03-stale-version'] = ['checks' => $checks3, 'statuses' => $statuses3, 'before' => $before3, 'after' => $after3, 'race' => $race3, 'followups' => ['missing_if_match' => $missingIfMatch, 'bad_if_match' => $badIfMatch], 'fixture' => $fx3];
logLine('S3 stale version: statuses='.implode(',', $statuses3).' -> '.(in_array(false, $checks3, true) ? 'FAIL' : 'PASS'));

// ---- S4: same key / different payload ---------------------------------------
$fx4 = buildReceiptFixture('S4', 2);
$key4 = 'wwhn190-s4-'.$runToken;
$before4 = dbSnapshot($fx4, [$key4]);
$race4 = runRace('04-same-key-different-payload', [
    ['method' => 'POST', 'uri' => $uri($fx4['document_id']), 'if_match' => '1', 'idempotency_key' => $key4, 'body' => ['note' => 'payload-A']],
    ['method' => 'POST', 'uri' => $uri($fx4['document_id']), 'if_match' => '1', 'idempotency_key' => $key4, 'body' => ['note' => 'payload-B-different']],
], $evidenceDir, null, $actorUsername);
$after4 = dbSnapshot($fx4, [$key4]);

$statuses4 = array_map(fn ($r) => $r['http_status'], $race4['results']);
$success4 = array_values(array_filter($race4['results'], fn ($r) => $r['http_status'] === 200));
$loser4 = array_values(array_filter($race4['results'], fn ($r) => $r['http_status'] !== 200));
$followup4 = sequentialCall('04-same-key-different-payload', [
    'method' => 'POST', 'uri' => $uri($fx4['document_id']), 'if_match' => '1', 'idempotency_key' => $key4, 'body' => ['note' => 'payload-C-different-again'],
], $evidenceDir, $actorUsername, 'payload-conflict');

$checks4 = [
    'two_distinct_backend_pids' => count($race4['distinct_backend_pids']) === 2,
    'real_contention_observed' => $race4['overlapping_request_windows'] || $race4['observed_lock_wait'],
    'exactly_one_success' => count($success4) === 1,
    'loser_is_409_idempotency_or_concurrency' => count($loser4) === 1 && $loser4[0]['http_status'] === 409
        && in_array($loser4[0]['error_code'], ['IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT'], true),
    'sequential_payload_conflict_is_409_idempotency_conflict' => $followup4['http_status'] === 409 && $followup4['error_code'] === 'IDEMPOTENCY_CONFLICT',
    'movement_count_is_one' => $after4['movement_count'] === 1,
    'balance_delta_equals_qty' => ($after4['balance_on_hand_total'] - $before4['balance_on_hand_total']) === $fx4['qty'],
    'posting_event_count_is_one' => $after4['posting_event_count'] === 1,
    'no_extra_movement_after_conflict' => dbSnapshot($fx4, [])['movement_count'] === 1,
];
$scenarios['04-same-key-different-payload'] = ['checks' => $checks4, 'statuses' => $statuses4, 'before' => $before4, 'after' => $after4, 'race' => $race4, 'followup' => $followup4, 'fixture' => $fx4];
logLine('S4 same key/different payload: statuses='.implode(',', $statuses4).' -> '.(in_array(false, $checks4, true) ? 'FAIL' : 'PASS'));

// ---- S5: forced rollback ----------------------------------------------------
// Fault injection ở tầng DB (không sửa code runtime): trigger raise exception khi
// chứng từ fixture chuyển POSTED — tức là SAU khi movement + balance đã ghi trong
// cùng transaction. Đây đúng tình huống W180-TIMEOUT-001 "failure after business write".
$fx5 = buildReceiptFixture('S5', 6);
$key5a = 'wwhn190-s5a-'.$runToken;
$key5b = 'wwhn190-s5b-'.$runToken;

$relationType = DB::selectOne(
    "select table_type from information_schema.tables where table_schema='public' and table_name='stock_documents'"
);
if (($relationType->table_type ?? null) !== 'BASE TABLE') {
    fail('S5 cần stock_documents là BASE TABLE để gắn trigger fault injection; thực tế: '.($relationType->table_type ?? 'không tìm thấy'));
}

DB::unprepared(<<<SQL
create or replace function wwhn190_force_rollback() returns trigger as $$
begin
  if new.id = '{$fx5['document_id']}' and new.status = 'POSTED' then
    raise exception 'WWHN190_FORCED_ROLLBACK';
  end if;
  return new;
end;
$$ language plpgsql;
drop trigger if exists wwhn190_force_rollback_trg on stock_documents;
create trigger wwhn190_force_rollback_trg before update on stock_documents
for each row execute function wwhn190_force_rollback();
SQL);
logLine('S5: đã gắn trigger fault injection wwhn190_force_rollback_trg');

$before5 = dbSnapshot($fx5, [$key5a, $key5b]);
$race5 = runRace('05-forced-rollback', [
    ['method' => 'POST', 'uri' => $uri($fx5['document_id']), 'if_match' => '1', 'idempotency_key' => $key5a, 'body' => ['note' => 'rollback-A']],
    ['method' => 'POST', 'uri' => $uri($fx5['document_id']), 'if_match' => '1', 'idempotency_key' => $key5b, 'body' => ['note' => 'rollback-B']],
], $evidenceDir, $fx5['document_id'], $actorUsername);
$duringFault = dbSnapshot($fx5, [$key5a, $key5b]);

DB::unprepared('drop trigger if exists wwhn190_force_rollback_trg on stock_documents; drop function if exists wwhn190_force_rollback();');
logLine('S5: đã gỡ trigger fault injection');

$retry5 = sequentialCall('05-forced-rollback', [
    'method' => 'POST', 'uri' => $uri($fx5['document_id']), 'if_match' => '1', 'idempotency_key' => $key5a, 'body' => ['note' => 'rollback-A'],
], $evidenceDir, $actorUsername, 'retry-after-fault-cleared');
$after5 = dbSnapshot($fx5, [$key5a, $key5b]);

$statuses5 = array_map(fn ($r) => $r['http_status'], $race5['results']);
$checks5 = [
    'two_distinct_backend_pids' => count($race5['distinct_backend_pids']) === 2,
    'no_worker_succeeded_during_fault' => count(array_filter($race5['results'], fn ($r) => $r['http_status'] === 200)) === 0,
    'no_movement_written_during_fault' => $duringFault['movement_count'] === 0,
    'balance_unchanged_during_fault' => $duringFault['balance_on_hand_total'] === $before5['balance_on_hand_total'],
    'document_still_scanning_version_1' => ($duringFault['document']['status'] ?? null) === 'SCANNING' && ($duringFault['document']['version'] ?? null) === 1,
    'no_posting_event_during_fault' => $duringFault['posting_event_count'] === 0,
    'lease_released_no_processing_row' => count(array_filter($duringFault['idempotency_rows'], fn ($r) => $r['status'] === 'processing')) === 0,
    'retry_after_fault_cleared_succeeds' => $retry5['http_status'] === 200,
    'exactly_one_movement_after_retry' => $after5['movement_count'] === 1,
    'balance_delta_equals_qty_after_retry' => ($after5['balance_on_hand_total'] - $before5['balance_on_hand_total']) === $fx5['qty'],
];
$scenarios['05-forced-rollback'] = ['checks' => $checks5, 'statuses' => $statuses5, 'before' => $before5, 'during_fault' => $duringFault, 'after' => $after5, 'race' => $race5, 'retry' => $retry5, 'fixture' => $fx5];
logLine('S5 forced rollback: statuses='.implode(',', $statuses5).' -> '.(in_array(false, $checks5, true) ? 'FAIL' : 'PASS'));

// ---------------------------------------------------------------------------
// Tổng hợp
// ---------------------------------------------------------------------------
foreach ($scenarios as $name => $scenario) {
    foreach ($scenario['checks'] as $check => $passed) {
        if (! $passed) {
            $failures[] = $name.'::'.$check;
        }
    }
}

$commit = trim((string) @shell_exec('git rev-parse HEAD')) ?: 'unknown';
$summary = [
    'jira_key' => 'WWHN-190',
    'register_item' => 'DEV190-P1-02R2',
    'status' => $failures === [] ? 'PASS' : 'FAIL',
    'commit_sha' => $commit,
    'run_token' => $runToken,
    'database' => DB::connection()->getDatabaseName(),
    'driver' => 'pgsql',
    'postgres_version' => DB::selectOne('select version() as v')->v,
    'php_version' => PHP_VERSION,
    'lock_hold_ms' => $holdMs,
    'scenario_count' => count($scenarios),
    'failed_checks' => $failures,
    'scenarios' => $scenarios,
    'generated_at' => now()->toIso8601String(),
];

file_put_contents(
    $evidenceDir.'/CONCURRENCY_EVIDENCE.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
);
file_put_contents($evidenceDir.'/concurrency-run.txt', implode("\n", $rawLog)."\n");

logLine('KẾT QUẢ: '.$summary['status'].' — '.count($failures).' check đỏ.');

exit($failures === [] ? 0 : 1);
