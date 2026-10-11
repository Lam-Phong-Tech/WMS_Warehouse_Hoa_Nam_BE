<?php

/** Isolated PostgreSQL replay regression; temporary tables, not a full seed. */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Database\Seeders\InventoryStockStatusMixSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseOperationsCoverageSeeder;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$name = config('database.default');
$connection = config('database.connections.'.$name);
if (! $app->environment('testing') || ($connection['driver'] ?? null) !== 'pgsql'
    || ($connection['host'] ?? null) !== '127.0.0.1'
    || ($connection['database'] ?? null) !== 'component_issue_seeder_test'
    || ! empty($connection['url']) || config('deployment_safety.protect_database')) {
    throw new RuntimeException('Requires the isolated localhost component_issue_seeder_test database. No live authorization.');
}
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
    echo 'PASS: '.$label.PHP_EOL;
};
$invoke = static fn (object $object, string $method, array $args = []) => (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
$set = static fn (object $object, string $property, mixed $value) => (new ReflectionProperty($object, $property))->setValue($object, $value);
// Ordered full row encodings support compound/no-primary-key tables as well.
$rows = static function (string $table): string {
    $encoded = DB::table($table)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
    sort($encoded, SORT_STRING);

    return hash('sha256', implode("\n", $encoded));
};
DB::unprepared(<<<'SQL'
    CREATE TEMP TABLE skus (id uuid PRIMARY KEY, sku_code text UNIQUE, status text);
    CREATE TEMP TABLE inventory_balances (
        id uuid PRIMARY KEY, warehouse_id uuid, product_id uuid, sku_id uuid,
        stock_status text, on_hand_qty bigint, available_qty bigint, reserved_qty bigint,
        quantity bigint, version integer, created_at timestamp, updated_at timestamp,
        UNIQUE (warehouse_id, sku_id, stock_status)
    );
    CREATE TEMP TABLE stock_movements (
        id uuid PRIMARY KEY, movement_no text, business_key text UNIQUE, movement_type text,
        warehouse_id uuid, product_id uuid, sku_id uuid, stock_status text,
        qty_before bigint, qty_delta bigint, qty_after bigint, quantity bigint,
        source_type text, created_by uuid, request_id text, occurred_at timestamp,
        created_at timestamp, stock_document_id uuid, source_id uuid, item_id uuid
    );
    CREATE TEMP TABLE reversal_document (reversal_document_id uuid PRIMARY KEY, status text);
    CREATE TEMP TABLE reversal_line (reversal_document_id uuid, original_movement_id uuid);
    CREATE TEMP TABLE item_instances (
        item_id uuid PRIMARY KEY, serial_number text, item_status text, item_condition text,
        created_at timestamp, updated_at timestamp, location_hint text
    );
    CREATE TEMP TABLE warranty_case (item_id uuid);
    CREATE TEMP TABLE stock_documents (id uuid PRIMARY KEY, doc_no text);
SQL);
$warehouse = (string) Str::uuid();
$actor = (string) Str::uuid();
$mix = new InventoryStockStatusMixSeeder;
$set($mix, 'warehouseId', $warehouse);
$set($mix, 'actorId', $actor);
$ids = [];
for ($i = 0; $i < 20; $i++) {
    $ids[] = $id = (string) Str::uuid();
    DB::table('skus')->insert(['id' => $id, 'sku_code' => sprintf('REPLAY-%03d', $i), 'status' => 'ACTIVE']);
}
DB::table('inventory_balances')->insert([
    'id' => (string) Str::uuid(), 'warehouse_id' => $warehouse, 'sku_id' => $ids[0], 'product_id' => $ids[0],
    'stock_status' => 'AVAILABLE', 'quantity' => 50, 'on_hand_qty' => 50, 'available_qty' => 50,
    'reserved_qty' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
$check($invoke($mix, 'seedMatchedCohort') === 14, 'first cohort creates exactly 14 matched fixture buckets');
$check(DB::table('stock_movements')->count() === 14, '14 real SQL opening movement rows');
$check(DB::table('inventory_balances')->count() === 15, 'original balance plus 14 new buckets');
foreach (DB::table('stock_movements')->get() as $movement) {
    $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse)
        ->where('sku_id', $movement->sku_id)->where('stock_status', $movement->stock_status)->first();
    $check((int) $balance->quantity === (int) $movement->qty_after, 'new bucket balance matches its opening evidence');
    $check((int) $balance->available_qty === ($movement->stock_status === 'AVAILABLE' ? (int) $movement->quantity : 0), 'non-available quantity never inflates available stock');
}
$before = [$rows('stock_movements'), $rows('inventory_balances')];
for ($i = 0; $i < 3; $i++) {
    $check($invoke($mix, 'seedMatchedCohort') === 0, 'repeat does not choose the next no-stock cohort');
    $check($before === [$rows('stock_movements'), $rows('inventory_balances')], 'repeat retains every existing row and timestamp');
}
// An existing legacy fragment is preserved, not repaired or marked complete.
$legacyWarehouse = (string) Str::uuid();
$set($mix, 'warehouseId', $legacyWarehouse);
DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'warehouse_id' => $legacyWarehouse,
    'business_key' => 'SEED-STKMIX:LEGACY:'.Str::uuid(), 'movement_type' => 'OPENING']);
