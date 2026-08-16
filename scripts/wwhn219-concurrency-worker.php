<?php

declare(strict_types=1);

/**
 * WWHN-219 runtime concurrency worker.
 *
 * One OS process, one PostgreSQL backend connection. The worker opens an explicit
 * transaction, takes the contended row lock, holds it for `hold_ms` so a monitor
 * connection can observe a real `pg_locks` wait, then executes the canonical
 * domain service inside that same transaction (Laravel nests it as a SAVEPOINT).
 *
 * This is runtime evidence, not source inspection: the proof is two distinct
 * `pg_backend_pid()` values contending for the same row in overlapping wall-clock
 * windows, with the ledger/balance/audit outcome asserted afterwards.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\CurrentBaselineStockService;

require __DIR__.'/../vendor/autoload.php';

$planPath = $argv[1] ?? null;
if ($planPath === null || ! is_file($planPath)) {
    fwrite(STDERR, "Usage: php scripts/wwhn219-concurrency-worker.php <plan.json>\n");
    exit(2);
}

/** @var array<string,mixed> $plan */
$plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);

$workerIndex = (int) $plan['worker_index'];
$barrierDir = (string) $plan['barrier_dir'];
$outFile = (string) $plan['out_file'];
$holdMs = (int) ($plan['hold_ms'] ?? 0);
$operation = (array) $plan['operation'];
$appName = 'wwhn219-worker-'.$workerIndex;

$result = [
    'worker_index' => $workerIndex,
    'application_name' => $appName,
    'scenario' => (string) $plan['scenario'],
    'operation' => (string) $operation['type'],
    'backend_pid' => null,
    'outcome' => 'ERROR',
    'http_status' => null,
    'error_code' => null,
    'error_message' => null,
    'lock_wait_ms' => null,
    'timeline' => [],
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
        throw new RuntimeException('WWHN-219 runtime concurrency evidence requires the pgsql driver.');
    }

    // Name the connection so the monitor can attribute pg_locks rows to this worker.
    DB::statement("SET application_name = '".$appName."'");
    $result['backend_pid'] = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    $result['timeline']['booted_at'] = microtime(true);
} catch (Throwable $e) {
    $result['error_message'] = 'bootstrap: '.$e->getMessage();
    $writeResult();
    exit(3);
}

// ---- Barrier: announce readiness, then block until the orchestrator releases all workers.
file_put_contents($barrierDir.'/ready-'.$workerIndex, (string) $result['backend_pid']);
$goFile = $barrierDir.'/go';
$barrierDeadline = microtime(true) + 60.0;
while (! is_file($goFile)) {
    if (microtime(true) > $barrierDeadline) {
        $result['error_message'] = 'barrier timeout waiting for orchestrator release';
        $writeResult();
        exit(4);
    }
    usleep(1000);
}
$result['timeline']['released_at'] = microtime(true);

try {
    DB::beginTransaction();

    // Explicitly contend for the authoritative row before touching the domain
    // service. Whichever backend wins holds the lock for hold_ms; the loser
    // genuinely blocks here inside PostgreSQL, which is what the monitor samples.
    $lockWaitStart = microtime(true);
    $contendedTable = (string) ($operation['contended_table'] ?? 'stock_documents');
    $contendedKey = (string) ($operation['contended_key'] ?? 'id');
    $contendedValue = (string) ($operation['contended_value'] ?? $operation['document_id']);
    DB::table($contendedTable)
        ->where($contendedKey, $contendedValue)
        ->lockForUpdate()
        ->get();
    $result['lock_wait_ms'] = (int) round((microtime(true) - $lockWaitStart) * 1000);
    $result['timeline']['lock_acquired_at'] = microtime(true);

    if ($holdMs > 0) {
        usleep($holdMs * 1000);
    }
    $result['timeline']['hold_released_at'] = microtime(true);

    $payload = match ($operation['type']) {
        'post_receipt', 'post_issue' => app(CurrentBaselineStockService::class)->postStockDocument(
            (string) $operation['document_id'],
            (string) $operation['document_type'],
            $operation['actor_id'] === null ? null : (string) $operation['actor_id'],
            (string) $operation['idempotency_key'],
            (string) $operation['request_hash'],
        ),
        'reverse' => app(CurrentBaselineStockService::class)->reverseStockDocument(
            (string) $operation['document_id'],
            (string) $operation['document_type'],
            (array) $operation['payload'],
            $operation['actor_id'] === null ? null : (string) $operation['actor_id'],
            (string) $operation['idempotency_key'],
            (string) $operation['request_hash'],
        ),
        default => throw new RuntimeException('Unknown operation type: '.$operation['type']),
    };

    DB::commit();
    $result['outcome'] = 'SUCCESS';
    $result['http_status'] = 200;
    $result['payload'] = $payload;
} catch (HttpResponseException $e) {
    DB::rollBack();
    $response = $e->getResponse();
    $result['outcome'] = 'REJECTED';
    $result['http_status'] = $response->getStatusCode();
    $decoded = json_decode((string) $response->getContent(), true);
    $result['error_code'] = is_array($decoded) ? ($decoded['error_code'] ?? null) : null;
    $result['error_message'] = is_array($decoded) ? ($decoded['message'] ?? null) : null;
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result['outcome'] = 'ERROR';
    $result['error_message'] = $e->getMessage();
    $result['error_class'] = $e::class;
}

$result['timeline']['finished_at'] = microtime(true);
$writeResult();

// The orchestrator, not the worker, decides whether the race as a whole passed.
exit(0);
