<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\ContainerItem;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;

/**
 * Seed dữ liệu demo cho môi trường dev/local.
 *
 * Luồng tạo dữ liệu (tôn trọng khóa ngoại & constraint):
 *   1. product_masters  → brand/category/model/package/power
 *   2. sku              (liên kết master)
 *   3. import_batches   (lô nhập, traceability)
 *   4. physical_codes   (mã vật lý QR/barcode cho máy + container)
 *   5. containers       (cần physical_code_id)
 *   6. item_instances   (máy có serial, liên kết sku/container/import_batch/physical_code)
 *   7. inventory_serials(chồng lên item_instance cho SKU serial-tracked)
 *   8. stock_documents  + stock_document_lines (phiếu nhập đã hạch toán)
 *   9. inventory_balances + stock_movements   (projection & append-only ledger)
 *  10. container_items  (bản đồ item/quantity trong container)
 */
class DemoDataSeeder extends Seeder
{
    private const BRANDS = [
        ['code' => 'KUBOTA', 'name' => 'Kubota'],
        ['code' => 'YANMAR', 'name' => 'Yanmar'],
        ['code' => 'HONDA', 'name' => 'Honda'],
        ['code' => 'HUSQVARNA', 'name' => 'Husqvarna'],
    ];

    private const CATEGORIES = [
        ['code' => 'TRACTOR', 'name' => 'Máy kéo'],
        ['code' => 'HARVESTER', 'name' => 'Máy gặt đập liên hợp'],
        ['code' => 'PUMP', 'name' => 'Máy bơm nước'],
        ['code' => 'ENGINE', 'name' => 'Động cơ'],
        ['code' => 'SPARE_PART', 'name' => 'Phụ tùng thay thế'],
    ];

    private const MODELS = [
        ['code' => 'L3301', 'name' => 'L3301 HST'],
        ['code' => 'L4701', 'name' => 'L4701'],
        ['code' => 'DC95', 'name' => 'DC95 (máy gặt)'],
        ['code' => 'YPB30', 'name' => 'YPB30 (máy bơm)'],
        ['code' => 'GX160', 'name' => 'GX160'],
        ['code' => 'GX390', 'name' => 'GX390'],
    ];

    private const PACKAGES = [
        ['code' => 'BARE', 'name' => 'Bare (chỉ máy)'],
        ['code' => 'KIT', 'name' => 'KIT'],
        ['code' => 'SET', 'name' => 'SET'],
    ];

    private const POWERS = [
        ['code' => 'P25', 'name' => '25 HP'],
        ['code' => 'P47', 'name' => '47 HP'],
        ['code' => 'P6', 'name' => '6 HP'],
        ['code' => 'P13', 'name' => '13 HP'],
    ];

