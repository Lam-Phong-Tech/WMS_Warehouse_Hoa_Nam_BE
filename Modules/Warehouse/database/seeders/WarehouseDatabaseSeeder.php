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

    /**
     * SKU nền — HÀNG THẬT của Hoa Nam (nhà phân phối DongCheng), không còn mã demo
     * `HN-PV-BASE-*`/`HN-COMP-BASE-*`. Ở đây chỉ khai những mã dùng nhóm hàng đã
     * được chính seeder này tạo (dụng cụ cầm tay + phụ tùng); toàn bộ 992 mã còn
     * lại — kể cả 15 nhóm hàng máy PT_* — do DongChengCatalogSeeder nạp ngay sau.
     *
     * Xem DongChengAnchorSkus: mọi seeder nghiệp vụ đều neo vào cùng bộ mã này.
     */
    private const BASELINE_SKU = [
        'sku_code' => DongChengAnchorSkus::BASELINE_PRODUCT,
        'sku_name' => 'Kìm điện đa năng 6 inch',
        'sku_type' => 'PRODUCT',
        'unit' => 'cái',
        'is_serial_tracked' => false,
        'version_code' => 'V1',
        'warranty_applicable' => false,
        'published_to_app' => true,
        'status' => 'ACTIVE',
        'brand_code' => 'DONGCHENG',
        'category_code' => 'CLAMPING_TOOLS',
        'model_code' => null,
        'power_source_code' => 'MANUAL',
        'package_code' => 'BARE',
    ];

    /** Linh kiện nền — đảm bảo bảng sku có tối thiểu 5 dòng ngay sau bước này. */
    private const EXTRA_SKUS = [
        ['sku_code' => DongChengAnchorSkus::COMPONENT_1, 'sku_name' => 'Bộ mũi vít thay thế DongCheng', 'sku_type' => 'COMPONENT', 'unit' => 'bộ', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => false, 'status' => 'ACTIVE', 'brand_code' => 'DONGCHENG', 'category_code' => 'SPARE_PART', 'model_code' => null, 'power_source_code' => 'MANUAL', 'package_code' => 'BARE'],
        ['sku_code' => DongChengAnchorSkus::COMPONENT_2, 'sku_name' => 'Sạc nhanh DongCheng 20V MAX 2A', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => false, 'status' => 'ACTIVE', 'brand_code' => 'DONGCHENG', 'category_code' => 'SPARE_PART', 'model_code' => null, 'power_source_code' => 'MAINS_DIRECT', 'package_code' => 'BARE'],
        ['sku_code' => DongChengAnchorSkus::COMPONENT_3, 'sku_name' => 'Đầu kẹp mũi khoan 13mm DongCheng', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => false, 'status' => 'ACTIVE', 'brand_code' => 'DONGCHENG', 'category_code' => 'SPARE_PART', 'model_code' => null, 'power_source_code' => 'MANUAL', 'package_code' => 'BARE'],
        ['sku_code' => DongChengAnchorSkus::COMPONENT_BOX, 'sku_name' => 'Túi hứng bụi DongCheng', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => false, 'status' => 'ACTIVE', 'brand_code' => 'DONGCHENG', 'category_code' => 'SPARE_PART', 'model_code' => null, 'power_source_code' => 'MANUAL', 'package_code' => 'BARE'],
        ['sku_code' => DongChengAnchorSkus::COMPONENT_WARRANTY, 'sku_name' => 'Công tắc nguồn thay thế DongCheng', 'sku_type' => 'COMPONENT', 'unit' => 'cái', 'is_serial_tracked' => false, 'version_code' => 'V1', 'warranty_applicable' => false, 'published_to_app' => false, 'status' => 'ACTIVE', 'brand_code' => 'DONGCHENG', 'category_code' => 'SPARE_PART', 'model_code' => null, 'power_source_code' => 'MANUAL', 'package_code' => 'BARE'],
    ];

    private const AGENCIES = [
        ['agency_code' => 'AG-001', 'agency_name' => 'Đại lý Hoa Nam Hà Nội', 'contact_name' => 'Nguyễn Thị Hạnh', 'phone' => '0911111111', 'address' => 'Hà Nội'],
        ['agency_code' => 'AG-002', 'agency_name' => 'Đại lý Hoa Nam Đà Nẵng', 'contact_name' => 'Trần Văn Bình', 'phone' => '0922222222', 'address' => 'Đà Nẵng'],
        ['agency_code' => 'AG-003', 'agency_name' => 'Đại lý Hoa Nam Hồ Chí Minh', 'contact_name' => 'Lê Thị Cúc', 'phone' => '0933333333', 'address' => 'Hồ Chí Minh'],
        ['agency_code' => 'AG-004', 'agency_name' => 'Đại lý Hoa Nam Hải Phòng', 'contact_name' => 'Phạm Văn Đức', 'phone' => '0944444444', 'address' => 'Hải Phòng'],
        ['agency_code' => 'AG-005', 'agency_name' => 'Đại lý Hoa Nam Cần Thơ', 'contact_name' => 'Hoàng Thị Em', 'phone' => '0955555555', 'address' => 'Cần Thơ'],
    ];

    /**
     * Hoa Nam là NHÀ PHÂN PHỐI DONGCHENG — toàn bộ 992 mã hàng trong kho đều của
     * hãng này. Năm hãng máy nông nghiệp cũ (Kubota/Yanmar/Honda/Husqvarna + nhãn
     * nội bộ "Hoa Nam") chỉ tồn tại để bộ seeder demo bịa tên sản phẩm, chưa bao
     * giờ có hàng thật, nên đã gỡ khỏi danh mục.
     */
    private const BRANDS = [
        ['brand_code' => 'DONGCHENG', 'brand_name' => 'DongCheng', 'description' => 'Hãng dụng cụ cầm tay và máy cầm tay Trung Quốc — Hoa Nam là nhà phân phối.'],
    ];

    private const PRODUCT_CATEGORIES = [
        ['category_code' => 'SPARE_PART', 'category_name' => 'Phụ tùng', 'description' => 'Linh kiện, phụ tùng thay thế cho máy móc.'],
        // Hand Tools DongCheng (P0) — 12 category cha, ánh xạ 1:1 với cột "Phân
        // loại theo chức năng chung" của catalog nguồn. "Chủng loại máy" (172 giá
        // trị) KHÔNG trở thành category — nó là product_family (sku.family_name).
        ['category_code' => 'CLAMPING_TOOLS', 'category_name' => 'Dụng cụ kẹp giữ', 'description' => 'Kìm, mỏ lết, ê-tô và dụng cụ kẹp giữ chi tiết.'],
        ['category_code' => 'WRENCHES', 'category_name' => 'Cờ lê', 'description' => 'Cờ lê các loại dùng để siết/vặn bu-lông, đai ốc.'],
        ['category_code' => 'SOCKETS', 'category_name' => 'Đầu tuýp', 'description' => 'Đầu tuýp và bộ đầu tuýp dùng kèm tay vặn/cần siết.'],
        ['category_code' => 'FASTENING_TOOLS', 'category_name' => 'Dụng cụ siết/vặn', 'description' => 'Tua vít, tay vặn, cần siết và dụng cụ siết/vặn chi tiết.'],
        ['category_code' => 'CUTTING_TOOLS', 'category_name' => 'Dụng cụ cắt', 'description' => 'Dao, kéo, lưỡi cắt và dụng cụ cắt cầm tay.'],
        ['category_code' => 'MEASURING_TOOLS', 'category_name' => 'Dụng cụ đo lường', 'description' => 'Thước, ni-vô và dụng cụ đo lường cầm tay.'],
        ['category_code' => 'ELECTRICAL_TOOLS', 'category_name' => 'Dụng cụ điện', 'description' => 'Dụng cụ hỗ trợ thi công, kiểm tra hệ thống điện/điện tử.'],
        ['category_code' => 'PAINTING_WALL_TOOLS', 'category_name' => 'Dụng cụ sơn & làm tường', 'description' => 'Dụng cụ thi công sơn, keo, gạch và hoàn thiện bề mặt.'],
        ['category_code' => 'MECHANICAL_TOOLS', 'category_name' => 'Dụng cụ cơ khí', 'description' => 'Dụng cụ cơ khí đa dụng phục vụ lắp ráp, sửa chữa.'],
        ['category_code' => 'HYDRAULIC_TOOLS', 'category_name' => 'Dụng cụ thủy lực', 'description' => 'Kích, cảo và dụng cụ thủy lực cầm tay.'],
        ['category_code' => 'STORAGE_TOOLS', 'category_name' => 'Dụng cụ lưu trữ', 'description' => 'Hộp, tủ và dụng cụ lưu trữ/sắp xếp đồ nghề.'],
        ['category_code' => 'GARDEN_HAND_TOOLS', 'category_name' => 'Dụng cụ làm vườn cầm tay', 'description' => 'Kéo cắt tỉa và dụng cụ làm vườn cầm tay.'],
    ];

    /**
     * Dòng máy (sê-ri) đến từ chính mã catalog DongCheng — DongChengCatalogSeeder
     * nạp 113 sê-ri thật (DCPB, DCSM, DCZC, D07...). Ở đây chỉ giữ sê-ri phụ kiện
     * để 5 SKU linh kiện nền phía trên có dòng máy ngay từ bước master.
     */
    private const PRODUCT_MODELS = [
        ['model_code' => 'DC-DCA', 'model_name' => 'DongCheng sê-ri DCA', 'brand_code' => 'DONGCHENG', 'description' => 'Sê-ri phụ kiện & linh kiện thay thế DongCheng.'],
        ['model_code' => 'DC-DCB', 'model_name' => 'DongCheng sê-ri DCB', 'brand_code' => 'DONGCHENG', 'description' => 'Sê-ri pin Li-ion 20V MAX DongCheng.'],
        ['model_code' => 'DC-DCC', 'model_name' => 'DongCheng sê-ri DCC', 'brand_code' => 'DONGCHENG', 'description' => 'Sê-ri bộ sạc pin 20V MAX DongCheng.'],
    ];

    /**
     * Ngành nghề App PV — người dùng chốt 06/09/2026: ĐÚNG 5 mục này, đúng thứ tự
     * này. Đây là bộ lọc khách bấm trên App PV nên nó bám theo NGHỀ của khách, chứ
     * không theo ngành kinh tế chung như bộ cũ (Nông nghiệp / Lâm nghiệp / Thủy
     * sản / Cảnh quan).
     */
    private const PV_INDUSTRIES = [
        ['industry_code' => 'WOOD', 'industry_name' => 'Ngành gỗ'],
        ['industry_code' => 'METAL', 'industry_name' => 'Ngành kim loại'],
        ['industry_code' => 'CONSTRUCTION', 'industry_name' => 'Công trường xây dựng'],
        ['industry_code' => 'AUTO_REPAIR', 'industry_name' => 'Sửa chữa ô tô'],
        ['industry_code' => 'GARDEN', 'industry_name' => 'Dụng cụ vườn'],
    ];

    /**
     * Thẻ phân loại App PV — người dùng chốt 06/09/2026 CHỈ hai thẻ này. Bộ cũ
     * (Bán chạy / Hàng mới / Khuyến mãi / Công suất lớn / Tiết kiệm nhiên liệu) là
     * nhãn marketing, không phải cách khách phân loại dụng cụ.
     *
     * Cần thêm thẻ thì thêm ở màn PV Admin (SCR-PV-ADM-01); seeder chỉ dựng bộ
     * khởi tạo, không phải nơi giữ danh sách đang chạy.
     */
    private const PV_FILTER_TAGS = [
        ['tag_code' => 'HAND_TOOL', 'tag_name' => 'Dụng cụ cầm tay', 'tag_type' => 'CATEGORY'],
        ['tag_code' => 'POWER_TOOL', 'tag_name' => 'Dụng cụ điện', 'tag_type' => 'CATEGORY'],
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
        // Hai nguồn dưới đây là của danh mục DongCheng thật (539 dụng cụ cầm tay
        // dùng MANUAL, 4 dụng cụ khí nén dùng PNEUMATIC) — phải có NGAY ở bước
        // master gốc, không đợi MasterCatalogCoverageSeeder chạy sau.
        ['power_source_code' => 'MANUAL', 'power_source_name' => 'Cơ tay (không động cơ)', 'description' => 'Dụng cụ cầm tay vận hành bằng sức người, không có động cơ.'],
        ['power_source_code' => 'PNEUMATIC', 'power_source_name' => 'Khí nén', 'description' => 'Dụng cụ chạy bằng khí nén từ máy nén khí.'],
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
        // sort_order lấy theo VỊ TRÍ trong hằng số: thứ tự hai danh sách là thứ tự
        // người dùng chốt, và cả FE lẫn mặt công khai đều sắp theo cột này. Để
        // toàn bộ về 0 như trước thì thứ tự dropdown rơi về "tuỳ DB trả".
        foreach (array_values(self::PV_INDUSTRIES) as $index => $row) {
            $existing = DB::table('pv_industry')->where('industry_code', $row['industry_code'])->first();
            if ($existing !== null) {
                continue;
            }

            DB::table('pv_industry')->insert([
                'industry_id' => (string) Str::uuid(),
                'industry_code' => $row['industry_code'],
                'industry_name' => $row['industry_name'],
                'status' => 'ACTIVE',
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 1,
            ]);
        }

        foreach (array_values(self::PV_FILTER_TAGS) as $index => $row) {
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
                'sort_order' => $index + 1,
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
