<?php

declare(strict_types=1);

use App\Support\Wwhn172MigrationInspector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$label = $argv[1] ?? 'snapshot';
$output = $argv[2] ?? 'build/evidence/wwhn-172/db-snapshot.json';

$tables = [
    'products', 'sku_component', 'physical_code', 'container', 'container_item',
    'inventory_serials', 'audit_log', 'audit_outbox', 'idempotency_keys', 'aggregate_mutexes',
];
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
}

$rows = [
    'products' => Schema::hasTable('products')
        ? DB::table('products')->where(function ($query): void {
            $query->where('code', 'like', 'W172-%')->orWhere('code', 'like', 'WWHN172-%');
        })->orderBy('code')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
    'sku_component' => Schema::hasTable('sku_component')
        ? DB::table('sku_component')->orderBy('parent_product_id')->orderBy('component_product_id')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
    'physical_code' => Schema::hasTable('physical_code')
        ? DB::table('physical_code')->where('code_value', 'like', 'W172-%')->orderBy('code_value')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
    'container' => Schema::hasTable('container')
        ? DB::table('container')->where('container_code', 'like', 'W172-%')->orderBy('container_code')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
    'container_item' => Schema::hasTable('container_item')
        ? DB::table('container_item')->orderBy('container_id')->orderBy('item_key')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
    'audit_log' => Schema::hasTable('audit_log')
        ? DB::table('audit_log')->where(function ($query): void {
            $query->where('reason', 'like', '%WWHN-172%')
                ->orWhere('action', 'like', 'SKU_IMPORT_%')
                ->orWhereIn('action', ['SKU_CREATE', 'SKU_UPDATE', 'SKU_PHYSICAL_CODE_ASSIGN', 'CONTAINER_CREATE', 'CONTAINER_UPDATE', 'CONTAINER_SEAL']);
        })->orderBy('created_at')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
    'audit_outbox' => Schema::hasTable('audit_outbox')
        ? DB::table('audit_outbox')->orderBy('created_at')->get()->map(fn (object $row): array => (array) $row)->all()
        : [],
];

$report = [
    'schema_version' => '1.0.0',
    'task' => 'WWHN-172',
    'label' => $label,
    'generated_at' => now()->toIso8601String(),
    'database_driver' => DB::getDriverName(),
    'database_name' => (string) DB::connection()->getDatabaseName(),
    'counts' => $counts,
    'preflight' => app(Wwhn172MigrationInspector::class)->inspect(),
    'rows' => $rows,
];

$directory = dirname($output);
if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
    throw new RuntimeException("Không tạo được {$directory}");
}
file_put_contents(
    $output,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
);
