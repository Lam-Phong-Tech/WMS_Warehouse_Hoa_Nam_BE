<?php

declare(strict_types=1);

// This is a deliberately narrow DEV/TEST fixture, not a seeder and not a
// production repair. It only inserts rows with the fixed TEST prefix below.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\WarrantyCasePii;

$fail = static function (string $message): never {
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
};

if (app()->environment() !== 'testing'
    || DB::connection()->getDatabaseName() !== 'wms_hoanam_green'
    || getenv('TC_FIXTURE_CONFIRM') !== 'DEVTEST-TC11-TC12-20261025') {
    $fail('TC11_TC12_FIXTURE_TARGET_GUARD_FAILED');
}

foreach (['sku', 'item_instances', 'physical_codes', 'inventory_balances', 'warranty_case', 'warranty_event', 'warranty_case_warehouse_owners'] as $table) {
    if (! Schema::hasTable($table)) {
        $fail('TC11_TC12_FIXTURE_SCHEMA_MISSING_'.$table);
    }
}

$warehouse = DB::table('warehouses')->where('code', 'KHO-TONG-HN')->where('status', 'ACTIVE')->first();
$actor = DB::table('app_user')->whereIn('username', ['admin', 'keeper01'])->orderByRaw("CASE WHEN username = 'admin' THEN 0 ELSE 1 END")->first();
if ($warehouse === null || $actor === null) {
    $fail('TC11_TC12_FIXTURE_PREREQUISITES_MISSING');
}

$skuCode = 'TC11-TC12-TEST-SKU-20261025';
$tc11Code = 'TC11-TC12-TEST-QR-20261025';
$tc12Serial = 'TC11-TC12-TEST-SERIAL-20261025';
$caseCode = 'TC12-TEST-WARRANTY-20261025';
$skuId = 'e1111111-1111-4111-8111-111111111101';
$tc11ItemId = 'e1111111-1111-4111-8111-111111111102';
$tc12ItemId = 'e1111111-1111-4111-8111-111111111103';
$tc11PhysicalId = 'e1111111-1111-4111-8111-111111111104';
$tc12PhysicalId = 'e1111111-1111-4111-8111-111111111105';
$balanceId = 'e1111111-1111-4111-8111-111111111106';
$caseId = 'e1111111-1111-4111-8111-111111111107';
$receiveEventId = 'e1111111-1111-4111-8111-111111111108';
$checkEventId = 'e1111111-1111-4111-8111-111111111109';
$now = now('UTC');

