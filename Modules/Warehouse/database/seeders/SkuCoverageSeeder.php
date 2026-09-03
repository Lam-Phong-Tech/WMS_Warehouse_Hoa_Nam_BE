<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Sku;

/**
 * Phủ kín màn "Danh sách SKU" (MST-01) và chiều SKU của mọi báo cáo.
 *
 * Sau bộ seeder cũ, 514 SKU đều ACTIVE, chỉ trải 5/26 nhóm hàng và 5/12 hãng —
 * nghĩa là bộ lọc "Trạng thái = Ngừng sử dụng" trả rỗng, 21 nhóm hàng và 7 hãng
 * không có SKU nào, và gần như 100% SKU theo dõi serial (chỉ 7 SKU theo số
 * lượng) nên nhánh tồn theo số lượng gần như không có dữ liệu.
 *
 * Seeder sinh lưới SKU đầy đủ cho từng nhóm hàng × hãng, trải đều:
 *  - sku_type:      PRODUCT / COMPONENT
 *  - tracking_mode: SERIAL / QUANTITY (is_serial_tracked true/false)
 *  - status:        ACTIVE / INACTIVE
 * và luôn điền ĐỦ mọi cột hiển thị (hãng, nhóm hàng, model, nguồn năng lượng,
 * quy cách đóng gói, đơn vị, phiên bản, dòng máy/biến thể, định mức min/max).
 *
 * INACTIVE ở đây gắn với hãng/nhóm hàng đã ngừng kinh doanh (xem
 * MasterCatalogCoverageSeeder) nên dữ liệu vẫn đúng nghiệp vụ, không phải
 * "tắt bừa" một SKU đang bán.
 *
 * Idempotent theo sku_code. Chạy SAU MasterCatalogCoverageSeeder (cần hãng/nhóm
 * hàng/model mới) và TRƯỚC các seeder chứng từ (chứng từ tham chiếu SKU ở đây).
 */
class SkuCoverageSeeder extends Seeder
{
    /**
     * Bộ khung sản phẩm theo nhóm hàng: [tiền tố mã, tên dòng máy, đơn vị,
     * nguồn năng lượng ưu tiên, có bảo hành].
     *
     * @var array<string,array{0:string,1:string,2:string,3:string,4:bool}>
     */
    private const CATEGORY_BLUEPRINT = [
        'PUMP' => ['PUMP', 'Máy bơm nước', 'cái', 'GASOLINE', true],
        'ENGINE' => ['ENG', 'Động cơ nổ', 'cái', 'DIESEL', true],
        'HARVESTER' => ['HARV', 'Máy gặt đập liên hợp', 'cái', 'DIESEL', true],
        'TRACTOR' => ['TRAC', 'Máy kéo', 'cái', 'DIESEL', true],
        'SPRAYER' => ['SPRY', 'Máy phun thuốc', 'cái', 'BATTERY', true],
        'GENERATOR' => ['GEN', 'Máy phát điện', 'cái', 'GASOLINE', true],
        'SOIL_TILLER' => ['TILL', 'Máy xới đất', 'cái', 'DIESEL', true],
        'GRASS_CUTTER' => ['GRAS', 'Máy cắt cỏ', 'cái', 'GASOLINE', true],
        'ELECTRICAL_TOOLS' => ['ELEC', 'Máy khoan/bắt vít', 'cái', 'BATTERY', true],
        'CUTTING_TOOLS' => ['CUTT', 'Máy cắt cầm tay', 'cái', 'MAINS_DIRECT', true],
        'HYDRAULIC_TOOLS' => ['HYDR', 'Kích thuỷ lực', 'cái', 'MANUAL', true],
        'WELDING_TOOLS' => ['WELD', 'Máy hàn', 'cái', 'MAINS_DIRECT', true],
        'MEASURING_TOOLS' => ['MEAS', 'Thước/máy đo', 'cái', 'BATTERY', false],
        'MECHANICAL_TOOLS' => ['MECH', 'Bộ dụng cụ cơ khí', 'bộ', 'MANUAL', false],
        'FASTENING_TOOLS' => ['FAST', 'Dụng cụ siết bulông', 'cái', 'PNEUMATIC', false],
        'CLAMPING_TOOLS' => ['CLMP', 'Kìm/ê-tô kẹp', 'cái', 'MANUAL', false],
        'WRENCHES' => ['WREN', 'Cờ lê', 'bộ', 'MANUAL', false],
        'SOCKETS' => ['SOCK', 'Đầu tuýp', 'bộ', 'MANUAL', false],
        'GARDEN_HAND_TOOLS' => ['GARD', 'Dụng cụ làm vườn', 'cái', 'MANUAL', false],
        'PAINTING_WALL_TOOLS' => ['PANT', 'Dụng cụ sơn tường', 'cái', 'MANUAL', false],
        'STORAGE_TOOLS' => ['STOR', 'Tủ/hộp đựng dụng cụ', 'cái', 'MANUAL', false],
        'SAFETY_GEAR' => ['SAFE', 'Trang bị bảo hộ', 'bộ', 'MANUAL', false],
        'SPARE_PART' => ['SPR', 'Phụ tùng thay thế', 'cái', 'MANUAL', true],
        // Nhóm hàng đã ngừng kinh doanh → chỉ sinh SKU INACTIVE.
        'MANUAL_SPRAYER' => ['MSPR', 'Bình xịt tay', 'cái', 'MANUAL', false],
        'CARBURETOR_ENGINE' => ['CARB', 'Động cơ chế hoà khí', 'cái', 'GASOLINE', true],
        'LEGACY_ACCESSORY' => ['LGCY', 'Phụ kiện đời cũ', 'cái', 'MANUAL', false],
    ];

