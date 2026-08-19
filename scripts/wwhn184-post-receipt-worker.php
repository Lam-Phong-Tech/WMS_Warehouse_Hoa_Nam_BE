<?php

declare(strict_types=1);

/**
 * WWHN-184 (DEV184-P1-03R) runtime concurrency worker cho Post Receipt.
 *
 * Reviewer từ chối bằng chứng race hiện có của luồng Post vì "DEV race chỉ SQLite
 * stale replay": `PostReceiptConcurrencyBehaviorTest` phát lại tuần tự hai request
 * HTTP trên MỘT connection sqlite, nên hai backend độc lập chưa bao giờ thực sự
 * tranh chấp. WWHN-188 đã dựng race thật cho `inboundScan()`; luồng
 * `postReceipt()` — luồng DUY NHẤT ghi `stock_movements`/`inventory_balances` —
 * thì chưa. Worker này là nửa còn thiếu đó.
 *
 * Một tiến trình OS = một PostgreSQL backend connection, gọi thẳng production code
 * path mà route dispatch tới. Cơ chế barrier/pre-lock/timestamp thô sao đúng
 * `scripts/wwhn188-inbound-scan-worker.php` đã được chấp nhận.
 *
 * `operation.action`:
 *   - `post_receipt` -> CurrentBaselineStockFlowController::postReceipt()
 *   - `cancel`       -> CurrentBaselineDocumentController::cancelInbound()
 *
 * `operation.mode`:
 *   - `locked` — worker mở transaction riêng và pre-lock hàng tranh chấp để bên
 *     thua thực sự block trong PostgreSQL (quan sát được ở pg_locks).
 *   - `plain`  — không transaction ngoài, không pre-lock: dùng cho kịch bản chia
 *     sẻ Idempotency-Key, nơi điểm tranh chấp chính là lease `idempotency_keys`;
 *     bọc thêm transaction ngoài sẽ làm sai lệch chính thứ đang được đo.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\CurrentBaselineDocumentController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineStockFlowController;

require __DIR__.'/../vendor/autoload.php';

$planPath = $argv[1] ?? null;
if ($planPath === null || ! is_file($planPath)) {
    fwrite(STDERR, "Usage: php scripts/wwhn184-post-receipt-worker.php <plan.json>\n");
    exit(2);
}

/** @var array<string,mixed> $plan */
$plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);

$workerIndex = (int) $plan['worker_index'];
$barrierDir = (string) $plan['barrier_dir'];
$outFile = (string) $plan['out_file'];
$holdMs = (int) ($plan['hold_ms'] ?? 0);
$op = (array) $plan['operation'];
$mode = (string) ($op['mode'] ?? 'locked');
$action = (string) ($op['action'] ?? 'post_receipt');
$appName = 'wwhn184-worker-'.$workerIndex;

/** Mốc thời gian thô, chính xác tới micro giây, để chứng minh hai tiến trình chồng lấn thật. */
$stamp = static function (): string {
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP');
};

$result = [
    'worker_index' => $workerIndex,
    'application_name' => $appName,
    'scenario' => (string) $plan['scenario'],
    'action' => $action,
    'mode' => $mode,
    'backend_pid' => null,
    'os_pid' => getmypid(),
    'outcome' => 'ERROR',
    'http_status' => null,
    'error_code' => null,
    'error_message' => null,
    'lock_wait_ms' => null,
    'response_status' => null,
    'response_version' => null,
    'response_replayed' => null,
    'idempotent_replay_header' => null,
    'etag_header' => null,
    'idempotency_key' => $op['idempotency_key'] ?? null,
    'if_match' => $op['if_match'] ?? null,
    'process_started_at' => $stamp(),
    'barrier_released_at' => null,
    'lock_wait_started_at' => null,
    'lock_acquired_at' => null,
    'request_started_at' => null,
    'request_finished_at' => null,
];

$writeResult = static function () use (&$result, $outFile): void {
    file_put_contents(
        $outFile,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
    );
};

try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (DB::connection()->getDriverName() !== 'pgsql') {
        throw new RuntimeException('WWHN-184 runtime concurrency evidence requires the pgsql driver.');
    }

    DB::statement("SET application_name = '".$appName."'");
    $result['backend_pid'] = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
} catch (Throwable $e) {
    $result['error_message'] = 'bootstrap: '.$e->getMessage();
    $writeResult();
    exit(3);
}