DB::transaction(function () use (
    $skuCode, $tc11Code, $tc12Serial, $caseCode, $skuId, $tc11ItemId, $tc12ItemId,
    $tc11PhysicalId, $tc12PhysicalId, $balanceId, $caseId, $receiveEventId, $checkEventId,
    $warehouse, $actor, $now
): void {
    $sku = DB::table('sku')->where('sku_code', $skuCode)->first();
    if ($sku === null) {
        DB::table('sku')->insert([
            'sku_id' => $skuId, 'sku_code' => $skuCode, 'sku_name' => 'TEST TC11 TC12 Product',
            'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => true,
            'warranty_applicable' => true, 'published_to_app' => false, 'status' => 'ACTIVE',
            'version_code' => 'V1', 'created_at' => $now, 'updated_at' => $now,
        ]);
    } elseif ((string) $sku->sku_id !== $skuId || $sku->status !== 'ACTIVE') {
        throw new RuntimeException('TC11_TC12_FIXTURE_SKU_COLLISION');
    }

    $physical = static function (string $id, string $value, string $itemId) use ($now): void {
        $row = DB::table('physical_codes')->where('code_value', $value)->first();
        if ($row === null) {
            DB::table('physical_codes')->insert([
                'physical_code_id' => $id, 'code_value' => $value, 'code_type' => 'ITEM',
                'carrier_type' => 'QR', 'status' => 'ACTIVE', 'assigned_object_type' => 'ITEM',
                'assigned_object_id' => $itemId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ((string) $row->physical_code_id !== $id || $row->status !== 'ACTIVE') {
            throw new RuntimeException('TC11_TC12_FIXTURE_PHYSICAL_CODE_COLLISION');
        }
    };
    $physical($tc11PhysicalId, $tc11Code, $tc11ItemId);
    $physical($tc12PhysicalId, $tc12Serial, $tc12ItemId);

    $item = static function (string $id, string $serial, string $physicalId, string $status) use ($skuId, $now): void {
        $row = DB::table('item_instances')->where('item_id', $id)->first();
        if ($row === null) {
            DB::table('item_instances')->insert([
                'item_id' => $id, 'sku_id' => $skuId, 'physical_code_id' => $physicalId,
                'serial_number' => $serial, 'item_status' => $status, 'item_condition' => 'NORMAL',
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ((string) $row->sku_id !== $skuId || (string) $row->serial_number !== $serial) {
            throw new RuntimeException('TC11_TC12_FIXTURE_ITEM_COLLISION');
        }
    };
    $item($tc11ItemId, $tc11Code, $tc11PhysicalId, 'IN_STOCK');
    $item($tc12ItemId, $tc12Serial, $tc12PhysicalId, 'ISSUED');

    $balance = DB::table('inventory_balances')->where('id', $balanceId)->first();
    if ($balance === null) {
        DB::table('inventory_balances')->insert([
            'id' => $balanceId, 'warehouse_id' => $warehouse->id, 'product_id' => $skuId,
            'sku_id' => $skuId, 'stock_status' => 'AVAILABLE', 'quantity' => 1,
            'on_hand_qty' => 1, 'available_qty' => 1, 'reserved_qty' => 0, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    } elseif ((string) $balance->warehouse_id !== (string) $warehouse->id || (string) $balance->product_id !== $skuId) {
        throw new RuntimeException('TC11_TC12_FIXTURE_BALANCE_COLLISION');
    }

    $case = DB::table('warranty_case')->where('warranty_case_code', $caseCode)->first();
    if ($case === null) {
        $defectId = DB::table('defect_catalogs')->where('status', 'ACTIVE')->value('defect_id');
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $caseId, 'warranty_case_code' => $caseCode,
            'item_id' => $tc12ItemId, 'inventory_serial_id' => null, 'product_id' => $skuId,
            'temporary_item_code' => null, 'identification_status' => 'IDENTIFIED',
            'sales_trace_status' => 'TRACEABLE', 'missing_code_reason' => null,
            'manual_product_description' => null, 'defect_id' => $defectId,
            'customer_name' => 'TC12 TEST CUSTOMER', 'customer_phone' => '0900000000',
            'customer_address' => 'TC12 DEV TEST', 'purchase_date' => $now->copy()->subMonth()->toDateString(),
            'status' => 'CHECKING', 'condition_status' => 'DEFECTIVE',
            'description' => 'TC12 TEST fixture only', 'reported_defect' => 'TC12 TEST fixture only',
            'confirmed_defect' => null, 'accessories_received' => null,
            'received_condition' => 'TEST', 'received_at' => $now, 'created_by' => $actor->user_id,
            'version' => 2, 'legal_hold_flag' => false, 'created_at' => $now,
            'updated_at' => $now,
        ]));
        DB::table('warranty_case_warehouse_owners')->insert([
            'warranty_case_id' => $caseId, 'warehouse_id' => $warehouse->id,
            'actor_id' => $actor->user_id, 'auth_session_id' => null,
            'source' => 'TC11_TC12_TEST_FIXTURE', 'assigned_at' => $now, 'evidence' => null,
        ]);
        DB::table('warranty_event')->insert([
            ['warranty_event_id' => $receiveEventId, 'warranty_case_id' => $caseId,
                'event_type' => 'RECEIVE', 'from_status' => null, 'to_status' => 'RECEIVED',
                'note' => 'TC12 TEST fixture receive', 'created_by' => $actor->user_id, 'event_at' => $now],
            ['warranty_event_id' => $checkEventId, 'warranty_case_id' => $caseId,
                'event_type' => 'CHECK', 'from_status' => 'RECEIVED', 'to_status' => 'CHECKING',
                'note' => 'TC12 TEST fixture check', 'created_by' => $actor->user_id, 'event_at' => $now],
        ]);
    } elseif ((string) $case->warranty_case_id !== $caseId || (string) $case->product_id !== $skuId) {
        throw new RuntimeException('TC11_TC12_FIXTURE_CASE_COLLISION');
    }
});

echo json_encode([
    'status' => 'FIXTURE_READY', 'environment' => app()->environment(),
    'database' => DB::connection()->getDatabaseName(), 'warehouse_id' => (string) $warehouse->id,
    'actor_id' => (string) $actor->user_id, 'tc11' => [
        'sku_id' => $skuId, 'item_id' => $tc11ItemId, 'raw_code' => $tc11Code,
        'eligible_for_outbound_expected' => true,
    ], 'tc12' => [
        'warranty_case_id' => $caseId, 'case_code' => $caseCode, 'item_id' => $tc12ItemId,
        'sku_id' => $skuId, 'status' => 'CHECKING', 'identification_status' => 'IDENTIFIED',
    ], 'business_commands_called' => [], 'migration' => false, 'seed' => false,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