    private const SKUS = [
        // Máy (serial tracked)
        [
            'sku_code' => 'KUB-L3301-TRAC',
            'sku_name' => 'Máy kéo Kubota L3301 HST',
            'sku_type' => 'PRODUCT',
            'unit' => 'chiếc',
            'is_serial_tracked' => true,
            'brand' => 'KUBOTA', 'category' => 'TRACTOR', 'model' => 'L3301',
            'power_or_voltage' => 'P25', 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => true,
            'specification' => ['capacity' => '3 lưỡi', 'drive' => '4WD', 'weight_kg' => 1250],
            'status' => 'ACTIVE',
        ],
        [
            'sku_code' => 'KUB-L4701-TRAC',
            'sku_name' => 'Máy kéo Kubota L4701',
            'sku_type' => 'PRODUCT',
            'unit' => 'chiếc',
            'is_serial_tracked' => true,
            'brand' => 'KUBOTA', 'category' => 'TRACTOR', 'model' => 'L4701',
            'power_or_voltage' => 'P47', 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => true,
            'specification' => ['capacity' => '4 lưỡi', 'drive' => '4WD', 'weight_kg' => 1650],
            'status' => 'ACTIVE',
        ],
        [
            'sku_code' => 'YAN-DC95-HARV',
            'sku_name' => 'Máy gặt đập liên hợp Yanmar DC95',
            'sku_type' => 'PRODUCT',
            'unit' => 'chiếc',
            'is_serial_tracked' => true,
            'brand' => 'YANMAR', 'category' => 'HARVESTER', 'model' => 'DC95',
            'power_or_voltage' => 'P47', 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => true,
            'specification' => ['cut_width' => '1.6m', 'engine' => 'Yanmar 3TNM74'],
            'status' => 'ACTIVE',
        ],
        [
            'sku_code' => 'YAN-YPB30-PUMP',
            'sku_name' => 'Máy bơm nước Yanmar YPB30',
            'sku_type' => 'PRODUCT',
            'unit' => 'chiếc',
            'is_serial_tracked' => true,
            'brand' => 'YANMAR', 'category' => 'PUMP', 'model' => 'YPB30',
            'power_or_voltage' => 'P6', 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => true,
            'specification' => ['flow_m3h' => 60, 'head_m' => 20],
            'status' => 'ACTIVE',
        ],
        [
            'sku_code' => 'HON-GX160-ENG',
            'sku_name' => 'Động cơ Honda GX160',
            'sku_type' => 'PRODUCT',
            'unit' => 'chiếc',
            'is_serial_tracked' => true,
            'brand' => 'HONDA', 'category' => 'ENGINE', 'model' => 'GX160',
            'power_or_voltage' => 'P6', 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => true,
            'specification' => ['cylinder' => 1, 'displacement_cc' => 163],
            'status' => 'ACTIVE',
        ],
        // Phụ tùng (không serial tracked)
        [
            'sku_code' => 'KUB-L3310-BLADE',
            'sku_name' => 'Lưỡi cày KUBOTA L3301/L3310',
            'sku_type' => 'COMPONENT',
            'unit' => 'bộ',
            'is_serial_tracked' => false,
            'brand' => 'KUBOTA', 'category' => 'SPARE_PART', 'model' => 'L3301',
            'power_or_voltage' => null, 'package_variant' => 'SET',
            'version_code' => 'V1', 'warranty_applicable' => false,
            'specification' => ['material' => 'thép hợp kim', 'qty_in_set' => 3],
            'status' => 'ACTIVE',
        ],
        [
            'sku_code' => 'HON-GX160-PISTON',
            'sku_name' => 'Piston Honda GX160',
            'sku_type' => 'COMPONENT',
            'unit' => 'cái',
            'is_serial_tracked' => false,
            'brand' => 'HONDA', 'category' => 'SPARE_PART', 'model' => 'GX160',
            'power_or_voltage' => null, 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => false,
            'specification' => ['diameter_mm' => 68, 'material' => 'hợp kim nhôm'],
            'status' => 'ACTIVE',
        ],
        [
            'sku_code' => 'HUS-R2-CUTBLADE',
            'sku_name' => 'Lưỡi cắt Husqvarna R2',
            'sku_type' => 'COMPONENT',
            'unit' => 'cái',
            'is_serial_tracked' => false,
            'brand' => 'HUSQVARNA', 'category' => 'SPARE_PART', 'model' => 'GX390',
            'power_or_voltage' => null, 'package_variant' => 'BARE',
            'version_code' => 'V1', 'warranty_applicable' => false,
            'specification' => ['length_mm' => 2500, 'material' => 'thép SK-5'],
            'status' => 'ACTIVE',
        ],
    ];

