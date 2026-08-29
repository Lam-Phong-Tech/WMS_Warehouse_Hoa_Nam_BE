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
        'name' => 'Kho tổng Hoa Nam',
        'address' => 'Số 1, KCN Quang Minh, Mê Linh, Hà Nội',
        'contact_person' => 'Nguyễn Văn A',
        'phone' => '0900000000',
        'status' => 'ACTIVE',
    ];

    private const LEGACY_WAREHOUSE_CODE = 'WH-MAIN';

    private const BASELINE_SKU = [
        'sku_code' => 'HN-PV-BASE-001',
        'sku_name' => 'SKU cơ sở Hoa Nam',
        'sku_type' => 'PRODUCT',
        'unit' => 'cái',
        'is_serial_tracked' => false,
        'version_code' => 'V1',
        'warranty_applicable' => false,
        'published_to_app' => true,
        'status' => 'ACTIVE',
        'brand_code' => 'HOANAM',
        'category_code' => 'PUMP',
        'model_code' => 'MDL-HN-01',
        'power_source_code' => 'MAINS_DIRECT',
        'package_code' => 'BARE',
    ];

    /** SKU mẫu bổ sung — đảm bảo bảng sku có tối thiểu 5 dòng. */
    private const EXTRA_SKUS = [
        ['sku_code' => 'HN-PV-BASE-002', 'sku_name' => 'Máy bơm nước PV mẫu 002', 'sku_type' => 'PRODUCT', 'unit' => 'cái', 'is_serial_tracked' => true, 'version_code' => 'V1', 'warranty_applicable' => true, 'published_to_app' => true, 'status' => 'ACTIVE', 'brand_code' => 'HOANAM', 'category_code' => 'PUMP', 'model_code' => 'MDL-HN-01', 'power_source_code' => 'SOLAR', 'package_code' => 'KIT'],
        ['sku_code' => 'HN-PV-BASE-003', 'sku_name' => 'Máy phát điện PV mẫu 003', 'sku_type' => 'PRODUCT', 'unit' => 'cái', 'is_serial_tracked' => true, 'version_code' => 'V1', 'warranty_applicable' => true, 'published_to_app' => true, 'status' => 'ACTIVE', 'brand_code' => 'KUBOTA', 'category_code' => 'ENGINE', 'model_code' => 'MDL-KB-01', 'power_source_code' => 'DIESEL', 'package_code' => 'SET'],
        ['sku_code' => 'HN-COMP-BASE-002', 'sku_name' => 'Bo mạch điều khiển mẫu 002', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => true, 'status' => 'ACTIVE', 'brand_code' => 'HOANAM', 'category_code' => 'SPARE_PART', 'model_code' => 'MDL-HN-01', 'power_source_code' => 'BATTERY', 'package_code' => 'BARE'],
        ['sku_code' => 'HN-COMP-BASE-003', 'sku_name' => 'Dây curoa mẫu 003', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => true, 'status' => 'ACTIVE', 'brand_code' => 'HOANAM', 'category_code' => 'SPARE_PART', 'model_code' => 'MDL-HN-01', 'power_source_code' => null, 'package_code' => 'BARE'],
    ];

    private const AGENCIES = [
        ['agency_code' => 'AG-001', 'agency_name' => 'Đại lý Hoa Nam Hà Nội', 'contact_name' => 'Nguyễn Thị Hạnh', 'phone' => '0911111111', 'address' => 'Hà Nội'],
        ['agency_code' => 'AG-002', 'agency_name' => 'Đại lý Hoa Nam Đà Nẵng', 'contact_name' => 'Trần Văn Bình', 'phone' => '0922222222', 'address' => 'Đà Nẵng'],
        ['agency_code' => 'AG-003', 'agency_name' => 'Đại lý Hoa Nam Hồ Chí Minh', 'contact_name' => 'Lê Thị Cúc', 'phone' => '0933333333', 'address' => 'Hồ Chí Minh'],
        ['agency_code' => 'AG-004', 'agency_name' => 'Đại lý Hoa Nam Hải Phòng', 'contact_name' => 'Phạm Văn Đức', 'phone' => '0944444444', 'address' => 'Hải Phòng'],
        ['agency_code' => 'AG-005', 'agency_name' => 'Đại lý Hoa Nam Cần Thơ', 'contact_name' => 'Hoàng Thị Em', 'phone' => '0955555555', 'address' => 'Cần Thơ'],
    ];

    private const BRANDS = [
        ['brand_code' => 'KUBOTA', 'brand_name' => 'Kubota', 'description' => 'Hãng máy nông nghiệp và động cơ diesel Nhật Bản.'],
        ['brand_code' => 'YANMAR', 'brand_name' => 'Yanmar', 'description' => 'Hãng động cơ và máy nông nghiệp Nhật Bản.'],
        ['brand_code' => 'HONDA', 'brand_name' => 'Honda', 'description' => 'Hãng động cơ đa dụng và máy cầm tay Nhật Bản.'],
        ['brand_code' => 'HUSQVARNA', 'brand_name' => 'Husqvarna', 'description' => 'Hãng thiết bị cắt cỏ, lâm nghiệp Thụy Điển.'],
        ['brand_code' => 'HOANAM', 'brand_name' => 'Hoa Nam', 'description' => 'Thương hiệu nội bộ Hoa Nam — sản phẩm lắp ráp/gia công trong nước.'],
    ];

    private const PRODUCT_CATEGORIES = [
        ['category_code' => 'TRACTOR', 'category_name' => 'Máy kéo', 'description' => 'Máy kéo phục vụ canh tác, làm đất.'],
        ['category_code' => 'HARVESTER', 'category_name' => 'Máy gặt', 'description' => 'Máy gặt đập liên hợp và thiết bị thu hoạch.'],
        ['category_code' => 'PUMP', 'category_name' => 'Máy bơm nước', 'description' => 'Máy bơm nước tưới tiêu, thoát nước.'],
        ['category_code' => 'ENGINE', 'category_name' => 'Động cơ', 'description' => 'Động cơ xăng/diesel dùng cho máy phát điện, máy nông nghiệp.'],
        ['category_code' => 'SPARE_PART', 'category_name' => 'Phụ tùng', 'description' => 'Linh kiện, phụ tùng thay thế cho máy móc.'],
    ];

    private const PRODUCT_MODELS = [
        ['model_code' => 'MDL-KB-01', 'model_name' => 'Kubota Model 01', 'brand_code' => 'KUBOTA', 'description' => 'Dòng model tiêu chuẩn của Kubota cho máy phát điện/động cơ diesel.'],
        ['model_code' => 'MDL-YM-01', 'model_name' => 'Yanmar Model 01', 'brand_code' => 'YANMAR', 'description' => 'Dòng model tiêu chuẩn của Yanmar cho máy xới đất.'],
        ['model_code' => 'MDL-HD-01', 'model_name' => 'Honda Model 01', 'brand_code' => 'HONDA', 'description' => 'Dòng model tiêu chuẩn của Honda cho động cơ đa dụng.'],
        ['model_code' => 'MDL-HQ-01', 'model_name' => 'Husqvarna Model 01', 'brand_code' => 'HUSQVARNA', 'description' => 'Dòng model tiêu chuẩn của Husqvarna cho máy cắt cỏ.'],
        ['model_code' => 'MDL-HN-01', 'model_name' => 'Hoa Nam Model 01', 'brand_code' => 'HOANAM', 'description' => 'Dòng model nội bộ Hoa Nam cho máy bơm/bo mạch/dây curoa.'],
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
        ['power_source_code' => 'MAINS_DIRECT', 'power_source_name' => 'Điện lưới trực tiếp', 'description' => 'Cắm trực tiếp vào điện lưới 220V, không qua pin/ắc quy.'],
        ['power_source_code' => 'BATTERY', 'power_source_name' => 'Pin/ắc quy', 'description' => 'Chạy bằng pin sạc hoặc ắc quy rời.'],
        ['power_source_code' => 'DIESEL', 'power_source_name' => 'Dầu diesel', 'description' => 'Động cơ đốt trong dùng nhiên liệu diesel.'],
        ['power_source_code' => 'GASOLINE', 'power_source_name' => 'Xăng', 'description' => 'Động cơ đốt trong dùng nhiên liệu xăng.'],
        ['power_source_code' => 'SOLAR', 'power_source_name' => 'Năng lượng mặt trời', 'description' => 'Vận hành bằng tấm pin năng lượng mặt trời.'],
    ];

    /**
     * REQ-SRS-029: quy cách đóng gói là master mở rộng được, không phải enum cứng.
     * Bốn mã dưới đây chỉ là baseline (đúng bộ giá trị legacy đang dùng trong
     * template import SKU); thêm mã mới đi qua POST /package-variants.
     */
    private const PACKAGE_VARIANTS = [
        ['package_code' => 'BARE', 'package_name' => 'Bare — chỉ máy', 'description' => 'Chỉ gồm thân máy, không kèm phụ kiện/pin/sạc.'],
        ['package_code' => 'KIT', 'package_name' => 'Kit — máy kèm phụ kiện', 'description' => 'Máy kèm bộ phụ kiện tiêu chuẩn đi kèm.'],
        ['package_code' => 'SET', 'package_name' => 'Set — bộ nhiều máy', 'description' => 'Đóng gói thành bộ gồm nhiều máy/thiết bị.'],
        ['package_code' => 'BODY_ONLY', 'package_name' => 'Body only — không pin/sạc', 'description' => 'Chỉ thân máy, không kèm pin và bộ sạc.'],
        ['package_code' => 'COMBO', 'package_name' => 'Combo — kèm quà tặng', 'description' => 'Đóng gói combo kèm quà tặng khuyến mãi.'],
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
            $this->resolveSkuCatalogRefs(collect(self::BASELINE_SKU)->except('sku_code')->all())
        );

        foreach (self::EXTRA_SKUS as $row) {
            Sku::updateOrCreate(
                ['sku_code' => $row['sku_code']],
                $this->resolveSkuCatalogRefs(collect($row)->except('sku_code')->all())
            );
        }

        foreach (self::AGENCIES as $agency) {
            Agency::updateOrCreate(
                ['agency_code' => $agency['agency_code']],
                [
                    'agency_name' => $agency['agency_name'],
                    'contact_name' => $agency['contact_name'],
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

    /**
     * sku.brand_id/category_id/model_id trên SQLite vẫn FK vào product_masters.master_id
     * (bảng polymorphic cũ) thay vì thẳng brands/product_categories/product_models —
     * trên PostgreSQL có trigger 2026_08_16_135000 tự đồng bộ nhưng trigger đó chỉ
     * chạy khi `DB::getDriverName() === 'pgsql'`. Không có bước này, seed SKU kèm
     * brand/category/model trên SQLite sẽ vỡ FOREIGN KEY constraint. Ghi thẳng vào
     * đây (idempotent, chạy được cả 2 driver — trên PG chỉ là ghi trùng vô hại vì
     * trigger đã làm sẵn) để không phụ thuộc trigger pgsql-only.
     */
    private function syncProductMaster(string $masterType, string $id, string $code, string $name, ?string $parentMasterId = null): void
    {
        if (! Schema::hasTable('product_masters')) {
            return;
        }

        $existing = DB::table('product_masters')->where('master_id', $id)->first();
        if ($existing !== null) {
            DB::table('product_masters')->where('master_id', $id)->update([
                'master_type' => $masterType,
                'code' => $code,
                'name' => $name,
                'parent_master_id' => $parentMasterId,
                'status' => 'ACTIVE',
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('product_masters')->insert([
            'master_id' => $id,
            'master_type' => $masterType,
            'code' => $code,
            'name' => $name,
            'parent_master_id' => $parentMasterId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'version' => 0,
        ]);
    }

    /**
     * SKU const dùng *_code (brand_code/category_code/model_code/power_source_code/
     * package_code) để đọc dễ hơn UUID; ở đây đổi sang *_id thật trước khi ghi vào
     * Sku (chạy sau seedCanonicalMasters() nên các bảng catalog đã có sẵn).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolveSkuCatalogRefs(array $row): array
    {
        $map = [
            'brand_code' => ['brand_id', 'brands', 'brand_code'],
            'category_code' => ['category_id', 'product_categories', 'category_code'],
            'model_code' => ['model_id', 'product_models', 'model_code'],
            'power_source_code' => ['power_source_id', 'power_sources', 'power_source_code'],
            'package_code' => ['package_variant_id', 'package_variants', 'package_code'],
        ];

        foreach ($map as $codeKey => [$idKey, $table, $column]) {
            if (! array_key_exists($codeKey, $row)) {
                continue;
            }

            $code = $row[$codeKey];
            unset($row[$codeKey]);
            $row[$idKey] = $code !== null ? DB::table($table)->where($column, $code)->value('id') : null;
        }

        return $row;
    }

    private function seedCanonicalMasters(): void
    {
        foreach (self::POWER_SOURCES as $row) {
            $existing = DB::table('power_sources')->where('power_source_code', $row['power_source_code'])->first();
            if ($existing !== null) {
                DB::table('power_sources')->where('id', $existing->id)->update([
                    'power_source_name' => $row['power_source_name'],
                    'description' => $row['description'],
                    'status' => 'ACTIVE',
                    'updated_at' => now(),
                ]);
                $this->syncProductMaster('POWER', $existing->id, $row['power_source_code'], $row['power_source_name']);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('power_sources')->insert([
                'id' => $id,
                'power_source_code' => $row['power_source_code'],
                'power_source_name' => $row['power_source_name'],
                'description' => $row['description'],
                'sort_order' => 0,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'created_by' => null,
                'updated_at' => now(),
                'updated_by' => null,
                'version' => 0,
            ]);
            $this->syncProductMaster('POWER', $id, $row['power_source_code'], $row['power_source_name']);
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
            $existing = DB::table('brands')->where('brand_code', $row['brand_code'])->first();
            if ($existing !== null) {
                DB::table('brands')->where('id', $existing->id)->update([
                    'brand_name' => $row['brand_name'],
                    'description' => $row['description'],
                    'updated_at' => now(),
                ]);
                $this->syncProductMaster('BRAND', $existing->id, $row['brand_code'], $row['brand_name']);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('brands')->insert([
                'id' => $id,
                'brand_code' => $row['brand_code'],
                'brand_name' => $row['brand_name'],
                'description' => $row['description'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
            $this->syncProductMaster('BRAND', $id, $row['brand_code'], $row['brand_name']);
        }

        foreach (self::PRODUCT_CATEGORIES as $row) {
            $existing = DB::table('product_categories')->where('category_code', $row['category_code'])->first();
            if ($existing !== null) {
                DB::table('product_categories')->where('id', $existing->id)->update([
                    'category_name' => $row['category_name'],
                    'description' => $row['description'],
                    'updated_at' => now(),
                ]);
                $this->syncProductMaster('CATEGORY', $existing->id, $row['category_code'], $row['category_name']);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('product_categories')->insert([
                'id' => $id,
                'category_code' => $row['category_code'],
                'category_name' => $row['category_name'],
                'description' => $row['description'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
            $this->syncProductMaster('CATEGORY', $id, $row['category_code'], $row['category_name']);
        }
    }

    /**
     * product_models.brand_id tham chiếu brands.id — phải chạy sau seedBrandsAndCategories().
     */
    private function seedProductModels(): void
    {
        foreach (self::PRODUCT_MODELS as $row) {
            $brandId = DB::table('brands')->where('brand_code', $row['brand_code'])->value('id');
            $existing = DB::table('product_models')->where('model_code', $row['model_code'])->first();
            if ($existing !== null) {
                DB::table('product_models')->where('id', $existing->id)->update([
                    'model_name' => $row['model_name'],
                    'brand_id' => $brandId,
                    'description' => $row['description'],
                    'updated_at' => now(),
                ]);
                $this->syncProductMaster('MODEL', $existing->id, $row['model_code'], $row['model_name'], $brandId);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('product_models')->insert([
                'id' => $id,
                'model_code' => $row['model_code'],
                'model_name' => $row['model_name'],
                'brand_id' => $brandId,
                'description' => $row['description'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
            $this->syncProductMaster('MODEL', $id, $row['model_code'], $row['model_name'], $brandId);
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
                $update = ['package_name' => $row['package_name'], 'updated_at' => now()];
                if (Schema::hasColumn('package_variants', 'description')) {
                    $update['description'] = $row['description'];
                }
                DB::table('package_variants')->where('id', $existing->id)->update($update);
                $this->syncProductMaster('PACKAGE', $existing->id, $row['package_code'], $row['package_name']);

                continue;
            }

            $id = (string) Str::uuid();
            $insert = [
                'id' => $id,
                'package_code' => $row['package_code'],
                'package_name' => $row['package_name'],
                'status' => 'ACTIVE',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // sqlite giữ cả cặp cột legacy variant_* (PostgreSQL đồng bộ bằng trigger).
            foreach (['variant_code' => $row['package_code'], 'variant_name' => $row['package_name'], 'description' => $row['description'], 'created_by' => null, 'updated_by' => null, 'version' => 0] as $column => $value) {
                if (Schema::hasColumn('package_variants', $column)) {
                    $insert[$column] = $value;
                }
            }

            DB::table('package_variants')->insert($insert);
            $this->syncProductMaster('PACKAGE', $id, $row['package_code'], $row['package_name']);
        }
    }
}
