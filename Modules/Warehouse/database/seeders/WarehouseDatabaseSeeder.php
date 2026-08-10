<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\DocumentStatusHistory;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\SafeStockAlertHistory;
use Modules\Warehouse\Models\SafeStockConfig;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;

class WarehouseDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $staff = User::query()->where('username', 'staff01')->first() ?? $admin;
        $approver = User::query()->where('username', 'approver01')->first() ?? $admin;
        $keeper = User::query()->where('username', 'keeper01')->first() ?? $admin;

        $warehouses = $this->seedWarehouses();
        $products = $this->seedProducts();

        $this->seedSafeStock($warehouses, $products);
        $this->seedDocumentsAndLedger($warehouses, $products, $staff, $approver, $keeper);
        $this->seedSerials($warehouses, $products);
        $this->seedErdExtensions($warehouses, $products, $admin, $staff, $keeper);
    }

    /**
     * @return array<string, Warehouse>
     */
    private function seedWarehouses(): array
    {
        $rows = [
            ['code' => 'KHO-TONG-HN', 'name' => 'Kho tong Hoa Nam', 'address' => 'KCN Quang Minh, Ha Noi', 'contact_person' => 'Nguyen Van Khoi', 'phone' => '02473000001'],
            ['code' => 'KHO-LINH-KIEN', 'name' => 'Kho linh kien va phu kien', 'address' => 'Lo B2, KCN Quang Minh, Ha Noi', 'contact_person' => 'Tran Thi Mai', 'phone' => '02473000002'],
            ['code' => 'KHO-BAO-HANH', 'name' => 'Kho hang bao hanh', 'address' => 'So 18 Pham Hung, Ha Noi', 'contact_person' => 'Le Minh Duc', 'phone' => '02473000003'],
            ['code' => 'KHO-CHO-XUAT', 'name' => 'Kho cho xuat dai ly', 'address' => 'Cang ICD My Dinh, Ha Noi', 'contact_person' => 'Pham Hoang Nam', 'phone' => '02473000004'],
            ['code' => 'KHO-KIEM-DINH', 'name' => 'Kho kiem dinh chat luong', 'address' => 'Khu QA Hoa Nam, Ha Noi', 'contact_person' => 'Do Thi Lan', 'phone' => '02473000005'],
        ];

        return collect($rows)->mapWithKeys(function ($row) {
            $values = collect($row + ['status' => 'ACTIVE'])
                ->filter(fn ($value, $column) => Schema::hasColumn('warehouses', $column))
                ->all();

            return [
                $row['code'] => Warehouse::updateOrCreate(['code' => $row['code']], $values),
            ];
        })->all();
    }

    /**
     * @return array<string, Sku>
     */
    private function seedProducts(): array
    {
        $rows = [
            ['sku_code' => 'HN-PV-BASE-001', 'sku_name' => 'Bo dieu khien Hoa Nam PV-220', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'brand_code' => 'HOA-NAM', 'category_code' => 'PV', 'model_code' => 'PV-220', 'power_or_voltage' => '220V', 'package_variant' => 'BARE', 'published_to_app' => true, 'is_serial_tracked' => true],
            ['sku_code' => 'HN-PV-BASE-002', 'sku_name' => 'Bo dieu khien Hoa Nam PV-380', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'brand_code' => 'HOA-NAM', 'category_code' => 'PV', 'model_code' => 'PV-380', 'power_or_voltage' => '380V', 'package_variant' => 'BARE', 'published_to_app' => true, 'is_serial_tracked' => true],
            ['sku_code' => 'HN-COMP-BASE-001', 'sku_name' => 'Mach dieu khien trung tam HN-MCU', 'unit' => 'cai', 'sku_type' => 'COMPONENT', 'brand_code' => 'HOA-NAM', 'category_code' => 'COMPONENT', 'model_code' => 'MCU-A1', 'power_or_voltage' => '5V', 'package_variant' => 'KIT', 'published_to_app' => false, 'is_serial_tracked' => false],
            ['sku_code' => 'HN-COMP-BASE-002', 'sku_name' => 'Cam bien dong dien HN-CT', 'unit' => 'cai', 'sku_type' => 'COMPONENT', 'brand_code' => 'HOA-NAM', 'category_code' => 'SENSOR', 'model_code' => 'CT-100', 'power_or_voltage' => '100A', 'package_variant' => 'SET', 'published_to_app' => false, 'is_serial_tracked' => false],
            ['sku_code' => 'HN-PACK-BASE-001', 'sku_name' => 'Bo phu kien lap dat Hoa Nam', 'unit' => 'bo', 'sku_type' => 'COMPONENT', 'brand_code' => 'HOA-NAM', 'category_code' => 'ACCESSORY', 'model_code' => 'KIT-01', 'power_or_voltage' => null, 'package_variant' => 'KIT', 'published_to_app' => true, 'is_serial_tracked' => false],
        ];

        return collect($rows)->mapWithKeys(function ($row) {
            $sku = Sku::updateOrCreate(
                ['sku_code' => $row['sku_code']],
                [
                    'sku_name' => $row['sku_name'],
                    'unit' => $row['unit'],
                    'sku_type' => $row['sku_type'],
                    'is_serial_tracked' => $row['is_serial_tracked'],
                    'power_or_voltage' => $row['power_or_voltage'],
                    'package_variant' => $row['package_variant'],
                    'published_to_app' => $row['published_to_app'],
                    'status' => 'ACTIVE'
                ]
            );

            return [
                $row['sku_code'] => $sku,
            ];
        })->all();
    }

    private function seedSafeStock(array $warehouses, array $products): void
    {
        $mins = [20, 12, 100, 80, 30];
        foreach (array_values($products) as $i => $product) {
            SafeStockConfig::updateOrCreate(
                ['product_id' => $product->sku_id, 'warehouse_id' => $warehouses['KHO-TONG-HN']->id],
                ['min_qty' => $mins[$i], 'max_qty' => $mins[$i] * 6, 'effective_from' => '2026-01-01', 'effective_to' => null]
            );

            SafeStockAlertHistory::firstOrCreate(
                ['product_id' => $product->sku_id, 'warehouse_id' => $warehouses['KHO-TONG-HN']->id, 'created_at' => Carbon::parse("2026-01-0".($i + 1).' 08:00:00')],
                ['current_qty' => $mins[$i] + (5 * $i), 'min_qty' => $mins[$i], 'max_qty' => $mins[$i] * 6, 'old_status' => null, 'new_status' => $i === 1 ? 'low_stock' : 'normal']
            );
        }
    }

    private function seedDocumentsAndLedger(array $warehouses, array $products, User $staff, User $approver, User $keeper): void
    {
        $docs = [
            ['receipt', 'PN-2026-0001', '2026-01-05', 'received', 'KHO-TONG-HN', [['HN-PV-BASE-001', 50], ['HN-COMP-BASE-001', 200]]],
            ['receipt', 'PN-2026-0002', '2026-01-08', 'received', 'KHO-LINH-KIEN', [['HN-COMP-BASE-002', 160], ['HN-PACK-BASE-001', 70]]],
            ['receipt', 'PN-2026-0003', '2026-01-12', 'approved', 'KHO-KIEM-DINH', [['HN-PV-BASE-002', 25], ['HN-PV-BASE-001', 10]]],
            ['issue', 'PX-2026-0001', '2026-01-15', 'issued', 'KHO-TONG-HN', [['HN-PV-BASE-001', 8], ['HN-COMP-BASE-001', 30]]],
            ['issue', 'PX-2026-0002', '2026-01-18', 'pending', 'KHO-CHO-XUAT', [['HN-PACK-BASE-001', 12], ['HN-COMP-BASE-002', 20]]],
        ];

        $balances = [];
        foreach ($docs as $docIndex => [$type, $docNo, $date, $status, $warehouseCode, $lines]) {
            $warehouse = $warehouses[$warehouseCode];
            $document = StockDocument::updateOrCreate(
                ['doc_no' => $docNo],
                [
                    'doc_type' => $type,
                    'doc_date' => $date,
                    'status' => $status,
                    'src_warehouse_id' => $type === 'issue' ? $warehouse->id : null,
                    'dst_warehouse_id' => $type === 'receipt' ? $warehouse->id : null,
                    'note' => 'Du lieu mau nghiep vu Hoa Nam WMS',
                    'create_idempotency_key' => "seed-$docNo",
                    'submitted_by' => $staff->user_id,
                    'submitted_at' => Carbon::parse("$date 09:00:00"),
                    'approved_by' => in_array($status, ['approved', 'received', 'issued'], true) ? $approver->user_id : null,
                    'approved_at' => in_array($status, ['approved', 'received', 'issued'], true) ? Carbon::parse("$date 10:00:00") : null,
                    'posted_by' => in_array($status, ['received', 'issued'], true) ? $keeper->user_id : null,
                    'posted_at' => in_array($status, ['received', 'issued'], true) ? Carbon::parse("$date 11:00:00") : null,
                    'created_by' => $staff->user_id,
                    'updated_by' => $keeper->user_id,
                ]
            );

            foreach ([['draft', 'create'], ['pending', 'submit'], [$status, $status]] as $step => [$to, $action]) {
                DocumentStatusHistory::firstOrCreate(
                    ['stock_document_id' => $document->id, 'to_status' => $to, 'action' => $action],
                    ['from_status' => $step === 0 ? null : ($step === 1 ? 'draft' : 'pending'), 'changed_by' => $step === 2 ? $approver->user_id : $staff->user_id, 'note' => "Seed status $to", 'created_at' => Carbon::parse($date)->addHours(8 + $step)]
                );
            }

            foreach ($lines as $lineIndex => [$productCode, $qty]) {
                $product = $products[$productCode];
                $line = StockDocumentLine::updateOrCreate(
                    ['stock_document_id' => $document->id, 'line_no' => $lineIndex + 1],
                    $this->filterColumns('stock_document_lines', [
                        'product_id' => $product->sku_id,
                        'qty_planned' => $qty,
                        'qty_actual' => in_array($status, ['received', 'issued'], true) ? $qty : 0,
                        'scanned_qty' => in_array($status, ['received', 'issued'], true) ? $qty : 0,
                        'completion_type' => 'full',
                    ])
                );

                if (in_array($status, ['received', 'issued'], true)) {
                    $key = $warehouse->id.'|'.$product->sku_id;
                    $before = $balances[$key] ?? 0;
                    $delta = $type === 'receipt' ? $qty : -$qty;
                    $after = $before + $delta;
                    $balances[$key] = $after;

                    StockMovement::firstOrCreate(
                        ['business_key' => "seed:$docNo:line:".($lineIndex + 1)],
                        $this->filterColumns('stock_movements', [
                            'movement_no' => sprintf('SM-2026-%04d', ($docIndex * 2) + $lineIndex + 1),
                            'movement_type' => $type === 'receipt' ? StockMovement::RECEIPT_IN : StockMovement::ISSUE_OUT,
                            'warehouse_id' => $warehouse->id,
                            'product_id' => $product->sku_id,
                            'stock_status' => 'AVAILABLE',
                            'qty_before' => $before,
                            'qty_delta' => $delta,
                            'qty_after' => $after,
                            'stock_document_id' => $document->id,
                            'stock_document_line_id' => $line->id,
                            'created_by' => $keeper->user_id,
                            'request_id' => "seed-$docNo",
                            'occurred_at' => Carbon::parse("$date 11:05:00"),
                            'created_at' => Carbon::parse("$date 11:05:00"),
                        ])
                    );
                }
            }
        }

        foreach ($balances as $key => $qty) {
            [$warehouseId, $productId] = explode('|', $key);
            InventoryBalance::updateOrCreate(
                $this->filterColumns('inventory_balances', ['warehouse_id' => $warehouseId, 'product_id' => $productId]),
                $this->filterColumns('inventory_balances', ['on_hand_qty' => $qty, 'available_qty' => $qty, 'reserved_qty' => 0])
            );
        }
    }

    private function seedSerials(array $warehouses, array $products): void
    {
        $serialProducts = [$products['HN-PV-BASE-001'], $products['HN-PV-BASE-002']];
        for ($i = 1; $i <= 5; $i++) {
            $product = $serialProducts[$i % 2];
            InventorySerial::updateOrCreate(
                ['serial_no' => sprintf('HN-PV-2026-%05d', $i)],
                $this->filterColumns('inventory_serials', [
                    'product_id' => $product->sku_id,
                    'warehouse_id' => $warehouses['KHO-TONG-HN']->id,
                    'status' => 'IN_STOCK',
                ])
            );
        }
    }

    private function seedErdExtensions(array $warehouses, array $products, User $admin, User $staff, User $keeper): void
    {
        $now = now();
        $warehouseIds = $this->seedWarehouseMirror($warehouses, $now);
        $skuIds = $this->seedSkuMirror($products, $now);

        $this->insertRows('agencies', 'agency_code', [
            ['agency_id' => (string) Str::uuid(), 'agency_code' => 'DL-MIEN-BAC-01', 'agency_name' => 'Dai ly Mien Bac 01', 'phone' => '0901000001', 'address' => 'Ha Noi', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['agency_id' => (string) Str::uuid(), 'agency_code' => 'DL-MIEN-BAC-02', 'agency_name' => 'Dai ly Mien Bac 02', 'phone' => '0901000002', 'address' => 'Hai Phong', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['agency_id' => (string) Str::uuid(), 'agency_code' => 'DL-MIEN-TRUNG-01', 'agency_name' => 'Dai ly Mien Trung 01', 'phone' => '0901000003', 'address' => 'Da Nang', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['agency_id' => (string) Str::uuid(), 'agency_code' => 'DL-MIEN-NAM-01', 'agency_name' => 'Dai ly Mien Nam 01', 'phone' => '0901000004', 'address' => 'TP HCM', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['agency_id' => (string) Str::uuid(), 'agency_code' => 'DL-TAY-NGUYEN-01', 'agency_name' => 'Dai ly Tay Nguyen 01', 'phone' => '0901000005', 'address' => 'Dak Lak', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (['BRAND' => ['HOA-NAM', 'Hoa Nam'], 'CATEGORY' => ['PV', 'Thiet bi PV'], 'CATEGORY2' => ['COMPONENT', 'Linh kien'], 'MODEL' => ['PV-220', 'Model PV 220V'], 'MODEL2' => ['PV-380', 'Model PV 380V']] as $type => [$code, $name]) {
            DB::table('product_masters')->updateOrInsert(['master_type' => str_replace(['2'], '', $type), 'code' => $code], ['master_id' => (string) Str::uuid(), 'name' => $name, 'parent_master_id' => null, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->insertSimpleCodes('defect_catalogs', 'defect_code', 'defect_id', [
            ['DFT-POWER', 'Khong len nguon', 'HIGH'],
            ['DFT-SCRATCH', 'Tray xuoc vo', 'LOW'],
            ['DFT-SENSOR', 'Sai so cam bien', 'MEDIUM'],
            ['DFT-DISPLAY', 'Loi hien thi', 'MEDIUM'],
            ['DFT-PACKAGE', 'Thieu phu kien', 'LOW'],
        ], ['defect_name', 'severity']);

        $document = StockDocument::query()->where('doc_no', 'PN-2026-0001')->first();
        $containerCodeIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $physicalId = $this->upsertWithStableId('physical_codes', 'physical_code_id', ['code_value' => sprintf('QR-HN-2026-%05d', $i)], ['code_type' => 'ITEM', 'carrier_type' => 'QR', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            $product = array_values($products)[$i - 1];
            $warehouse = array_values($warehouses)[$i - 1];
            
            $containerId = $this->upsertWithStableId('containers', 'container_id', ['container_code' => sprintf('PALLET-HN-%03d', $i)], ['physical_code_id' => $physicalId, 'container_type' => 'PALLET', 'parent_container_id' => null, 'primary_sku_id' => $product->sku_id, 'status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->upsertWithStableId('container_items', 'container_item_id', ['container_id' => $containerId, 'sku_id' => $product->sku_id], ['quantity' => 10 * $i, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            $containerCodeIds[] = [$containerId, $physicalId];
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->upsertWithStableId('import_batches', 'import_batch_id', ['import_batch_code' => sprintf('IMP-2026-%03d', $i)], ['import_period' => '2026-Q1', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->seedRemainingExtensions($warehouseIds, $skuIds, $admin, $staff, $keeper, $now);
    }

    private function seedRemainingExtensions(array $warehouseIds, array $skuIds, User $admin, User $staff, User $keeper, Carbon $now): void
    {
        $warehouses = array_values($warehouseIds);
        $skus = array_values($skuIds);
        $products = Sku::query()->limit(5)->get();
        $imports = DB::table('import_batches')->limit(5)->pluck('import_batch_id')->all();
        $containers = DB::table('containers')->limit(5)->pluck('container_id')->all();
        $physicalCodes = DB::table('physical_codes')->limit(5)->pluck('physical_code_id')->all();

        for ($i = 1; $i <= 5; $i++) {
            $idx = $i - 1;
            $product = $products[$idx];
            $itemId = $this->upsertWithStableId('item_instances', 'item_id', ['serial_number' => sprintf('ITEM-HN-2026-%03d', $i)], ['sku_id' => $skus[$idx], 'physical_code_id' => $physicalCodes[$idx] ?? null, 'item_status' => 'IN_STOCK', 'item_condition' => 'NORMAL', 'container_id' => $containers[$idx] ?? null, 'import_batch_id' => $imports[$idx] ?? null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function insertRows(string $table, string $uniqueKey, array $rows): void
    {
        foreach ($rows as $row) {
            $idColumn = array_key_first($row);
            $id = $row[$idColumn];
            unset($row[$idColumn]);
            $this->upsertWithStableId($table, $idColumn, [$uniqueKey => $row[$uniqueKey]], $row, $id);
        }
    }

    private function filterColumns(string $table, array $values): array
    {
        return collect($values)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->all();
    }

    private function seedSkuMirror(array $products, Carbon $now): array
    {
        return collect($products)->mapWithKeys(fn ($product) => [$product->sku_code => $product->sku_id])->all();
    }

    private function seedWarehouseMirror(array $warehouses, Carbon $now): array
    {
        return collect($warehouses)->mapWithKeys(fn ($warehouse) => [$warehouse->code => $warehouse->id])->all();
    }

    private function insertSimpleCodes(string $table, string $codeColumn, string $idColumn, array $rows, array $valueColumns): void
    {
        foreach ($rows as $row) {
            $values = [$idColumn => (string) Str::uuid(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
            foreach ($valueColumns as $index => $column) {
                $values[$column] = $row[$index + 1];
            }
            $id = $values[$idColumn];
            unset($values[$idColumn]);
            $this->upsertWithStableId($table, $idColumn, [$codeColumn => $row[0]], $values, $id);
        }
    }

    private function upsertWithStableId(string $table, string $idColumn, array $match, array $values, ?string $id = null): string
    {
        $match = $this->filterColumns($table, $match);
        $values = $this->filterColumns($table, $values);

        $existingId = DB::table($table)->where($match)->value($idColumn);

        if ($existingId) {
            DB::table($table)->where($match)->update($values);

            return $existingId;
        }

        $id ??= (string) Str::uuid();
        DB::table($table)->insert($match + $values + [$idColumn => $id]);

        return $id;
    }
}
