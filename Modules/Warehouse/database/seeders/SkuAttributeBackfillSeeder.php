<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Điền các thuộc tính SKU còn trống để KHÔNG cột nào ở màn "Danh sách SKU" /
 * modal chi tiết SKU hiện "Chưa có":
 *  - power_source_id  (Nguồn năng lượng) — trống ở toàn bộ SKU linh kiện cũ.
 *  - package_variant_id (Quy cách đóng gói).
 *  - family_name / variant_label (Dòng máy · biến thể) — trước đây NULL 100%.
 *
 * PHẢI chạy CUỐI pipeline: HoaNamBulkDemoSeeder còn sinh thêm ~500 SKU SAU
 * SkuCoverageSeeder, nên nếu backfill nằm trong seeder đó thì nhóm SKU sinh sau
 * vẫn trống. Chỉ ĐIỀN CHỖ TRỐNG, không ghi đè giá trị đã có.
 *
 * Idempotent: lần chạy sau không còn dòng nào thoả điều kiện WHERE.
 */
class SkuAttributeBackfillSeeder extends Seeder
{
    /** @var array<string,array{0:string,1:string}> category_code => [nguồn năng lượng, tên dòng máy] */
    private const CATEGORY_DEFAULTS = [
        'PUMP' => ['GASOLINE', 'Máy bơm nước'],
        'ENGINE' => ['DIESEL', 'Động cơ nổ'],
        'HARVESTER' => ['DIESEL', 'Máy gặt đập liên hợp'],
        'TRACTOR' => ['DIESEL', 'Máy kéo'],
        'SPRAYER' => ['BATTERY', 'Máy phun thuốc'],
        'GENERATOR' => ['GASOLINE', 'Máy phát điện'],
        'SOIL_TILLER' => ['DIESEL', 'Máy xới đất'],
        'GRASS_CUTTER' => ['GASOLINE', 'Máy cắt cỏ'],
        'ELECTRICAL_TOOLS' => ['BATTERY', 'Máy khoan/bắt vít'],
        'CUTTING_TOOLS' => ['MAINS_DIRECT', 'Máy cắt cầm tay'],
        'HYDRAULIC_TOOLS' => ['MANUAL', 'Kích thuỷ lực'],
        'WELDING_TOOLS' => ['MAINS_DIRECT', 'Máy hàn'],
        'MEASURING_TOOLS' => ['BATTERY', 'Thước/máy đo'],
        'MECHANICAL_TOOLS' => ['MANUAL', 'Bộ dụng cụ cơ khí'],
        'FASTENING_TOOLS' => ['PNEUMATIC', 'Dụng cụ siết bulông'],
        'CLAMPING_TOOLS' => ['MANUAL', 'Kìm/ê-tô kẹp'],
        'WRENCHES' => ['MANUAL', 'Cờ lê'],
        'SOCKETS' => ['MANUAL', 'Đầu tuýp'],
        'GARDEN_HAND_TOOLS' => ['MANUAL', 'Dụng cụ làm vườn'],
        'PAINTING_WALL_TOOLS' => ['MANUAL', 'Dụng cụ sơn tường'],
        'STORAGE_TOOLS' => ['MANUAL', 'Tủ/hộp đựng dụng cụ'],
        'SAFETY_GEAR' => ['MANUAL', 'Trang bị bảo hộ'],
        'SPARE_PART' => ['MANUAL', 'Phụ tùng thay thế'],
        'MANUAL_SPRAYER' => ['MANUAL', 'Bình xịt tay'],
        'CARBURETOR_ENGINE' => ['GASOLINE', 'Động cơ chế hoà khí'],
        'LEGACY_ACCESSORY' => ['MANUAL', 'Phụ kiện đời cũ'],
    ];

    /** @var list<array{0:string,1:string}> [mã biến thể, nhãn biến thể] */
    private const VARIANTS = [
        ['STANDARD', 'Bản tiêu chuẩn'],
        ['PRO', 'Bản chuyên nghiệp'],
        ['COMBO', 'Bản combo phụ kiện'],
        ['BATTERY_KIT', 'Bản kèm 2 pin'],
        ['CASE', 'Bản vali nhựa'],
    ];

    public function run(): void
    {
        $powerIds = DB::table('power_sources')->pluck('id', 'power_source_code');
        $packageIds = DB::table('package_variants')->pluck('id', 'variant_code');
        $fallbackPower = $powerIds['MANUAL'] ?? $powerIds->first();
        $fallbackPackage = $packageIds['BARE'] ?? $packageIds->first();
        if ($fallbackPower === null || $fallbackPackage === null) {
            return;
        }

        $rows = DB::table('skus as s')
            ->leftJoin('product_categories as c', 'c.id', '=', 's.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 's.brand_id')
            ->where(function ($query): void {
                $query->whereNull('s.power_source_id')
                    ->orWhereNull('s.package_variant_id')
                    ->orWhereNull('s.family_name')
                    ->orWhereNull('s.variant_label');
            })
            ->orderBy('s.sku_code')
            ->get([
                's.id', 's.sku_type', 's.power_source_id', 's.package_variant_id',
                's.family_name', 's.variant_label', 'c.category_code', 'b.brand_name',
            ]);

        $touched = 0;
        foreach ($rows as $i => $row) {
            $defaults = self::CATEGORY_DEFAULTS[(string) $row->category_code] ?? ['MANUAL', 'Hàng hoá Hoa Nam'];
            // Linh kiện không gắn nguồn năng lượng của máy mẹ — dùng "Cơ tay".
            $powerCode = $row->sku_type === 'COMPONENT' ? 'MANUAL' : $defaults[0];
            [$variantCode, $variantLabel] = self::VARIANTS[$i % count(self::VARIANTS)];

            DB::table('skus')->where('id', $row->id)->update([
                'power_source_id' => $row->power_source_id ?? ($powerIds[$powerCode] ?? $fallbackPower),
                'package_variant_id' => $row->package_variant_id ?? $fallbackPackage,
                'family_name' => $row->family_name ?? ($defaults[1].' '.($row->brand_name ?? 'Hoa Nam')),
                'variant_label' => $row->variant_label ?? ($variantLabel.' ('.$variantCode.')'),
                'updated_at' => now(),
            ]);
            $touched++;
        }

        $this->command?->info("SkuAttributeBackfillSeeder: đã điền thuộc tính còn trống cho {$touched} SKU.");
    }
}