    private const IMPORT_BATCHES = [
        ['import_batch_code' => 'IMP-2026-001', 'shipment_container_code' => 'SHIP-CNT-001', 'supplier_batch_no' => 'SUP-880001', 'import_period' => '2026-Q1', 'supplier_name' => 'Kubota Global Trading'],
        ['import_batch_code' => 'IMP-2026-002', 'shipment_container_code' => 'SHIP-CNT-002', 'supplier_batch_no' => 'SUP-880012', 'import_period' => '2026-Q2', 'supplier_name' => 'Yanmar Vietnam'],
        ['import_batch_code' => 'IMP-2026-003', 'shipment_container_code' => 'SHIP-CNT-003', 'supplier_batch_no' => 'SUP-880023', 'import_period' => '2026-Q2', 'supplier_name' => 'Honda Power Equipment'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = User::query()->where('username', 'admin')->first();
            if (! $admin) {
                $admin = User::query()->orderBy('created_at')->first();
            }
            if (! $admin) {
                throw new \RuntimeException('DemoDataSeeder cần ít nhất 1 user (chạy AuthDatabaseSeeder trước).');
            }
            $createdBy = $admin->user_id;

            $master = $this->seedProductMasters();
            $skuMap = $this->seedSkus($master);
            $batchMap = $this->seedImportBatches();

            $codes = $this->seedPhysicalCodes();
            $containerMap = $this->seedContainers($codes, $skuMap);

            $warehouse = Warehouse::query()->orderBy('created_at')->first();
            if (! $warehouse) {
                throw new \RuntimeException('DemoDataSeeder cần ít nhất 1 warehouse (chạy WarehouseDatabaseSeeder trước).');
            }
            $whId = $warehouse->id;

            [$serialMap, $itemMap] = $this->seedItemInstancesAndSerials($skuMap, $batchMap, $codes, $containerMap, $whId);

            $this->seedStockDocuments($skuMap, $whId, $createdBy, $itemMap);

            $this->seedInventoryBalancesAndMovements($skuMap, $whId, $createdBy, $itemMap);

            $this->seedContainerItems($containerMap, $itemMap, $skuMap);
        }, 5);
    }

    /**
     * @return array<string, ProductMaster> key = master code (uppercase)
     */
    private function seedProductMasters(): array
    {
        $map = [];
        foreach (self::BRANDS as $row) {
            $map[$row['code']] = ProductMaster::firstOrCreate(
                ['master_type' => 'BRAND', 'code' => $row['code']],
                ['name' => $row['name'], 'status' => 'ACTIVE']
            );
        }
        foreach (self::CATEGORIES as $row) {
            $map[$row['code']] = ProductMaster::firstOrCreate(
                ['master_type' => 'CATEGORY', 'code' => $row['code']],
                ['name' => $row['name'], 'status' => 'ACTIVE']
            );
        }
        foreach (self::MODELS as $row) {
            $map[$row['code']] = ProductMaster::firstOrCreate(
                ['master_type' => 'MODEL', 'code' => $row['code']],
                ['name' => $row['name'], 'status' => 'ACTIVE']
            );
        }
        foreach (self::PACKAGES as $row) {
            $map['PKG-'.$row['code']] = ProductMaster::firstOrCreate(
                ['master_type' => 'PACKAGE', 'code' => 'PKG-'.$row['code']],
                ['name' => $row['name'], 'status' => 'ACTIVE']
            );
        }
        foreach (self::POWERS as $row) {
            $map[$row['code']] = ProductMaster::firstOrCreate(
                ['master_type' => 'POWER', 'code' => $row['code']],
                ['name' => $row['name'], 'status' => 'ACTIVE']
            );
        }

        return $map;
    }

    /**
     * @param  array<string, ProductMaster>  $master
     * @return array<string, Sku> key = sku_code
     */
    private function seedSkus(array $master): array
    {
        $map = [];
        foreach (self::SKUS as $row) {
            $sku = Sku::firstOrCreate(
                ['sku_code' => $row['sku_code']],
                [
                    'sku_name' => $row['sku_name'],
                    'sku_type' => $row['sku_type'],
                    'unit' => $row['unit'],
                    'is_serial_tracked' => $row['is_serial_tracked'],
                    'brand_id' => $master[$row['brand']]->master_id,
                    'category_id' => $master[$row['category']]->master_id,
                    'model_id' => $master[$row['model']]->master_id,
                    // power_or_voltage / package_variant là cột chuỗi (string/enum), không phải FK.
                    'power_or_voltage' => $row['power_or_voltage'],
                    'package_variant' => $row['package_variant'],
                    'version_code' => $row['version_code'],
                    'warranty_applicable' => $row['warranty_applicable'],
                    'specification' => $row['specification'],
                    'published_to_app' => false,
                    'status' => $row['status'],
                ]
            );
            $map[$sku->sku_code] = $sku;
        }

        return $map;
    }

    /**
     * @return array<string, ImportBatch> key = import_batch_code
     */
    private function seedImportBatches(): array
    {
        $map = [];
        foreach (self::IMPORT_BATCHES as $row) {
            $batch = ImportBatch::firstOrCreate(
                ['import_batch_code' => $row['import_batch_code']],
                [
                    'shipment_container_code' => $row['shipment_container_code'],
                    'supplier_batch_no' => $row['supplier_batch_no'],
                    'import_period' => $row['import_period'],
                    'supplier_name' => $row['supplier_name'],
                    'status' => 'ACTIVE',
                ]
            );
            $map[$batch->import_batch_code] = $batch;
        }

        return $map;
    }

    /**
     * @return array<string, PhysicalCode> key = code_value
     */
    private function seedPhysicalCodes(): array
    {
        $rows = [
            // Mã vật lý gắn trên máy (QR)
            ['code_value' => 'QR-M-0001', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0002', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0003', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0004', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0005', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0006', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0007', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-M-0008', 'code_type' => 'ITEM', 'carrier_type' => 'QR'],
            // Mã vật lý gắn trên container
            ['code_value' => 'QR-C-CARTON-001', 'code_type' => 'CARTON', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-C-BOX-001', 'code_type' => 'BOX', 'carrier_type' => 'QR'],
            ['code_value' => 'QR-C-TRAY-001', 'code_type' => 'TRAY', 'carrier_type' => 'QR'],
        ];
        $map = [];
        foreach ($rows as $row) {
            $code = PhysicalCode::firstOrCreate(
                ['code_value' => $row['code_value']],
                [
                    'code_type' => $row['code_type'],
                    'carrier_type' => $row['carrier_type'],
                    'status' => 'ACTIVE',
                ]
            );
            $map[$code->code_value] = $code;
        }

        return $map;
    }

    /**
     * @param  array<string, PhysicalCode>  $codes
     * @param  array<string, Sku>  $skuMap
     * @return array<string, Container> key = container_code
     */
    private function seedContainers(array $codes, array $skuMap): array
    {
        $rows = [
            [
                'container_code' => 'CT-CARTON-001',
                'container_type' => 'CARTON',
                'physical_code' => 'QR-C-CARTON-001',
                'primary_sku' => 'KUB-L3310-BLADE',
                'zone_code' => 'Z-PTNK',
                'location_hint' => 'Kệ A1',
                'status' => 'ACTIVE',
            ],
            [
                'container_code' => 'CT-BOX-001',
                'container_type' => 'BOX',
                'physical_code' => 'QR-C-BOX-001',
                'primary_sku' => 'HON-GX160-PISTON',
                'zone_code' => 'Z-PTNK',
                'location_hint' => 'Kệ B2',
                'status' => 'ACTIVE',
            ],
            [
                'container_code' => 'CT-TRAY-001',
                'container_type' => 'TRAY',
                'physical_code' => 'QR-C-TRAY-001',
                'primary_sku' => 'HUS-R2-CUTBLADE',
                'zone_code' => 'Z-PTNK',
                'location_hint' => 'Tầng 2',
                'status' => 'ACTIVE',
            ],
        ];

        $map = [];
        foreach ($rows as $row) {
            $container = Container::firstOrCreate(
                ['container_code' => $row['container_code']],
                [
                    'container_type' => $row['container_type'],
                    'physical_code_id' => $codes[$row['physical_code']]->physical_code_id,
                    'primary_sku_id' => $skuMap[$row['primary_sku']]->sku_id ?? null,
                    'zone_code' => $row['zone_code'],
                    'location_hint' => $row['location_hint'],
                    'status' => $row['status'],
                ]
            );
            $map[$container->container_code] = $container;
        }

        return $map;
    }

    /**
     * @param  array<string, Sku>  $skuMap
     * @param  array<string, ImportBatch>  $batchMap
     * @param  array<string, PhysicalCode>  $codes
     * @param  array<string, Container>  $containerMap
     * @return array{0: array<string, InventorySerial>, 1: array<string, ItemInstance>} key = sku_code
     */
    private function seedItemInstancesAndSerials(array $skuMap, array $batchMap, array $codes, array $containerMap, string $whId): array
    {
        $serialMap = [];
        $itemMap = [];

        // Máy kéo Kubota L3301 (2 chiếc)
        $this->makeItem($skuMap['KUB-L3301-TRAC'], 'KUB3301-SN-0001', 'QR-M-0001', 'IMP-2026-001', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);
        $this->makeItem($skuMap['KUB-L3301-TRAC'], 'KUB3301-SN-0002', 'QR-M-0002', 'IMP-2026-001', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);

        // Máy kéo Kubota L4701 (1 chiếc)
        $this->makeItem($skuMap['KUB-L4701-TRAC'], 'KUB4701-SN-0001', 'QR-M-0003', 'IMP-2026-001', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);

        // Máy gặt Yanmar DC95 (1 chiếc)
        $this->makeItem($skuMap['YAN-DC95-HARV'], 'YANDC95-SN-0001', 'QR-M-0004', 'IMP-2026-002', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);

        // Máy bơm Yanmar YPB30 (2 chiếc)
        $this->makeItem($skuMap['YAN-YPB30-PUMP'], 'YANYPB30-SN-0001', 'QR-M-0005', 'IMP-2026-002', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);
        $this->makeItem($skuMap['YAN-YPB30-PUMP'], 'YANYPB30-SN-0002', 'QR-M-0006', 'IMP-2026-002', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);

        // Động cơ Honda GX160 (2 chiếc)
        $this->makeItem($skuMap['HON-GX160-ENG'], 'HONGX160-SN-0001', 'QR-M-0007', 'IMP-2026-003', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);
        $this->makeItem($skuMap['HON-GX160-ENG'], 'HONGX160-SN-0002', 'QR-M-0008', 'IMP-2026-003', 'IN_STOCK', 'NORMAL', $batchMap, $codes, $whId, $serialMap, $itemMap);

        return [$serialMap, $itemMap];
    }

    private function makeItem(
        Sku $sku,
        string $serialNumber,
        string $codeValue,
        string $batchCode,
        string $status,
        string $condition,
        array $batchMap,
        array $codes,
        string $whId,
        array &$serialMap,
        array &$itemMap
    ): void {
        $importBatchId = $batchMap[$batchCode]->import_batch_id ?? null;

        $item = ItemInstance::firstOrCreate(
            ['serial_number' => $serialNumber],
            [
                'sku_id' => $sku->sku_id,
                'physical_code_id' => $codes[$codeValue]->physical_code_id,
                'item_status' => $status,
                'item_condition' => $condition,
                'import_batch_id' => $importBatchId,
            ]
        );

        $serial = InventorySerial::firstOrCreate(
            ['serial_no' => $serialNumber],
            [
                'product_id' => $sku->sku_id,
                'warehouse_id' => $whId,
                'physical_code_id' => $codes[$codeValue]->physical_code_id,
                'condition' => 'GOOD',
                'stock_condition' => $condition,
                'item_condition' => 'NEW',
                'status' => 'IN_STOCK',
            ]
        );

        $serialMap[$sku->sku_code] ??= [];
        $serialMap[$sku->sku_code][$serialNumber] = $serial;
        $itemMap[$sku->sku_code][$serialNumber] = $item;
    }

    private function seedStockDocuments(array $skuMap, string $whId, string $createdBy, array $itemMap): void
    {
        $documents = [
            [
                'doc_no' => 'REC-2026-0001',
                'note' => 'Nhập lô máy nông nghiệp đợt 1',
                'items' => [
                    ['sku' => 'KUB-L3301-TRAC', 'qty' => 2],
                    ['sku' => 'KUB-L4701-TRAC', 'qty' => 1],
                    ['sku' => 'KUB-L3310-BLADE', 'qty' => 5],
                ],
            ],
            [
                'doc_no' => 'REC-2026-0002',
                'note' => 'Nhập lô máy gặt + bơm + động cơ đợt 2',
                'items' => [
                    ['sku' => 'YAN-DC95-HARV', 'qty' => 1],
                    ['sku' => 'YAN-YPB30-PUMP', 'qty' => 2],
                    ['sku' => 'HON-GX160-ENG', 'qty' => 2],
                    ['sku' => 'HON-GX160-PISTON', 'qty' => 10],
                    ['sku' => 'HUS-R2-CUTBLADE', 'qty' => 8],
                ],
            ],
        ];

        foreach ($documents as $row) {
            $existing = StockDocument::query()->where('doc_no', $row['doc_no'])->first();
            $now = now();

            if ($existing) {
                continue;
            }

            $document = StockDocument::create([
                'doc_type' => StockDocument::TYPE_RECEIPT,
                'doc_no' => $row['doc_no'],
                'doc_date' => $now->toDateString(),
                'status' => StockDocument::STATUS_RECEIVED,
                'dst_warehouse_id' => $whId,
                'note' => $row['note'],
                'submitted_by' => $createdBy,
                'submitted_at' => $now->copy()->subDays(3),
                'approved_by' => $createdBy,
                'approved_at' => $now->copy()->subDays(2),
                'posted_by' => $createdBy,
                'posted_at' => $now->copy()->subDay(),
                'created_by' => $createdBy,
            ]);

            $lineNo = 1;
            foreach ($row['items'] as $line) {
                StockDocumentLine::create([
                    'stock_document_id' => $document->id,
                    'line_no' => $lineNo++,
                    'product_id' => $skuMap[$line['sku']]->sku_id,
                    'qty_planned' => $line['qty'],
                    'qty_actual' => $line['qty'],
                    'scanned_qty' => $line['qty'],
                    'completion_type' => 'full',
                ]);
            }
        }

        // Trả về map document cho seed inventory — dùng static query bên dưới.
    }

    private function seedInventoryBalancesAndMovements(array $skuMap, string $whId, string $createdBy, array $itemMap): void
    {
        $balances = [
            'KUB-L3301-TRAC' => 2,
            'KUB-L4701-TRAC' => 1,
            'YAN-DC95-HARV' => 1,
            'YAN-YPB30-PUMP' => 2,
            'HON-GX160-ENG' => 2,
            'KUB-L3310-BLADE' => 5,
            'HON-GX160-PISTON' => 10,
            'HUS-R2-CUTBLADE' => 8,
        ];

        $hasStockStatus = Schema::hasColumn('stock_movements', 'stock_status');

        foreach ($balances as $skuCode => $qty) {
            $sku = $skuMap[$skuCode];
            $balance = InventoryBalance::firstOrCreate(
                ['warehouse_id' => $whId, 'product_id' => $sku->sku_id, 'stock_status' => 'AVAILABLE'],
                ['on_hand_qty' => $qty, 'available_qty' => $qty, 'reserved_qty' => 0, 'version' => 1]
            );

            $movement = StockMovement::query()
                ->where('business_key', 'RECEIPT:'.$skuCode.':'.$whId)
                ->first();
            if ($movement) {
                continue;
            }

            $now = now();
            $payload = [
                'movement_no' => 'SM-'.strtoupper(Str::substr($skuCode, 0, 6)).'-'.strtoupper(Str::substr((string) Str::uuid(), 0, 8)),
                'business_key' => 'RECEIPT:'.$skuCode.':'.$whId,
                'movement_type' => StockMovement::RECEIPT_IN,
                'warehouse_id' => $whId,
                'product_id' => $sku->sku_id,
                'qty_before' => 0,
                'qty_delta' => $qty,
                'qty_after' => $qty,
                'created_by' => $createdBy,
                'occurred_at' => $now->copy()->subDay(),
                'created_at' => $now->copy()->subDay(),
            ];
            if ($hasStockStatus) {
                $payload['stock_status'] = 'AVAILABLE';
            }
            StockMovement::create($payload);
        }
    }

    private function seedContainerItems(array $containerMap, array $itemMap, array $skuMap): void
    {
        $blueprint = [
            'CT-CARTON-001' => [
                ['sku' => 'KUB-L3310-BLADE', 'qty' => 3],
            ],
            'CT-BOX-001' => [
                ['sku' => 'HON-GX160-PISTON', 'qty' => 6],
            ],
            'CT-TRAY-001' => [
                ['sku' => 'HUS-R2-CUTBLADE', 'qty' => 4],
            ],
        ];

        foreach ($blueprint as $containerCode => $lines) {
            $container = $containerMap[$containerCode];
            foreach ($lines as $line) {
                $exists = ContainerItem::query()
                    ->where('container_id', $container->container_id)
                    ->where('sku_id', $skuMap[$line['sku']]->sku_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                ContainerItem::create([
                    'container_id' => $container->container_id,
                    'sku_id' => $skuMap[$line['sku']]->sku_id,
                    'quantity' => $line['qty'],
                    'status' => 'ACTIVE',
                ]);
            }
        }
    }
}
