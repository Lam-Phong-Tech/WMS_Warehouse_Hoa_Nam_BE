<?php

declare(strict_types=1);

use App\Support\IdempotencyStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\SkuAggregateService;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$output = $argv[1] ?? 'build/evidence/wwhn-172/service-proof.json';
$key = 'wwhn172-service-proof';
$payload = ['code' => 'W172-SERVICE-PROOF', 'name' => 'WWHN-172 service proof'];
$hash = 'v2:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
$service = app(SkuAggregateService::class);

$run = static function () use ($service, $payload): array {
    $data = $service->create($payload, null, 'wwhn172-service-proof-request');
    return [201, ['success' => true, 'data' => $data]];
};

$first = IdempotencyStore::handle($key, 'sku_create', null, $hash, $run);
$replay = IdempotencyStore::handle($key, 'sku_create', null, $hash, $run);
$id = (string) ($first[1]['data']['id'] ?? '');

$report = [
    'schema_version' => '1.0.0',
    'task' => 'WWHN-172',
    'database_driver' => DB::getDriverName(),
    'first_http_status' => $first[0],
    'first_is_replay' => $first[2],
    'second_http_status' => $replay[0],
    'second_is_replay' => $replay[2],
    'business_row' => (array) DB::table('products')->where('id', $id)->first(),
    'audit_rows' => DB::table('audit_log')->where('object_id', $id)->orderBy('created_at')->get()->map(fn ($row) => (array) $row)->all(),
    'idempotency_row' => (array) DB::table('idempotency_keys')->where('idem_key', $key)->first(),
    'assertions' => [
        'one_business_row' => DB::table('products')->where('code', 'W172-SERVICE-PROOF')->count() === 1,
        'one_audit_row' => DB::table('audit_log')->where('object_id', $id)->where('action', 'SKU_CREATE')->count() === 1,
        'exact_replay' => $first[0] === $replay[0] && $first[1] === $replay[1] && $replay[2] === true,
        'outbox_empty' => DB::table('audit_outbox')->count() === 0,
    ],
];
$report['status'] = ! in_array(false, $report['assertions'], true) ? 'PASS' : 'FAIL';

$dir = dirname($output);
if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
    throw new RuntimeException("Không tạo được {$dir}");
}
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
if ($report['status'] !== 'PASS') {
    exit(1);
}
