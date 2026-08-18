<?php

declare(strict_types=1);

/**
 * WWHN-188 (HN-IN-002, BE1) runtime concurrency worker.
 *
 * Reviewer finding DEV188-P1-02 rejected the existing evidence
 * (`InboundScanConcurrencyBehaviorTest`) because it replays two HTTP requests
 * sequentially against a single SQLite connection — real contention between two
 * independent PostgreSQL backends was never observed. This worker is the
 * executed-proof counterpart: one OS process, one real PostgreSQL backend
 * connection, calling the exact same production code path
 * (`CurrentBaselineStockFlowController::inboundScan()`) that the HTTP route
 * dispatches to. Pattern and barrier/lock-sampling mechanics mirror the
 * already-accepted `scripts/wwhn219-concurrency-worker.php` for WWHN-219.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\CurrentBaselineStockFlowController;

require __DIR__.'/../vendor/autoload.php';

$planPath = $argv[1] ?? null;
if ($planPath === null || ! is_file($planPath)) {
    fwrite(STDERR, "Usage: php scripts/wwhn188-inbound-scan-worker.php <plan.json>\n");
    exit(2);
}

/** @var array<string,mixed> $plan */
$plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);

$workerIndex = (int) $plan['worker_index'];
$barrierDir = (string) $plan['barrier_dir'];
$outFile = (string) $plan['out_file'];
$holdMs = (int) ($plan['hold_ms'] ?? 0);
$op = (array) $plan['operation'];
$appName = 'wwhn188-worker-'.$workerIndex;

$result = [
    'worker_index' => $workerIndex,
    'application_name' => $appName,
    'scenario' => (string) $plan['scenario'],
    'backend_pid' => null,
    'outcome' => 'ERROR',
    'http_status' => null,
    'error_code' => null,
    'error_message' => null,
    'lock_wait_ms' => null,
    'response_version' => null,
    'response_scanned_quantity' => null,
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
        throw new RuntimeException('WWHN-188 runtime concurrency evidence requires the pgsql driver.');
    }

    DB::statement("SET application_name = '".$appName."'");
    $result['backend_pid'] = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
} catch (Throwable $e) {
    $result['error_message'] = 'bootstrap: '.$e->getMessage();
    $writeResult();
    exit(3);
}

// ---- Barrier: announce readiness, then block until the orchestrator releases all workers.
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

try {
    $user = User::query()->find((string) $op['actor_id']);
    if ($user === null) {
        throw new RuntimeException('Fixture actor not found: '.$op['actor_id']);
    }
    Auth::guard('api')->setUser($user);

    $request = Request::create(
        '/api/v1/inbound-documents/'.$op['document_id'].'/scan',
        'POST',
        array_filter([
            'line_id' => $op['line_id'],
            'quantity' => $op['quantity'],
            'item_id' => $op['item_id'] ?? null,
        ], static fn ($v): bool => $v !== null)
    );
    $request->headers->set('If-Match', (string) $op['if_match']);
    $request->headers->set('Accept', 'application/json');
    app()->instance('request', $request);

    DB::beginTransaction();

    // Explicit pre-lock on the contended row so the loser genuinely blocks inside
    // PostgreSQL (observable via pg_locks) before the domain code re-acquires the
    // same lock harmlessly within the same transaction/connection.
    $lockWaitStart = microtime(true);
    $contendedTable = (string) ($op['contended_table'] ?? 'stock_documents');
    $contendedKey = (string) ($op['contended_key'] ?? 'id');
    $contendedValue = (string) ($op['contended_value'] ?? $op['document_id']);
    DB::table($contendedTable)->where($contendedKey, $contendedValue)->lockForUpdate()->get();
    $result['lock_wait_ms'] = (int) round((microtime(true) - $lockWaitStart) * 1000);

    if ($holdMs > 0) {
        usleep($holdMs * 1000);
    }

    $controller = app(CurrentBaselineStockFlowController::class);
    $response = $controller->inboundScan($request, (string) $op['document_id']);

    DB::commit();
    $result['outcome'] = 'SUCCESS';
    $result['http_status'] = $response->getStatusCode();
    $body = json_decode((string) $response->getContent(), true);
    $result['response_version'] = data_get($body, 'data.version');
    $result['response_scanned_quantity'] = data_get($body, 'data.scanned_quantity');
} catch (HttpResponseException $e) {
    DB::rollBack();
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
    $result['outcome'] = 'ERROR';
    $result['error_message'] = $e->getMessage();
    $result['error_class'] = $e::class;
}

$writeResult();
exit(0);