$before = [$rows('stock_movements'), $rows('inventory_balances')];
$check($invoke($mix, 'seedMatchedCohort') === 0, 'legacy fragment prevents a new moving cohort');
$check($before === [$rows('stock_movements'), $rows('inventory_balances')], 'legacy fragment is not fabricated into a completion receipt');

// Inject a failure only in a TEMP table on a different isolated warehouse.
$failedWarehouse = (string) Str::uuid();
$set($mix, 'warehouseId', $failedWarehouse);
DB::unprepared(<<<'SQL'
    CREATE FUNCTION pg_temp.reject_replay_balance() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN RAISE EXCEPTION 'test-only balance write failure'; END; $$;
    CREATE TRIGGER reject_replay_balance BEFORE INSERT ON inventory_balances
        FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_replay_balance();
SQL);
$failed = false;
try {
    $invoke($mix, 'seedMatchedCohort');
} catch (QueryException $exception) {
    $failed = str_contains($exception->getMessage(), 'test-only balance write failure');
}
$check($failed, 'unexpected database error propagates');
$check($before === [$rows('stock_movements'), $rows('inventory_balances')], 'failed cohort rolls back its opening movement and balance together');
$check((int) DB::selectOne('SELECT 1 AS ok')->ok === 1, 'transaction remains usable after rollback');

$operations = new WarehouseOperationsCoverageSeeder;
foreach (['receipt' => 'RECEIPT', 'issue' => 'ISSUE', 'component_issue' => 'COMPONENT_ISSUE'] as $type => $movementType) {
    foreach (['CANCELLED', 'DRAFT', 'POSTED'] as $state) {
        $document = (string) Str::uuid();
        $movement = (string) Str::uuid();
        $reversal = (string) Str::uuid();
        DB::table('stock_movements')->insert(['id' => $movement, 'movement_type' => $movementType,
            'stock_document_id' => $type === 'component_issue' ? null : $document,
            'source_type' => $type === 'component_issue' ? 'COMPONENT_ISSUE_DOCUMENT' : null,
            'source_id' => $document, 'quantity' => 1, 'created_at' => now()]);
        DB::table('reversal_document')->insert(['reversal_document_id' => $reversal, 'status' => $state]);
        DB::table('reversal_line')->insert(['reversal_document_id' => $reversal, 'original_movement_id' => $movement]);
        $beforeReversal = [$rows('reversal_document'), $rows('reversal_line'), $rows('stock_movements')];
        $invoke($operations, 'runReversal', [$document, $type, $state, 'Isolated fixture', $actor, false]);
        $check($beforeReversal === [$rows('reversal_document'), $rows('reversal_line'), $rows('stock_movements')], $type.' '.$state.' history prevents a second seed reversal');
    }
}
$fresh = [];
$existing = [];
for ($i = 0; $i < 80; $i++) {
    $id = (string) Str::uuid();
    if ($i < 60) {
        $fresh[$id] = true;
    } else {
        $existing[] = $id;
    }
    DB::table('item_instances')->insert(['item_id' => $id, 'serial_number' => 'ISOLATED-'.$i,
        'item_status' => 'IN_STOCK', 'item_condition' => 'NORMAL',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
}
$beforeItems = $rows('item_instances');
$invoke($operations, 'seedItemLifecycleEdges');
$check($beforeItems === $rows('item_instances'), 'an existing population is never a fresh lifecycle fixture cohort');
$set($operations, 'createdFixtureItemIds', $fresh);
$invoke($operations, 'seedItemLifecycleEdges');
$check(DB::table('item_instances')->whereIn('item_id', $existing)->where('item_status', 'IN_STOCK')->where('item_condition', 'NORMAL')->count() === count($existing), 'unrelated existing items retain status and condition');
$check(DB::table('item_instances')->whereIn('item_id', $existing)->where('updated_at', '2026-01-01 00:00:00')->count() === count($existing), 'unrelated timestamps are unchanged');
$check(DB::table('item_instances')->where('item_status', 'LOST')->count() === 8, 'only explicitly registered fresh fixture items enter the lifecycle sample');
$afterItems = $rows('item_instances');
$invoke($operations, 'seedItemLifecycleEdges');
$check($afterItems === $rows('item_instances'), 'a consumed lifecycle cohort is not reclassified on replay');

config(['deployment_safety.protect_database' => true, 'deployment_safety.allow_protected_database_seed' => true]);
foreach ([new InventoryStockStatusMixSeeder, new WarehouseOperationsCoverageSeeder] as $seeder) {
    $rejected = false;
    try {
        $seeder->run();
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'isolated');
    }
    $check($rejected, 'protected database cannot be enabled by this fixture repair: '.class_basename($seeder));
}
$summary = 'PASS: '.$checks.' isolated PostgreSQL replay assertions; TEMP tables only. Full-chain 14-day seed, historical 2386-row delta, runtime overlays and live seed NOT certified.'.PHP_EOL;
echo $summary;
if (getenv('GITHUB_STEP_SUMMARY')) {
    file_put_contents(getenv('GITHUB_STEP_SUMMARY'), $summary, FILE_APPEND);
}