    /** Nhóm hàng chỉ dùng cho SKU COMPONENT (linh kiện thay thế). */
    private const COMPONENT_CATEGORIES = ['SPARE_PART', 'LEGACY_ACCESSORY'];

    /** Nhóm hàng đã ngừng kinh doanh → SKU sinh ra ở trạng thái INACTIVE. */
    private const RETIRED_CATEGORIES = ['MANUAL_SPRAYER', 'CARBURETOR_ENGINE', 'LEGACY_ACCESSORY'];

    /** Hãng đã ngừng phân phối → SKU sinh ra ở trạng thái INACTIVE. */
    private const RETIRED_BRANDS = ['ROBIN', 'SUMO'];

    private const VARIANTS = [
        ['STANDARD', 'Bản tiêu chuẩn', 'BARE'],
        ['PRO', 'Bản chuyên nghiệp', 'KIT'],
        ['COMBO', 'Bản combo phụ kiện', 'COMBO'],
        ['BATTERY_KIT', 'Bản kèm 2 pin', 'DUAL_BATTERY'],
        ['CASE', 'Bản vali nhựa', 'PRO_CASE'],
    ];

    /** Số hãng gắn cho mỗi nhóm hàng (cửa sổ trượt qua toàn bộ danh sách hãng). */
    private const BRANDS_PER_CATEGORY = 4;

    /** @var array<string,array<string,string>> bảng mã → id theo từng bảng danh mục */
    private array $refs = [];

