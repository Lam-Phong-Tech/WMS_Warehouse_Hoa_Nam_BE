<?php

declare(strict_types=1);

/**
 * WWHN-198 (task cha WWHN-46 / HN-OUT-002) — worker cho race reservation outbound.
 *
 * Một OS process = một backend PostgreSQL độc lập. Worker mở transaction tường
 * minh, giành row lock trên chính hàng `item_instances` mà `resolveItem()` khoá
 * (lockForUpdate), giữ lock `hold_ms` để monitor connection quan sát được lock
 * wait thật trong pg_locks, rồi mới chạy đúng domain service canonical trong cùng
 * transaction đó (Laravel lồng thành SAVEPOINT).
 *
 * Đây là bằng chứng runtime chứ không phải đọc source: hai `pg_backend_pid()`
 * khác nhau tranh cùng một hàng trong cùng cửa sổ wall-clock, và kết quả đã
 * commit (match ACTIVE / scanned_qty / tồn kho) được assert sau đó.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Services\CurrentBaselineStockService;

require __DIR__.'/../vendor/autoload.php';

$planPath = $argv[1] ?? null;
if ($planPath === null || ! is_file($planPath)) {
    fwrite(STDERR, "Usage: php scripts/wwhn198-outbound-reservation-worker.php <plan.json>\n");
    exit(2);
}

/** @var array<string,mixed> $plan */
$plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);

$workerIndex = (int) $plan['worker_index'];
$barrierDir = (string) $plan['barrier_dir'];
$outFile = (string) $plan['out_file'];
$holdMs = (int) ($plan['hold_ms'] ?? 0);
$operation = (array) $plan['operation'];
$appName = 'wwhn198-worker-'.$workerIndex;

$result = [
    'worker_index' => $workerIndex,
    'application_name' => $appName,
    'scenario' => (string) $plan['scenario'],
    'operation' => (string) $operation['type'],
    'document_id' => $operation['document_id'] ?? null,
    'item_id' => $operation['item_id'] ?? null,
    'backend_pid' => null,
    'outcome' => 'ERROR',
    'http_status' => null,
    'error_code' => null,
    'sqlstate' => null,
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
        throw new RuntimeException('WWHN-198 reservation race evidence requires the pgsql driver.');
    }

    DB::statement("SET application_name = '".$appName."'");
    $result['backend_pid'] = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    $result['timeline']['booted_at'] = microtime(true);
} catch (Throwable $e) {
    $result['error_message'] = 'bootstrap: '.$e->getMessage();
    $writeResult();
    exit(3);
}

// ---- Barrier: báo sẵn sàng rồi chặn tới khi orchestrator thả tất cả cùng lúc.
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

    // Giành hàng tranh chấp trước khi chạm domain service. Backend thắng giữ lock
    // hold_ms; backend thua thật sự block bên trong PostgreSQL — đó chính là thứ
    // monitor lấy mẫu được.
    $lockWaitStart = microtime(true);
    DB::table((string) ($operation['contended_table'] ?? 'item_instances'))
        ->where((string) ($operation['contended_key'] ?? 'item_id'), (string) $operation['contended_value'])
        ->lockForUpdate()
        ->get();
    $result['lock_wait_ms'] = (int) round((microtime(true) - $lockWaitStart) * 1000);
    $result['timeline']['lock_acquired_at'] = microtime(true);

    if ($holdMs > 0) {
        usleep($holdMs * 1000);
    }
    $result['timeline']['hold_released_at'] = microtime(true);

    switch ($operation['type']) {
        case 'outbound_scan':
            $payload = app(CurrentBaselineStockService::class)->scanStockDocument(
                (string) $operation['document_id'],
                'issue',
                [
                    'line_id' => (string) $operation['line_id'],
                    'quantity' => (int) ($operation['quantity'] ?? 1),
                    'item_id' => (string) $operation['item_id'],
                ],
                $operation['actor_id'] === null ? null : (string) $operation['actor_id'],
            );
            break;

        // Bỏ qua toàn bộ tầng application để chứng minh unique partial index của
        // PostgreSQL tự nó đã là hàng rào cuối cùng, kể cả khi guard ứng dụng bị
        // bypass (migration/bulk script/regression tương lai).
        case 'raw_active_match_insert':
            DB::table('outbound_item_matches')->insert([
                'id' => (string) Str::uuid(),
                'outbound_line_id' => (string) $operation['line_id'],
                'item_id' => (string) $operation['item_id'],
                'container_id' => null,
                'quantity' => 1,
                'status' => 'ACTIVE',
                'matched_at' => now(),
                'matched_by' => $operation['actor_id'] === null ? null : (string) $operation['actor_id'],
                'created_at' => now(),
                'created_by' => $operation['actor_id'] === null ? null : (string) $operation['actor_id'],
                'updated_at' => now(),
                'updated_by' => $operation['actor_id'] === null ? null : (string) $operation['actor_id'],
                'version' => 0,
            ]);
            $payload = ['raw_insert' => true];
            break;

        default:
            throw new RuntimeException('Unknown operation type: '.$operation['type']);
    }

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
    $result['error_code'] = is_array($decoded)
        ? ($decoded['error']['code'] ?? $decoded['error_code'] ?? null)
        : null;
    $result['error_message'] = is_array($decoded)
        ? ($decoded['error']['message'] ?? $decoded['message'] ?? null)
        : null;
} catch (Illuminate\Database\QueryException $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // 23505 = unique_violation: bằng chứng index chặn ở tầng DB, không phải app.
    $result['outcome'] = 'DB_CONSTRAINT_REJECTED';
    $result['sqlstate'] = (string) ($e->errorInfo[0] ?? '');
    $result['error_message'] = $e->getMessage();
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

// Orchestrator mới là nơi quyết định race tổng thể pass hay fail.
exit(0);
