<?php

/**
 * Đo POST /api/v1/mini-app/inbound/record với N dòng quét.
 * Chạy trong transaction ngoài rồi rollback -> không bẩn DB dev.
 *
 * docker compose exec -T backend php scripts/perf/measure-mini-inbound-record.php 30
 *
 * In ra: thời gian request, số câu SQL, tổng thời gian SQL, và top câu lệnh theo
 * tổng thời gian (gom theo hình dạng câu lệnh).
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);
$app->make(ConsoleKernel::class)->bootstrap();

$n = (int) ($argv[1] ?? 10);

$user = DB::table('app_users')->where('email', 'sad-01@gmail.com')->first()
    ?? DB::table('app_users')->whereNotNull('email')->first();
if (! $user) {
    fwrite(STDERR, "no user\n");
    exit(1);
}

$warehouse = DB::table('warehouses')->where('code', 'KHO-TONG-HN')->first();
if (! $warehouse) {
    fwrite(STDERR, "no canonical warehouse\n");
    exit(1);
}

$token = app('tymon.jwt.auth')->fromUser(
    User::query()->find($user->id)
);

$sku = DB::table('sku')->where('sku_type', 'PRODUCT')->where('status', 'ACTIVE')->first();
if (! $sku) {
    fwrite(STDERR, "no sku\n");
    exit(1);
}

$tag = strtoupper(Str::random(6));
$items = [];
for ($i = 1; $i <= $n; $i++) {
    $u = 'PERF'.$tag.'-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $items[] = [
        'raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM='.$u.'|SER='.$u,
        'scan_source' => 'CAMERA',
        'item_type' => 'ITEM',
        'quantity' => 1,
    ];
}

$payload = [
    'name' => 'PERF probe '.$tag,
    'dst_warehouse_id' => (string) $warehouse->id,
    'items' => $items,
];

// ── profiling ──────────────────────────────────────────────────────────
$queries = [];
DB::listen(function ($q) use (&$queries) {
    $queries[] = ['sql' => $q->sql, 'ms' => $q->time];
});

DB::beginTransaction();

$t0 = microtime(true);
$request = Request::create(
    '/api/v1/mini-app/inbound/record', 'POST',
    [], [], [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_IDEMPOTENCY_KEY' => 'perf-'.$tag,
    ],
    json_encode($payload)
);
$response = $kernel->handle($request);
$elapsed = (microtime(true) - $t0) * 1000;

DB::rollBack();

$status = $response->getStatusCode();
$body = json_decode($response->getContent(), true);

$totalQueryMs = array_sum(array_column($queries, 'ms'));

// gom theo "hình dạng" câu lệnh
$groups = [];
foreach ($queries as $q) {
    $shape = preg_replace('/\s+/', ' ', substr($q['sql'], 0, 110));
    $groups[$shape]['n'] = ($groups[$shape]['n'] ?? 0) + 1;
    $groups[$shape]['ms'] = ($groups[$shape]['ms'] ?? 0) + $q['ms'];
}
uasort($groups, fn ($a, $b) => $b['ms'] <=> $a['ms']);

printf("items=%d status=%d wall=%.0fms queries=%d queryTime=%.0fms\n",
    $n, $status, $elapsed, count($queries), $totalQueryMs);
if ($status >= 400) {
    echo substr($response->getContent(), 0, 900), "\n";
}
printf("%-6s %-9s %s\n", 'COUNT', 'MS', 'SQL');
$i = 0;
foreach ($groups as $shape => $g) {
    printf("%-6d %-9.1f %s\n", $g['n'], $g['ms'], $shape);
    if (++$i >= 25) {
        break;
    }
}