    public function run(): void
    {
        $this->loadRefs();

        $brands = DB::table('brands')->orderBy('sort_order')->orderBy('brand_code')
            ->get(['brand_code', 'brand_name', 'status']);
        if ($brands->isEmpty()) {
            $this->command?->warn('SkuCoverageSeeder: chưa có hãng nào — chạy WarehouseDatabaseSeeder trước.');

            return;
        }

        $created = 0;
        $index = 0;
        $categoryIndex = 0;
        foreach (self::CATEGORY_BLUEPRINT as $categoryCode => [$prefix, $familyName, $unit, $powerCode, $warranty]) {
            if (! isset($this->refs['product_categories'][$categoryCode])) {
                continue;
            }

            // Mỗi nhóm hàng chỉ gắn BRANDS_PER_CATEGORY hãng, cửa sổ trượt theo
            // thứ tự nhóm hàng: đủ để mọi hãng đều có SKU (bộ lọc "Hãng" không
            // giá trị nào rỗng) mà không thổi danh mục lên gấp ba lần số hiện vật.
            foreach ($this->brandWindow($brands, $categoryIndex++) as $brand) {
                $brandCode = (string) $brand->brand_code;
                $isComponentCategory = in_array($categoryCode, self::COMPONENT_CATEGORIES, true);
                $retired = in_array($categoryCode, self::RETIRED_CATEGORIES, true)
                    || in_array($brandCode, self::RETIRED_BRANDS, true)
                    || (string) $brand->status === 'INACTIVE';

                // 3 biến thể mỗi (nhóm hàng × hãng): PRODUCT-serial, PRODUCT theo
                // số lượng, và một biến thể COMPONENT. Biến thể COMPONENT luân
                // phiên serial / số lượng theo nhóm hàng để CẢ HAI cách theo dõi
                // đều trải rộng trên nhiều nhóm hàng (pool linh kiện serial của
                // seeder chứng từ lấy theo từng nhóm hàng). Nhóm phụ tùng chỉ sinh
                // COMPONENT — đúng bản chất hàng hoá.
                $componentSerial = $categoryIndex % 2 === 1;
                $shapes = $isComponentCategory
                    ? [['COMPONENT', true], ['COMPONENT', false]]
                    : [['PRODUCT', true], ['PRODUCT', false], ['COMPONENT', $componentSerial]];

                foreach ($shapes as $shapeIndex => [$skuType, $serialTracked]) {
                    $index++;
                    [$variantCode, $variantLabel, $packageCode] = self::VARIANTS[$index % count(self::VARIANTS)];
                    $code = sprintf('%s-%s-%s%02d', $prefix, substr($brandCode, 0, 3), $serialTracked ? 'S' : 'Q', $shapeIndex + 1);

                    if (Sku::query()->where('sku_code', $code)->exists()) {
                        continue;
                    }

                    $name = $this->skuName($familyName, (string) $brand->brand_name, $skuType, $variantLabel, $index);
                    // INACTIVE bám hãng/nhóm hàng đã ngừng kinh doanh; ngoài ra
                    // cứ 7 SKU có 1 SKU ngừng bán (vòng đời danh mục bình thường).
                    $status = ($retired || $index % 7 === 0) ? 'INACTIVE' : 'ACTIVE';
                    [$minStock, $maxStock] = $this->thresholds($skuType, $serialTracked, $index);

                    Sku::query()->create([
                        'sku_code' => $code,
                        'sku_name' => mb_substr($name, 0, 200),
                        'sku_type' => $skuType,
                        'unit' => $skuType === 'COMPONENT' ? 'cái' : $unit,
                        'is_serial_tracked' => $serialTracked,
                        'brand_id' => $this->refs['brands'][$brandCode] ?? null,
                        'category_id' => $this->refs['product_categories'][$categoryCode] ?? null,
                        'model_id' => $this->modelForBrand($brandCode),
                        'power_source_id' => $this->refs['power_sources'][$skuType === 'COMPONENT' ? 'MANUAL' : $powerCode]
                            ?? $this->refs['power_sources']['MANUAL'] ?? null,
                        'package_variant_id' => $this->refs['package_variants'][$packageCode]
                            ?? $this->refs['package_variants']['BARE'] ?? null,
                        'family_name' => $familyName.' '.$brand->brand_name,
                        'variant_label' => $variantLabel.' ('.$variantCode.')',
                        'version_code' => 'V'.(1 + ($index % 3)),
                        'warranty_applicable' => $warranty && $skuType === 'PRODUCT',
                        'published_to_app' => $status === 'ACTIVE' && $index % 4 !== 0,
                        'status' => $status,
                        'min_stock' => $minStock,
                        'max_stock' => $maxStock,
                    ]);
                    $created++;
                }
            }
        }

        $this->command?->info(
            "SkuCoverageSeeder: đã tạo {$created} SKU phủ đủ nhóm hàng × hãng × loại × cách theo dõi × trạng thái."
        );
        // Việc điền các thuộc tính còn trống (nguồn năng lượng / quy cách / dòng
        // máy) nằm ở SkuAttributeBackfillSeeder và chạy CUỐI pipeline — vì
        // HoaNamBulkDemoSeeder còn sinh thêm ~500 SKU SAU seeder này.
    }

