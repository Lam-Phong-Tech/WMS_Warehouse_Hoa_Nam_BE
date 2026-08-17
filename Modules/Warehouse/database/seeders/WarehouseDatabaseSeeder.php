<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;

class WarehouseDatabaseSeeder extends Seeder
{
    private const WAREHOUSE = [
        'code' => 'KHO-TONG-HN',
        'name' => 'Kho tổng Hòa Nam',
        'address' => 'Số 1, KCN Quang Minh, Mê Linh, Hà Nội',
        'contact_person' => 'Nguyễn Văn A',
        'phone' => '0900000000',
        'status' => 'ACTIVE',
    ];

    private const LEGACY_WAREHOUSE_CODE = 'WH-MAIN';

    private const BASELINE_SKU = [
        'sku_code' => 'HN-PV-BASE-001',
        'sku_name' => 'SKU baseline Hòa Nam',
        'sku_type' => 'PRODUCT',
        'unit' => 'cái',
        'is_serial_tracked' => false,
        'version_code' => 'V1',
        'warranty_applicable' => false,
        'published_to_app' => true,
        'status' => 'ACTIVE',
    ];

    private const AGENCIES = [
        ['agency_code' => 'AG-001', 'agency_name' => 'Đại lý Hòa Nam Hà Nội', 'phone' => '0911111111', 'address' => 'Hà Nội'],
        ['agency_code' => 'AG-002', 'agency_name' => 'Đại lý Hòa Nam Đà Nẵng', 'phone' => '0922222222', 'address' => 'Đà Nẵng'],
        ['agency_code' => 'AG-003', 'agency_name' => 'Đại lý Hòa Nam Hồ Chí Minh', 'phone' => '0933333333', 'address' => 'Hồ Chí Minh'],
    ];

    private const DEFECT_CATALOGS = [
        ['defect_code' => 'DEF-001', 'defect_name' => 'Trầy xước bề mặt', 'description' => 'Trầy xước ngoại quan không ảnh hưởng chức năng'],
        ['defect_code' => 'DEF-002', 'defect_name' => 'Vỡ/nứt vỏ máy', 'description' => 'Vỡ hoặc nứt vỏ ngoài của thiết bị'],
        ['defect_code' => 'DEF-003', 'defect_name' => 'Thiếu linh kiện', 'description' => 'Thiếu phụ kiện/linh kiện đi kèm khi nhận hàng'],
        ['defect_code' => 'DEF-004', 'defect_name' => 'Không lên nguồn', 'description' => 'Thiết bị không khởi động được'],
        ['defect_code' => 'DEF-005', 'defect_name' => 'Lỗi phần mềm/firmware', 'description' => 'Lỗi phát sinh do phần mềm hoặc firmware'],
    ];

    private const POWER_SOURCES = [
        ['power_source_code' => 'MAINS_DIRECT', 'power_source_name' => 'Điện lưới trực tiếp'],
        ['power_source_code' => 'BATTERY', 'power_source_name' => 'Pin/ắc quy'],
    ];

    /**
     * REQ-SRS-029: quy cách đóng gói là master mở rộng được, không phải enum cứng.
     * Bốn mã dưới đây chỉ là baseline (đúng bộ giá trị legacy đang dùng trong
     * template import SKU); thêm mã mới đi qua POST /package-variants.
     */
    private const PACKAGE_VARIANTS = [
        ['package_code' => 'BARE', 'package_name' => 'Bare — chỉ máy'],
        ['package_code' => 'KIT', 'package_name' => 'Kit — máy kèm phụ kiện'],
        ['package_code' => 'SET', 'package_name' => 'Set — bộ nhiều máy'],
        ['package_code' => 'BODY_ONLY', 'package_name' => 'Body only — không pin/sạc'],
    ];

    public function run(): void
    {
        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE['code'])->first()
            ?? Warehouse::query()->where('code', self::LEGACY_WAREHOUSE_CODE)->first();

        if ($warehouse === null) {
            Warehouse::query()->create(self::WAREHOUSE);
        } else {
            $warehouse->update(self::WAREHOUSE);
        }

        $this->seedCanonicalMasters();

        Sku::updateOrCreate(
            ['sku_code' => self::BASELINE_SKU['sku_code']],
            collect(self::BASELINE_SKU)->except('sku_code')->all()
        );

        foreach (self::AGENCIES as $agency) {
            Agency::firstOrCreate(
                ['agency_code' => $agency['agency_code']],
                [
                    'agency_name' => $agency['agency_name'],
                    'phone' => $agency['phone'],
                    'address' => $agency['address'],
                    'status' => 'ACTIVE',
                ]
            );
        }

        foreach (self::DEFECT_CATALOGS as $defect) {
            DefectCatalog::firstOrCreate(
                ['defect_code' => $defect['defect_code']],
                [
                    'defect_name' => $defect['defect_name'],
                    'description' => $defect['description'],
                    'status' => 'ACTIVE',
                ]
            );
        }

        // DemoDataSeeder belonged to the superseded demo/legacy workflow and creates
        // product_masters/transfer-style fixtures that no longer satisfy the current
        // physical FK and integer-stock contract. Current baseline seed is deliberately
        // small and deterministic; feature tests create their own transaction fixtures.
    }

    private function seedCanonicalMasters(): void
    {
        foreach (self::POWER_SOURCES as $row) {
            $existing = DB::table('power_sources')->where('power_source_code', $row['power_source_code'])->first();
            if ($existing !== null) {
                DB::table('power_sources')->where('id', $existing->id)->update([
                    'power_source_name' => $row['power_source_name'],
                    'status' => 'ACTIVE',
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('power_sources')->insert([
                'id' => (string) Str::uuid(),
                'power_source_code' => $row['power_source_code'],
                'power_source_name' => $row['power_source_name'],
                'description' => null,
                'sort_order' => 0,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'created_by' => null,
                'updated_at' => now(),
                'updated_by' => null,
                'version' => 0,
            ]);
        }

        $this->seedPackageVariants();
    }

    private function seedPackageVariants(): void
    {
        if (! Schema::hasTable('package_variants')) {
            return;
        }

        foreach (self::PACKAGE_VARIANTS as $row) {
            $existing = DB::table('package_variants')->where('package_code', $row['package_code'])->first();
            if ($existing !== null) {
                continue;
            }

            $insert = [
                'id' => (string) Str::uuid(),
                'package_code' => $row['package_code'],
                'package_name' => $row['package_name'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // sqlite giữ cả cặp cột legacy variant_* (PostgreSQL đồng bộ bằng trigger).
            foreach (['variant_code' => $row['package_code'], 'variant_name' => $row['package_name'], 'description' => null, 'created_by' => null, 'updated_by' => null, 'version' => 0] as $column => $value) {
                if (Schema::hasColumn('package_variants', $column)) {
                    $insert[$column] = $value;
                }
            }

            DB::table('package_variants')->insert($insert);
        }
    }
}
