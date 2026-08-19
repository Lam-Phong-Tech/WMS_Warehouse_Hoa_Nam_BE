<?php

declare(strict_types=1);

/**
 * WWHN-240 / WWHN-241 — QA240-19 "concurrent HTTP reversal race" (register R3
 * `DEV240-P1-QA-R3`, hard gate).
 *
 * Khác với scripts/wwhn239-reversal-item-race.php (2 OS process race thẳng vào
 * CurrentBaselineReversalService — đã PASS, giữ làm bằng chứng tầng service),
 * script này đi đúng câu chữ runbook QA240-19: **hai request HTTP thật, đồng
 * thời**, cùng movement_id + cùng item_id, KHÁC Idempotency-Key, cùng If-Match,
 * bắn qua curl_multi vào một HTTP server thật (php -S với
 * PHP_CLI_SERVER_WORKERS>1). Toàn bộ fixture dựng qua HTTP (create → scan →
 * post-receipt), không viết movement tay.
 *
 * Sau race, xác minh đủ các bề mặt mà gate đòi:
 *   - đúng 1 request 2xx, loser 409 theo ConflictCode
 *     (CONCURRENCY_CONFLICT | REVERSAL_ITEM_ALREADY_REVERSED)
 *   - trace-source: correction_chain 2 node, không trùng node, truncated=false,
 *     net_effect.reversed_qty = 1
 *   - XNT enhanced: receipt_gross/reversal/net + closing khớp ledger
 *     SUM(qty_delta) (bất biến WWHN-240)
 *   - RBAC: staff01 (không có inventory.reversal.post / inventory.trace) bị 403
 *     ở cả reverse lẫn trace-source
 *
 * Run (DB PostgreSQL cô lập đã migrate + AuthDatabaseSeeder, server đã chạy):
 *   PHP_CLI_SERVER_WORKERS=4 DB_CONNECTION=pgsql ... php -S 127.0.0.1:8240 \
 *     vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php &
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=hn_wwhn240_qa19 ... \
 *     WWHN240_BASE_URL=http://127.0.0.1:8240 \
 *     php scripts/wwhn240-qa19-http-race.php [evidenceDir]
 *
 * Exit 0 = PASS, 1 = FAIL, 2 = sai driver/môi trường.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baseUrl = rtrim(getenv('WWHN240_BASE_URL') ?: 'http://127.0.0.1:8240', '/');
$evidenceDir = $argv[1] ?? 'docs/evidence/WWHN-240/runtime-qa19';
@mkdir($evidenceDir, 0777, true);

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "QA240-19 yêu cầu DB_CONNECTION=pgsql (chạy trên PostgreSQL thật).\n");
    exit(2);
}

$checks = [];
$evidence = ['generated_at' => date(DATE_ATOM), 'base_url' => $baseUrl];

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[$name] = ['pass' => $ok, 'detail' => $detail];
    fwrite(STDOUT, sprintf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? " — {$detail}" : ''));
    if (! $ok) {
        fail($name.($detail !== '' ? " — {$detail}" : ''));
    }
}

/** @return array{status: int, json: array<string, mixed>, total_time_ms: float} */
function httpJson(string $baseUrl, string $method, string $path, ?array $body, array $headers): array
{
    $ch = curl_init($baseUrl.$path);
    $headerLines = ['Accept: application/json'];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name.': '.$value;
    }
    if ($body !== null) {
        $headerLines[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        fail("HTTP {$method} {$path}: ".curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $totalMs = 1000.0 * (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode((string) $raw, true) ?: [], 'total_time_ms' => $totalMs];
}

function login(string $baseUrl, string $email, string $password): string
{
    $response = httpJson($baseUrl, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password], []);
    $token = $response['json']['data']['access_token'] ?? null;
    if (! is_string($token) || $token === '') {
        fail("Login {$email} thất bại (status {$response['status']}). DB đã seed AuthDatabaseSeeder chưa?");
    }

    return $token;
}

function errorCode(array $json): ?string
{
    return $json['error']['code'] ?? $json['error_code'] ?? null;
}

// ---------------------------------------------------------------------------
// 0. Server sẵn sàng?
$probe = httpJson($baseUrl, 'POST', '/api/v1/auth/login', ['email' => 'x@x', 'password' => 'x'], []);
if ($probe['status'] === 0 || $probe['status'] >= 500) {
    fwrite(STDERR, "Server {$baseUrl} chưa sẵn sàng (status {$probe['status']}).\n");
    exit(2);
}

$password = getenv('CI_DEMO_PASSWORD') ?: 'Cntt@1234';
// manager01 (WAREHOUSE_MANAGER) dựng fixture — keeper01 KHÔNG có inbound.create
// theo seeder chuẩn; keeper01 (WAREHOUSE_KEEPER, có inventory.reversal.post +
// inventory.trace + report.view) là actor chạy race + trace + XNT.
$managerToken = login($baseUrl, 'manager01@gmail.com', $password);
$fixtureAuth = ['Authorization' => 'Bearer '.$managerToken];
$keeperToken = login($baseUrl, 'keeper01@gmail.com', $password);
$auth = ['Authorization' => 'Bearer '.$keeperToken];

// ---------------------------------------------------------------------------
// 1. Fixture qua HTTP thật: warehouse + SKU serial + 2 item, create → scan → post.
$warehouseId = (string) (DB::table('warehouses')->value('id') ?? '');
if ($warehouseId === '') {
    $warehouseId = (string) Str::uuid();
    DB::table('warehouses')->insert([
        'id' => $warehouseId,
        'code' => 'KHO-TONG-HN',
        'name' => 'Kho tổng Hòa Nam',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$runTag = strtoupper(substr(sha1((string) microtime(true).getmypid()), 0, 6));
$skuCode = 'PRD-QA19-'.$runTag;
$skuId = (string) Str::uuid();
$skuRow = [
    'sku_id' => $skuId,
    'sku_code' => $skuCode,
    'sku_name' => 'WWHN-240 QA19 '.$runTag,
    'sku_type' => 'PRODUCT',
    'unit' => 'cai',
    'is_serial_tracked' => true,
    'status' => 'ACTIVE',
    'version' => 1,
    'created_at' => now(),
    'updated_at' => now(),
];
DB::table('sku')->insert(array_filter(
    $skuRow,
    static fn (string $column): bool => Schema::hasColumn('sku', $column),
    ARRAY_FILTER_USE_KEY
));

$itemIds = [];
for ($index = 0; $index < 2; $index++) {
    $itemId = (string) Str::uuid();
    DB::table('item_instances')->insert([
        'item_id' => $itemId,
        'sku_id' => $skuId,
        'serial_number' => 'SN240QA19-'.$runTag.'-'.$index,
        'item_status' => 'NEW',
        'item_condition' => 'NORMAL',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $itemIds[] = $itemId;
}

$create = httpJson($baseUrl, 'POST', '/api/v1/inbound-documents', [
    'dst_warehouse_id' => $warehouseId,
    'source_name' => 'WWHN-240 QA240-19 fixture',
    'lines' => [['product_id' => $skuId, 'qty_planned' => 2]],
], $fixtureAuth);
check($checks, 'fixture_create_201', $create['status'] === 201, 'status='.$create['status'].' '.json_encode(errorCode($create['json'])));
$documentId = (string) $create['json']['data']['id'];
$lineId = (string) $create['json']['data']['lines'][0]['id'];

foreach ($itemIds as $offset => $itemId) {
    $scan = httpJson($baseUrl, 'POST', "/api/v1/inbound-documents/{$documentId}/scan", [
        'line_id' => $lineId,
        'quantity' => 1,
        'item_id' => $itemId,
    ], $fixtureAuth + ["If-Match" => (string) ($offset + 1)]);
    check($checks, "fixture_scan_{$offset}_200", $scan['status'] === 200, 'status='.$scan['status']);
}

$post = httpJson($baseUrl, 'POST', "/api/v1/inbound-documents/{$documentId}/post-receipt", [], $fixtureAuth + [
    "If-Match" => "3",
    'Idempotency-Key' => 'wwhn240-qa19-receipt-'.$runTag,
]);
check($checks, 'fixture_post_receipt_200', $post['status'] === 200, 'status='.$post['status'].' '.json_encode(errorCode($post['json'])));

$movementId = (string) DB::table('stock_movements')
    ->where('stock_document_id', $documentId)
    ->where('movement_type', 'RECEIPT')
    ->value('id');
check($checks, 'fixture_receipt_movement_exists', $movementId !== '');
$ifMatchVersion = '4'; // create=1, 2 scan bump → 3, post-receipt bump → 4; reversal không bump version.
$racedItemId = $itemIds[0];

// ---------------------------------------------------------------------------
// 2. QA240-19: HAI request HTTP đồng thời qua curl_multi — cùng movement + item,
//    KHÁC Idempotency-Key, cùng If-Match hợp lệ.
$movementsBeforeRace = (int) DB::table('stock_movements')->count();

$payload = json_encode([
    'reason' => 'WWHN-240 QA240-19 concurrent HTTP race',
    'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $racedItemId]],
]);
$multi = curl_multi_init();
$handles = [];
foreach (['a', 'b'] as $label) {
    $ch = curl_init($baseUrl."/api/v1/inbound-documents/{$documentId}/reverse");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer '.$keeperToken,
            'If-Match: '.$ifMatchVersion,
            'Idempotency-Key: wwhn240-qa19-race-'.$label.'-'.$runTag,
        ],
        CURLOPT_TIMEOUT => 120,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[$label] = $ch;
}

$raceStartedAt = microtime(true);
do {
    $mrc = curl_multi_exec($multi, $active);
    if ($active) {
        curl_multi_select($multi, 0.05);
    }
} while ($active && $mrc === CURLM_OK);
$raceWallMs = 1000.0 * (microtime(true) - $raceStartedAt);

$results = [];
foreach ($handles as $label => $ch) {
    $raw = (string) curl_multi_getcontent($ch);
    $results[$label] = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'total_time_ms' => round(1000.0 * (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1),
        'json' => json_decode($raw, true) ?: [],
    ];
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

$statuses = [$results['a']['status'], $results['b']['status']];
$successCount = count(array_filter($statuses, static fn (int $s): bool => $s >= 200 && $s < 300));
$loserLabel = $results['a']['status'] >= 300 ? 'a' : 'b';
$winnerLabel = $loserLabel === 'a' ? 'b' : 'a';
$loser = $results[$loserLabel];
$loserCode = errorCode($loser['json']);

check($checks, 'race_exactly_one_success', $successCount === 1, 'statuses='.json_encode($statuses));
check(
    $checks,
    'race_loser_rejected_with_conflict_code',
    in_array($loser['status'], [409, 412], true)
        && in_array($loserCode, ['CONCURRENCY_CONFLICT', 'REVERSAL_ITEM_ALREADY_REVERSED', 'STALE_VERSION'], true),
    "loser status={$loser['status']} code=".json_encode($loserCode)
);
// Hai request phải THỰC SỰ trong flight cùng lúc: tổng wall-clock của cả cặp
// nhỏ hơn tổng tuần tự của hai request (curl_multi bắn song song).
$sequentialMs = $results['a']['total_time_ms'] + $results['b']['total_time_ms'];
check(
    $checks,
    'race_requests_overlapped_in_flight',
    $raceWallMs < $sequentialMs,
    sprintf('wall=%.1fms < sequential-sum=%.1fms (a=%.1fms, b=%.1fms)', $raceWallMs, $sequentialMs, $results['a']['total_time_ms'], $results['b']['total_time_ms'])
);

$evidence['concurrent_reverse'] = [
    'winner' => $winnerLabel,
    'request_a' => ['status' => $results['a']['status'], 'error_code' => errorCode($results['a']['json']), 'total_time_ms' => $results['a']['total_time_ms']],
    'request_b' => ['status' => $results['b']['status'], 'error_code' => errorCode($results['b']['json']), 'total_time_ms' => $results['b']['total_time_ms']],
    'race_wall_ms' => round($raceWallMs, 1),
];

// ---------------------------------------------------------------------------
// 3. DB: không nhân đôi hiệu ứng tồn/chain.
$reversalMovementsForItem = (int) DB::table('stock_movements')
    ->where('movement_type', 'REVERSAL')
    ->where('item_id', $racedItemId)
    ->count();
check($checks, 'db_exactly_one_reversal_movement_for_item', $reversalMovementsForItem === 1, "count={$reversalMovementsForItem}");
check(
    $checks,
    'db_exactly_one_extra_movement_total',
    (int) DB::table('stock_movements')->count() === $movementsBeforeRace + 1
);
$balance = (int) DB::table('inventory_balances')
    ->where('warehouse_id', $warehouseId)
    ->where('sku_id', $skuId)
    ->where('stock_status', 'AVAILABLE')
    ->value('quantity');
check($checks, 'db_balance_decreased_exactly_once', $balance === 1, "balance={$balance}");

// ---------------------------------------------------------------------------
// 4. Trace-source: chain 2 node, không trùng, truncated=false, net effect đúng.
$trace = httpJson($baseUrl, 'GET', "/api/v1/stock-movements/{$movementId}/trace-source", null, $auth);
check($checks, 'trace_source_200', $trace['status'] === 200, 'status='.$trace['status']);
$chain = $trace['json']['data']['correction_chain'] ?? [];
$netEffect = $trace['json']['data']['net_effect'] ?? [];
$nodeIds = array_map(static fn (array $node): string => (string) $node['id'], $chain['nodes'] ?? []);
check($checks, 'trace_chain_node_count_2', (int) ($chain['node_count'] ?? 0) === 2, 'node_count='.json_encode($chain['node_count'] ?? null));
check($checks, 'trace_chain_nodes_unique', count($nodeIds) === count(array_unique($nodeIds)) && count($nodeIds) === 2);
check($checks, 'trace_chain_not_truncated', ($chain['truncated'] ?? null) === false);
check($checks, 'trace_net_effect_reversed_qty_1', (int) ($netEffect['reversed_qty'] ?? -1) === 1, json_encode($netEffect));
check($checks, 'trace_net_effect_reversal_count_1', (int) ($netEffect['reversal_count'] ?? -1) === 1);
$evidence['trace_source'] = ['correction_chain' => $chain, 'net_effect' => $netEffect];

// ---------------------------------------------------------------------------
// 5. XNT enhanced ↔ ledger reconciliation (bất biến WWHN-240).
$xnt = httpJson(
    $baseUrl,
    'GET',
    '/api/v1/reports/inventory-xnt-enhanced?from_date='.date('Y-m-d', strtotime('-1 day')).'&to_date='.date('Y-m-d', strtotime('+1 day')),
    null,
    $auth
);
check($checks, 'xnt_report_200', $xnt['status'] === 200, 'status='.$xnt['status']);
$xntLine = null;
foreach ($xnt['json']['data']['lines'] ?? [] as $line) {
    if (($line['product_code'] ?? null) === $skuCode && ($line['stock_status'] ?? null) === 'AVAILABLE') {
        $xntLine = $line;
        break;
    }
}
check($checks, 'xnt_line_found', $xntLine !== null, 'sku='.$skuCode);
check(
    $checks,
    'xnt_gross_reversal_net_correct',
    (int) $xntLine['receipt_gross'] === 2
        && (int) $xntLine['receipt_reversal'] === 1
        && (int) $xntLine['receipt_net'] === 1
        && (int) $xntLine['closing'] === 1
        && (int) $xntLine['reversal_net'] === -1,
    json_encode(array_intersect_key($xntLine, array_flip(['receipt_gross', 'receipt_reversal', 'receipt_net', 'reversal_net', 'closing'])))
);
$ledgerNet = (int) DB::table('stock_movements')
    ->where('warehouse_id', $warehouseId)
    ->where('product_id', $skuId)
    ->where('stock_status', 'AVAILABLE')
    ->sum('qty_delta');
check($checks, 'xnt_closing_equals_ledger_sum_qty_delta', (int) $xntLine['closing'] === $ledgerNet, "closing={$xntLine['closing']} ledger={$ledgerNet}");
check($checks, 'ledger_equals_balance', $ledgerNet === $balance, "ledger={$ledgerNet} balance={$balance}");
$evidence['xnt'] = array_intersect_key($xntLine, array_flip([
    'warehouse_code', 'product_code', 'stock_status', 'opening',
    'receipt_gross', 'receipt_reversal', 'receipt_net',
    'issue_gross', 'issue_reversal', 'issue_net',
    'component_issue_gross', 'component_issue_reversal', 'component_issue_net',
    'reversal_unlinked_net', 'reversal_net', 'closing',
]));
$evidence['ledger'] = ['sum_qty_delta' => $ledgerNet, 'inventory_balance' => $balance];

// ---------------------------------------------------------------------------
// 6. RBAC: staff01 không có inventory.reversal.post lẫn inventory.trace.
$staffToken = login($baseUrl, 'staff01@gmail.com', $password);
$staffAuth = ['Authorization' => 'Bearer '.$staffToken];
$staffReverse = httpJson($baseUrl, 'POST', "/api/v1/inbound-documents/{$documentId}/reverse", [
    'reason' => 'RBAC deny probe',
    'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $itemIds[1]]],
], $staffAuth + ['If-Match' => $ifMatchVersion, 'Idempotency-Key' => 'wwhn240-qa19-rbac-'.$runTag]);
check($checks, 'rbac_staff_reverse_403', $staffReverse['status'] === 403, 'status='.$staffReverse['status'].' code='.json_encode(errorCode($staffReverse['json'])));
$staffTrace = httpJson($baseUrl, 'GET', "/api/v1/stock-movements/{$movementId}/trace-source", null, $staffAuth);
check($checks, 'rbac_staff_trace_403', $staffTrace['status'] === 403, 'status='.$staffTrace['status']);
check(
    $checks,
    'rbac_denied_wrote_nothing',
    (int) DB::table('stock_movements')->count() === $movementsBeforeRace + 1
        && (int) DB::table('stock_movements')->where('movement_type', 'REVERSAL')->where('item_id', $itemIds[1])->count() === 0
);
$evidence['rbac'] = [
    'staff_reverse' => ['status' => $staffReverse['status'], 'error_code' => errorCode($staffReverse['json'])],
    'staff_trace' => ['status' => $staffTrace['status'], 'error_code' => errorCode($staffTrace['json'])],
];

// ---------------------------------------------------------------------------
$evidence['env'] = [
    'db' => [
        'driver' => 'pgsql',
        'database' => DB::connection()->getDatabaseName(),
        'server_version' => (string) (DB::selectOne('SHOW server_version')->server_version ?? ''),
    ],
    'php' => PHP_VERSION,
    'cli_server_workers_hint' => getenv('PHP_CLI_SERVER_WORKERS') ?: '(client env — xem lệnh khởi động server trong evidence doc)',
];
$evidence['fixture'] = [
    'document_id' => $documentId,
    'movement_id' => $movementId,
    'raced_item_id' => $racedItemId,
    'sku_code' => $skuCode,
    'warehouse_id' => $warehouseId,
];
$evidence['checks'] = $checks;

file_put_contents(
    rtrim($evidenceDir, '/').'/qa240-19-http-race-summary.json',
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
fwrite(STDOUT, "\nQA240-19 HTTP race: ALL CHECKS PASS (".count($checks)." checks)\n");
exit(0);