    private function skuName(string $familyName, string $brandName, string $skuType, string $variantLabel, int $index): string
    {
        $series = chr(65 + ($index % 6)).sprintf('%02d', ($index % 80) + 12);

        if ($skuType === 'COMPONENT') {
            $parts = [
                'Bộ lọc dầu', 'Bộ lọc gió', 'Dây curoa', 'Bơm nhiên liệu', 'Gioăng nắp máy',
                'Bạc đạn trục cơ', 'Piston + xéc-măng', 'Bugi sấy', 'Kim phun', 'Van hằng nhiệt',
                'Lưỡi cắt thay thế', 'Cụm công tắc nguồn',
            ];

            return $parts[$index % count($parts)].' cho '.$familyName.' '.$brandName.' '.$series;
        }

        $power = [3, 5, 8, 10, 12, 15, 18, 24][$index % 8];

        return $familyName.' '.$brandName.' '.$series.' — '.$power.'HP · '.$variantLabel;
    }

    /** @return array{0:float,1:float} [min_stock, max_stock] — max luôn > min (CHECK chk_skus_stock_thresholds). */
    private function thresholds(string $skuType, bool $serialTracked, int $index): array
    {
        $min = $skuType === 'COMPONENT' ? 10 + ($index % 20) : 3 + ($index % 8);
        $max = $min + ($serialTracked ? 25 + ($index % 30) : 60 + ($index % 90));

        return [(float) $min, (float) $max];
    }

    private function modelForBrand(string $brandCode): ?string
    {
        $preferred = [
            'KUBOTA' => 'MDL-KB-DI', 'YANMAR' => 'MDL-YM-TF', 'HONDA' => 'MDL-HD-GX',
            'HUSQVARNA' => 'MDL-HQ-XP', 'HOANAM' => 'MDL-HN-PRO', 'MAKITA' => 'MDL-MK-LXT',
            'STIHL' => 'MDL-ST-MS', 'MITSUBISHI' => 'MDL-MB-GM', 'TOTAL' => 'MDL-TT-HD',
            'DONGCHENG' => 'MDL-DC-PRO', 'ROBIN' => 'MDL-RB-EY', 'SUMO' => 'MDL-SM-QB',
        ][$brandCode] ?? 'MDL-HN-PRO';

        return $this->refs['product_models'][$preferred] ?? null;
    }

    /**
     * Cửa sổ trượt BRANDS_PER_CATEGORY hãng, dịch 3 vị trí sau mỗi nhóm hàng —
     * mọi hãng đều lọt vào nhiều nhóm hàng khác nhau (kể cả hãng đã ngừng phân
     * phối, để bộ lọc theo hãng không có giá trị nào trả rỗng).
     *
     * @param  Collection<int,object>  $brands
     * @return list<object>
     */
    private function brandWindow($brands, int $categoryIndex): array
    {
        $all = $brands->values()->all();
        $total = count($all);
        $take = min(self::BRANDS_PER_CATEGORY, $total);
        $window = [];
        for ($i = 0; $i < $take; $i++) {
            $window[] = $all[(($categoryIndex * 3) + $i) % $total];
        }

        return $window;
    }

    private function loadRefs(): void
    {
        $this->refs = [
            'brands' => DB::table('brands')->pluck('id', 'brand_code')->map(fn ($v) => (string) $v)->all(),
            'product_categories' => DB::table('product_categories')->pluck('id', 'category_code')->map(fn ($v) => (string) $v)->all(),
            'product_models' => DB::table('product_models')->pluck('id', 'model_code')->map(fn ($v) => (string) $v)->all(),
            'power_sources' => DB::table('power_sources')->pluck('id', 'power_source_code')->map(fn ($v) => (string) $v)->all(),
            'package_variants' => DB::table('package_variants')->pluck('id', 'variant_code')->map(fn ($v) => (string) $v)->all(),
        ];
    }
}
