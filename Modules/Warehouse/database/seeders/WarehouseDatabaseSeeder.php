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

    /**
     * Kho phụ mẫu — đảm bảo bảng warehouses có tối thiểu 5 dòng.
     *
     * uk_warehouses_single_active (partial unique index trên status='ACTIVE')
     * cho phép đúng MỘT warehouse ACTIVE tại một thời điểm — hệ thống hiện tại
     * là single-warehouse. Kho phụ do đó phải INACTIVE, không phải dữ liệu lỗi.
     */
    private const EXTRA_WAREHOUSES = [
        ['code' => 'KHO-HN-DN', 'name' => 'Kho chi nhánh Đà Nẵng', 'address' => 'Số 20, Cầu Giấy, Đà Nẵng', 'contact_person' => 'Trần Thị B', 'phone' => '0900000001', 'status' => 'INACTIVE'],
        ['code' => 'KHO-HN-HCM', 'name' => 'Kho chi nhánh Hồ Chí Minh', 'address' => 'Số 5, Quận 7, TP.HCM', 'contact_person' => 'Lê Văn C', 'phone' => '0900000002', 'status' => 'INACTIVE'],
        ['code' => 'KHO-HN-HP', 'name' => 'Kho chi nhánh Hải Phòng', 'address' => 'Số 15, Lê Chân, Hải Phòng', 'contact_person' => 'Phạm Thị D', 'phone' => '0900000003', 'status' => 'INACTIVE'],
        ['code' => 'KHO-HN-CT', 'name' => 'Kho chi nhánh Cần Thơ', 'address' => 'Số 8, Ninh Kiều, Cần Thơ', 'contact_person' => 'Hoàng Văn E', 'phone' => '0900000004', 'status' => 'INACTIVE'],
    ];

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

    /** SKU mẫu bổ sung — đảm bảo bảng sku có tối thiểu 5 dòng. */
    private const EXTRA_SKUS = [
        ['sku_code' => 'HN-PV-BASE-002', 'sku_name' => 'Máy bơm nước PV mẫu 002', 'sku_type' => 'PRODUCT', 'unit' => 'cái', 'is_serial_tracked' => true, 'version_code' => 'V1', 'warranty_applicable' => true, 'published_to_app' => true, 'status' => 'ACTIVE'],
        ['sku_code' => 'HN-PV-BASE-003', 'sku_name' => 'Máy phát điện PV mẫu 003', 'sku_type' => 'PRODUCT', 'unit' => 'cái', 'is_serial_tracked' => true, 'version_code' => 'V1', 'warranty_applicable' => true, 'published_to_app' => true, 'status' => 'ACTIVE'],
        ['sku_code' => 'HN-COMP-BASE-002', 'sku_name' => 'Bo mạch điều khiển mẫu 002', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => true, 'status' => 'ACTIVE'],
        ['sku_code' => 'HN-COMP-BASE-003', 'sku_name' => 'Dây curoa mẫu 003', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => true, 'status' => 'ACTIVE'],
    ];

    private const AGENCIES = [
        ['agency_code' => 'AG-001', 'agency_name' => 'Đại lý Hòa Nam Hà Nội', 'phone' => '0911111111', 'address' => 'Hà Nội'],
        ['agency_code' => 'AG-002', 'agency_name' => 'Đại lý Hòa Nam Đà Nẵng', 'phone' => '0922222222', 'address' => 'Đà Nẵng'],
        ['agency_code' => 'AG-003', 'agency_name' => 'Đại lý Hòa Nam Hồ Chí Minh', 'phone' => '0933333333', 'address' => 'Hồ Chí Minh'],
        ['agency_code' => 'AG-004', 'agency_name' => 'Đại lý Hòa Nam Hải Phòng', 'phone' => '0944444444', 'address' => 'Hải Phòng'],
        ['agency_code' => 'AG-005', 'agency_name' => 'Đại lý Hòa Nam Cần Thơ', 'phone' => '0955555555', 'address' => 'Cần Thơ'],
    ];

    private const BRANDS = [
        ['brand_code' => 'KUBOTA', 'brand_name' => 'Kubota'],
        ['brand_code' => 'YANMAR', 'brand_name' => 'Yanmar'],
        ['brand_code' => 'HONDA', 'brand_name' => 'Honda'],
        ['brand_code' => 'HUSQVARNA', 'brand_name' => 'Husqvarna'],
        ['brand_code' => 'HOANAM', 'brand_name' => 'Hòa Nam'],
    ];

    private const PRODUCT_CATEGORIES = [
        ['category_code' => 'TRACTOR', 'category_name' => 'Máy kéo'],
        ['category_code' => 'HARVESTER', 'category_name' => 'Máy gặt'],
        ['category_code' => 'PUMP', 'category_name' => 'Máy bơm nước'],
        ['category_code' => 'ENGINE', 'category_name' => 'Động cơ'],
        ['category_code' => 'SPARE_PART', 'category_name' => 'Phụ tùng'],
    ];

    private const PRODUCT_MODELS = [
        ['model_code' => 'MDL-KB-01', 'model_name' => 'Kubota Model 01', 'brand_code' => 'KUBOTA'],
        ['model_code' => 'MDL-YM-01', 'model_name' => 'Yanmar Model 01', 'brand_code' => 'YANMAR'],
        ['model_code' => 'MDL-HD-01', 'model_name' => 'Honda Model 01', 'brand_code' => 'HONDA'],
        ['model_code' => 'MDL-HQ-01', 'model_name' => 'Husqvarna Model 01', 'brand_code' => 'HUSQVARNA'],
        ['model_code' => 'MDL-HN-01', 'model_name' => 'Hòa Nam Model 01', 'brand_code' => 'HOANAM'],
    ];

    private const PV_INDUSTRIES = [
        ['industry_code' => 'AGRICULTURE', 'industry_name' => 'Nông nghiệp'],
        ['industry_code' => 'FORESTRY', 'industry_name' => 'Lâm nghiệp'],
        ['industry_code' => 'CONSTRUCTION', 'industry_name' => 'Xây dựng'],
        ['industry_code' => 'AQUACULTURE', 'industry_name' => 'Thủy sản'],
        ['industry_code' => 'LANDSCAPING', 'industry_name' => 'Cảnh quan'],
    ];

    private const PV_FILTER_TAGS = [
        ['tag_code' => 'BEST_SELLER', 'tag_name' => 'Bán chạy', 'tag_type' => 'MARKETING'],
        ['tag_code' => 'NEW_ARRIVAL', 'tag_name' => 'Hàng mới', 'tag_type' => 'MARKETING'],
        ['tag_code' => 'PROMOTION', 'tag_name' => 'Khuyến mãi', 'tag_type' => 'MARKETING'],
        ['tag_code' => 'HEAVY_DUTY', 'tag_name' => 'Công suất lớn', 'tag_type' => 'SPEC'],
        ['tag_code' => 'ENERGY_SAVING', 'tag_name' => 'Tiết kiệm nhiên liệu', 'tag_type' => 'SPEC'],
    ];

    private const PRINTER_CONFIGS = [
        ['printer_code' => 'PRN-001', 'printer_name' => 'Máy in tem kho tổng 01', 'agent_code' => 'AGENT-001', 'label_size' => '100x150'],
        ['printer_code' => 'PRN-002', 'printer_name' => 'Máy in tem kho tổng 02', 'agent_code' => 'AGENT-002', 'label_size' => '100x150'],
        ['printer_code' => 'PRN-003', 'printer_name' => 'Máy in tem Đà Nẵng', 'agent_code' => 'AGENT-003', 'label_size' => '80x100'],
        ['printer_code' => 'PRN-004', 'printer_name' => 'Máy in tem Hồ Chí Minh', 'agent_code' => 'AGENT-004', 'label_size' => '80x100'],
        ['printer_code' => 'PRN-005', 'printer_name' => 'Máy in tem dự phòng', 'agent_code' => 'AGENT-005', 'label_size' => '100x150'],
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
        ['power_source_code' => 'DIESEL', 'power_source_name' => 'Dầu diesel'],
        ['power_source_code' => 'GASOLINE', 'power_source_name' => 'Xăng'],
        ['power_source_code' => 'SOLAR', 'power_source_name' => 'Năng lượng mặt trời'],
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
        ['package_code' => 'COMBO', 'package_name' => 'Combo — kèm quà tặng'],
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

        foreach (self::EXTRA_WAREHOUSES as $row) {
            Warehouse::query()->updateOrCreate(['code' => $row['code']], $row);
        }

        $this->seedCanonicalMasters();

        Sku::updateOrCreate(
            ['sku_code' => self::BASELINE_SKU['sku_code']],
            collect(self::BASELINE_SKU)->except('sku_code')->all()
        );

        foreach (self::EXTRA_SKUS as $row) {
            Sku::updateOrCreate(['sku_code' => $row['sku_code']], collect($row)->except('sku_code')->all());
        }

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
        $this->seedBrandsAndCategories();
        $this->seedProductModels();
        $this->seedPvMasters();
        $this->seedPrinterConfigs();
    }

    private function seedBrandsAndCategories(): void
    {
        foreach (self::BRANDS as $row) {
            if (DB::table('brands')->where('brand_code', $row['brand_code'])->exists()) {
                continue;
            }

            DB::table('brands')->insert([
                'id' => (string) Str::uuid(),
                'brand_code' => $row['brand_code'],
                'brand_name' => $row['brand_name'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
        }

        foreach (self::PRODUCT_CATEGORIES as $row) {
            if (DB::table('product_categories')->where('category_code', $row['category_code'])->exists()) {
                continue;
            }

            DB::table('product_categories')->insert([
                'id' => (string) Str::uuid(),
                'category_code' => $row['category_code'],
                'category_name' => $row['category_name'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
        }
    }

    /**
     * product_models.brand_id tham chiếu brands.id — phải chạy sau seedBrandsAndCategories().
     */
    private function seedProductModels(): void
    {
        foreach (self::PRODUCT_MODELS as $row) {
            $existing = DB::table('product_models')->where('model_code', $row['model_code'])->first();
            if ($existing !== null) {
                continue;
            }

            $brandId = DB::table('brands')->where('brand_code', $row['brand_code'])->value('id');
            DB::table('product_models')->insert([
                'id' => (string) Str::uuid(),
                'model_code' => $row['model_code'],
                'model_name' => $row['model_name'],
                'brand_id' => $brandId,
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
        }
    }

    /**
     * Ghi vào bảng vật lý số ít (pv_industry/pv_filter_tag) — KHÔNG ghi thẳng
     * pv_industries/pv_tags: trên PostgreSQL đó là bảng mirror một chiều, chỉ
     * trigger AFTER INSERT trên pv_industry/pv_filter_tag mới đồng bộ sang.
     */
    private function seedPvMasters(): void
    {
        foreach (self::PV_INDUSTRIES as $row) {
            $existing = DB::table('pv_industry')->where('industry_code', $row['industry_code'])->first();
            if ($existing !== null) {
                continue;
            }

            DB::table('pv_industry')->insert([
                'industry_id' => (string) Str::uuid(),
                'industry_code' => $row['industry_code'],
                'industry_name' => $row['industry_name'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 1,
            ]);
        }

        foreach (self::PV_FILTER_TAGS as $row) {
            $existing = DB::table('pv_filter_tag')->where('tag_code', $row['tag_code'])->first();
            if ($existing !== null) {
                continue;
            }

            DB::table('pv_filter_tag')->insert([
                'tag_id' => (string) Str::uuid(),
                'tag_code' => $row['tag_code'],
                'tag_name' => $row['tag_name'],
                'tag_type' => $row['tag_type'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 1,
            ]);
        }
    }

    /**
     * label_width_mm/label_height_mm là NOT NULL + CHECK > 0 trên PostgreSQL;
     * trigger hn_erd_normalize_runtime_printer_configs tự parse từ label_size
     * dạng "<rộng>x<cao>" nên chỉ cần truyền label_size đúng định dạng.
     */
    private function seedPrinterConfigs(): void
    {
        if (! Schema::hasTable('printer_configs')) {
            return;
        }

        foreach (self::PRINTER_CONFIGS as $row) {
            $existing = DB::table('printer_configs')->where('printer_code', $row['printer_code'])->first();
            if ($existing !== null) {
                continue;
            }

            [$widthMm, $heightMm] = array_map('floatval', explode('x', $row['label_size']));

            $insert = [
                'id' => (string) Str::uuid(),
                'printer_code' => $row['printer_code'],
                'printer_name' => $row['printer_name'],
                'agent_code' => $row['agent_code'],
                'label_size' => $row['label_size'],
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ];
            foreach (['status' => 'ACTIVE', 'label_width_mm' => $widthMm, 'label_height_mm' => $heightMm, 'dpi' => 203] as $column => $value) {
                if (Schema::hasColumn('printer_configs', $column)) {
                    $insert[$column] = $value;
                }
            }

            DB::table('printer_configs')->insert($insert);
        }
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
