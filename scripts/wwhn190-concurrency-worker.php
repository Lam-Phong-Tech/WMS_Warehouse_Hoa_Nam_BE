<?php

declare(strict_types=1);

/**
 * WWHN-190 / DEV190-P1-02R2 — worker cho bằng chứng concurrency thật.
 *
 * Một OS process = một backend PostgreSQL riêng. Khác với worker WWHN-219 (gọi thẳng
 * domain service), worker này bắn **HTTP request thật qua HTTP kernel**, vì ba tầng
 * cần chứng minh của DEV190-P1-02R2 nằm ở controller/middleware chứ không ở service:
 *
 *   1. `If-Match`  -> 428 PRECONDITION_REQUIRED / 422 INVALID_VERSION / 412 STALE_VERSION
 *   2. `Idempotency-Key` + request hash -> replay 200 vs 409 IDEMPOTENCY_CONFLICT
 *   3. lease `idempotency_keys` -> 409 CONCURRENCY_CONFLICT khi hai request cùng key chồng nhau
 *
 * Worker chỉ ghi lại kết quả quan sát được; orchestrator mới là nơi phán xét pass/fail.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;

require __DIR__.'/../vendor/autoload.php';

$planPath = $argv[1] ?? null;
if ($planPath === null || ! is_file($planPath)) {
    fwrite(STDERR, "Usage: php scripts/wwhn190-concurrency-worker.php <plan.json>\n");
    exit(2);
}

/** @var array<string,mixed> $plan */
$plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);

$workerIndex = (int) $plan['worker_index'];
$scenario = (string) $plan['scenario'];
$barrierDir = (string) $plan['barrier_dir'];
$outFile = (string) $plan['out_file'];
$request = (array) $plan['request'];
$appName = 'wwhn190-w'.$workerIndex;

$result = [
    'worker_index' => $workerIndex,
    'scenario' => $scenario,
    'application_name' => $appName,
    'backend_pid' => null,
    'http_status' => null,
    'error_code' => null,
    'message' => null,
    'idempotent_replay_header' => null,
    'etag' => null,
    'body_replayed_flag' => null,
    'movement_ids' => [],
    'request' => [
        'method' => $request['method'],
        'uri' => $request['uri'],
        'if_match' => $request['if_match'] ?? null,
        'idempotency_key' => $request['idempotency_key'] ?? null,
        'body' => $request['body'] ?? null,
    ],
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
    $app->make(ConsoleKernel::class)->bootstrap();

    if (DB::connection()->getDriverName() !== 'pgsql') {
        throw new RuntimeException('WWHN-190 concurrency evidence yêu cầu DB_CONNECTION=pgsql.');
    }

    DB::statement("SET application_name = '".$appName."'");
    $result['backend_pid'] = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;

    $actor = User::query()->where('username', (string) $plan['actor_username'])->first();
    if (! $actor instanceof User) {
        throw new RuntimeException('Không tìm thấy actor '.$plan['actor_username']);
    }
    $token = auth('api')->login($actor);
    $result['timeline']['booted_at'] = microtime(true);
} catch (Throwable $e) {
    $result['message'] = 'bootstrap: '.$e->getMessage();
    $writeResult();
    exit(3);
}

// ---- Barrier: báo sẵn sàng rồi chờ orchestrator thả cả nhóm cùng lúc.
file_put_contents($barrierDir.'/ready-'.$workerIndex, (string) $result['backend_pid']);
$goFile = $barrierDir.'/go';
$deadline = microtime(true) + 60.0;
while (! is_file($goFile)) {
    if (microtime(true) > $deadline) {
        $result['message'] = 'barrier timeout';
        $writeResult();
        exit(4);
    }
    usleep(500);
}
$result['timeline']['released_at'] = microtime(true);

try {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ];
    if (array_key_exists('if_match', $request) && $request['if_match'] !== null) {
        $server['HTTP_IF_MATCH'] = (string) $request['if_match'];
    }
    if (array_key_exists('idempotency_key', $request) && $request['idempotency_key'] !== null) {
        $server['HTTP_IDEMPOTENCY_KEY'] = (string) $request['idempotency_key'];
    }

    $content = json_encode($request['body'] ?? new stdClass, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $httpRequest = Request::create(
        (string) $request['uri'],
        strtoupper((string) $request['method']),
        [],
        [],
        [],
        $server,
        $content
    );

    $result['timeline']['request_started_at'] = microtime(true);
    /** @var HttpKernel $kernel */
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($httpRequest);
    $result['timeline']['request_finished_at'] = microtime(true);

    $result['http_status'] = $response->getStatusCode();
    $result['idempotent_replay_header'] = $response->headers->get('Idempotent-Replay');
    $result['etag'] = $response->headers->get('ETag');

    $decoded = json_decode((string) $response->getContent(), true);
    if (is_array($decoded)) {
        $result['error_code'] = $decoded['error_code'] ?? null;
        $result['message'] = $decoded['message'] ?? null;
        $result['body_replayed_flag'] = $decoded['data']['replayed'] ?? null;
        $result['movement_ids'] = $decoded['data']['movement_ids'] ?? [];
        $result['body'] = $decoded;
    } else {
        $result['message'] = substr((string) $response->getContent(), 0, 2000);
    }

    $kernel->terminate($httpRequest, $response);
} catch (Throwable $e) {
    $result['http_status'] = $result['http_status'] ?? 0;
    $result['message'] = 'exception: '.$e->getMessage();
    $result['error_class'] = $e::class;
}

$result['timeline']['finished_at'] = microtime(true);
$writeResult();

exit(0);
