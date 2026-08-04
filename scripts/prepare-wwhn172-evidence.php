<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Product;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$evidenceDir = getenv('EVIDENCE_DIR') ?: 'build/evidence/wwhn-172';
$password = getenv('WWHN172_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDERR, "REFUSED: WWHN172_PASSWORD phải là credential tạm thời của workflow.\n");
    exit(78);
}

$fixtureDir = $evidenceDir.'/fixtures';
if (! is_dir($fixtureDir) && ! mkdir($fixtureDir, 0755, true) && ! is_dir($fixtureDir)) {
    throw new RuntimeException("Không tạo được {$fixtureDir}");
}

$component = Product::query()->updateOrCreate(
    ['code' => 'W172-COMPONENT-FIXTURE'],
    [
        'sku_code' => 'W172-COMPONENT-FIXTURE',
        'name' => 'WWHN-172 component fixture',
        'sku_type' => 'COMPONENT',
        'status' => 'ACTIVE',
        'version' => 1,
    ]
);
$bulkProduct = Product::query()->updateOrCreate(
    ['code' => 'W172-BULK-FIXTURE'],
    [
        'sku_code' => 'W172-BULK-FIXTURE',
        'name' => 'WWHN-172 bulk fixture',
        'sku_type' => 'PRODUCT',
        'status' => 'ACTIVE',
        'version' => 1,
    ]
);
$serialProduct = Product::query()->updateOrCreate(
    ['code' => 'W172-SERIAL-FIXTURE'],
    [
        'sku_code' => 'W172-SERIAL-FIXTURE',
        'name' => 'WWHN-172 serial fixture',
        'sku_type' => 'PRODUCT',
        'is_serial_tracked' => true,
        'status' => 'ACTIVE',
        'version' => 1,
    ]
);
$inactiveProduct = Product::query()->updateOrCreate(
    ['code' => 'W172-INACTIVE-FIXTURE'],
    [
        'sku_code' => 'W172-INACTIVE-FIXTURE',
        'name' => 'WWHN-172 inactive fixture',
        'sku_type' => 'PRODUCT',
        'status' => 'INACTIVE',
        'version' => 2,
    ]
);

$serialId = (string) Str::uuid();
DB::table('inventory_serials')->updateOrInsert(
    ['serial_no' => 'W172-SERIAL-0001'],
    [
        'id' => $serialId,
        'product_id' => (string) $serialProduct->id,
        'warehouse_id' => null,
        'physical_code_id' => null,
        'condition' => 'GOOD',
        'stock_condition' => 'AVAILABLE',
        'item_condition' => 'NEW',
        'status' => 'IN_STOCK',
        'created_at' => now(),
        'updated_at' => now(),
    ]
);
$serialId = (string) DB::table('inventory_serials')->where('serial_no', 'W172-SERIAL-0001')->value('id');

$writeXlsx = static function (string $path, array $headers, array $rows): void {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($headers, null, 'A1');
    foreach ($rows as $index => $row) {
        $sheet->fromArray($row, null, 'A'.($index + 2));
    }
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
};

$validImport = $fixtureDir.'/valid-import.xlsx';
$invalidImport = $fixtureDir.'/invalid-import.xlsx';
$writeXlsx(
    $validImport,
    ['name', 'code', 'sku_type', 'status', 'published_to_app'],
    [
        ['WWHN-172 imported valid A', 'W172-IMPORT-VALID-A', 'PRODUCT', 'ACTIVE', 'true'],
        ['WWHN-172 imported valid B', 'W172-IMPORT-VALID-B', 'COMPONENT', 'ACTIVE', 'false'],
    ]
);
$writeXlsx(
    $invalidImport,
    ['code', 'name', 'status', 'published_to_app'],
    [
        ['W172-IMPORT-MUST-ROLLBACK', 'Valid row but whole file must rollback', 'ACTIVE', 'true'],
        ['W172-DUP-IN-FILE', 'First duplicate', 'ACTIVE', 'false'],
        ['W172-DUP-IN-FILE', 'Second duplicate', 'ACTIVE', 'false'],
        ['W172-INACTIVE-FIXTURE', 'Inactive conflict', 'ACTIVE', 'false'],
        ['W172-INVALID-ENUM', 'Invalid enum', 'UNKNOWN', 'maybe'],
    ]
);

$metadata = [
    'task' => 'WWHN-172',
    'generated_at' => now()->toIso8601String(),
    'component_id' => (string) $component->id,
    'product_id' => (string) $bulkProduct->id,
    'serial_product_id' => (string) $serialProduct->id,
    'serial_id' => $serialId,
    'inactive_product_id' => (string) $inactiveProduct->id,
    'valid_import_sha256' => hash_file('sha256', $validImport),
    'invalid_import_sha256' => hash_file('sha256', $invalidImport),
];
file_put_contents(
    $evidenceDir.'/fixture-metadata.json',
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
);