// ---- Barrier: báo sẵn sàng, rồi chờ orchestrator thả cùng lúc.
file_put_contents($barrierDir.'/ready-'.$workerIndex, (string) $result['backend_pid']);
$goFile = $barrierDir.'/go';
$deadline = microtime(true) + 60.0;
while (! is_file($goFile)) {
    if (microtime(true) > $deadline) {
        $result['error_message'] = 'barrier timeout waiting for orchestrator release';
        $writeResult();
        exit(4);
    }
    usleep(1000);
}
$result['barrier_released_at'] = $stamp();

try {
    $user = User::query()->find((string) $op['actor_id']);
    if ($user === null) {
        throw new RuntimeException('Fixture actor not found: '.$op['actor_id']);
    }
    Auth::guard('api')->setUser($user);

    $documentId = (string) $op['document_id'];
    $path = $action === 'cancel'
        ? '/api/v1/inbound-documents/'.$documentId.'/cancel'
        : '/api/v1/inbound-documents/'.$documentId.'/post-receipt';
    $body = $action === 'cancel' ? ['reason' => (string) ($op['reason'] ?? 'WWHN-184 race cancel')] : [];

    $request = Request::create($path, 'POST', $body);
    $request->headers->set('If-Match', (string) $op['if_match']);
    $request->headers->set('Accept', 'application/json');
    if (($op['idempotency_key'] ?? null) !== null) {
        $request->headers->set('Idempotency-Key', (string) $op['idempotency_key']);
    }
    app()->instance('request', $request);

    if ($mode === 'locked') {
        DB::beginTransaction();

        // Pre-lock tường minh trên hàng tranh chấp để bên thua thực sự block trong
        // PostgreSQL trước khi domain code tái chiếm cùng lock (vô hại vì cùng
        // transaction/connection). Mốc lock_wait_started_at/lock_acquired_at là
        // ranh giới cửa sổ "engage DB" của worker: bên thua CHỒNG LẤN với request
        // của bên thắng ở trạng thái blocked-on-lock, không phải ở cửa sổ request
        // (pre-lock cùng hàng serialize cửa sổ request theo thiết kế).
        $result['lock_wait_started_at'] = $stamp();
        $lockWaitStart = microtime(true);
        $contendedTable = (string) ($op['contended_table'] ?? 'stock_documents');
        $contendedKey = (string) ($op['contended_key'] ?? 'id');
        $contendedValue = (string) ($op['contended_value'] ?? $documentId);
        DB::table($contendedTable)->where($contendedKey, $contendedValue)->lockForUpdate()->get();
        $result['lock_wait_ms'] = (int) round((microtime(true) - $lockWaitStart) * 1000);
        $result['lock_acquired_at'] = $stamp();

        if ($holdMs > 0) {
            usleep($holdMs * 1000);
        }
    }

    $result['request_started_at'] = $stamp();
    $response = $action === 'cancel'
        ? app(CurrentBaselineDocumentController::class)->cancelInbound($request, $documentId)
        : app(CurrentBaselineStockFlowController::class)->postReceipt($request, $documentId);

    if ($mode === 'locked') {
        DB::commit();
    }
    $result['request_finished_at'] = $stamp();

    $status = $response->getStatusCode();
    $decoded = json_decode((string) $response->getContent(), true);
    $result['http_status'] = $status;
    $result['idempotent_replay_header'] = $response->headers->get('Idempotent-Replay');
    $result['etag_header'] = $response->headers->get('ETag');

    if ($status >= 200 && $status < 300) {
        $result['outcome'] = 'SUCCESS';
        $result['response_status'] = data_get($decoded, 'data.status');
        $result['response_version'] = data_get($decoded, 'data.version');
        $result['response_replayed'] = data_get($decoded, 'data.replayed');
    } else {
        // IdempotencyStore trả 409 bằng body/status chứ không ném exception.
        $result['outcome'] = 'REJECTED';
        $result['error_code'] = is_array($decoded) ? ($decoded['error']['code'] ?? ($decoded['error_code'] ?? null)) : null;
        $result['error_message'] = is_array($decoded) ? ($decoded['message'] ?? null) : null;
    }
} catch (HttpResponseException $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result['request_finished_at'] = $stamp();
    $response = $e->getResponse();
    $result['outcome'] = 'REJECTED';
    $result['http_status'] = $response->getStatusCode();
    $decoded = json_decode((string) $response->getContent(), true);
    $result['error_code'] = is_array($decoded) ? ($decoded['error']['code'] ?? ($decoded['error_code'] ?? null)) : null;
    $result['error_message'] = is_array($decoded) ? ($decoded['message'] ?? null) : null;
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result['request_finished_at'] = $stamp();
    $result['outcome'] = 'ERROR';
    $result['error_message'] = $e->getMessage();
    $result['error_class'] = $e::class;
}

$writeResult();
exit(0);
