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
    public function run(): void
    {
        $powerIds = DB::table('power_sources')->pluck('id', 'power_source_code');
        $packageIds = DB::table('package_variants')->pluck('id', 'package_code');
        $fallbackPower = $powerIds['MANUAL'] ?? $powerIds->first();
        $fallbackPackage = $packageIds['BARE'] ?? $packageIds->first();
        if ($fallbackPower === null || $fallbackPackage === null) {
            return;
        }

        $rows = DB::table('skus as s')
            ->leftJoin('product_categories as c', 'c.id', '=', 's.category_id')
            ->where(function ($query): void {
                $query->whereNull('s.power_source_id')
                    ->orWhereNull('s.package_variant_id')
                    ->orWhereNull('s.family_name')
                    ->orWhereNull('s.variant_label');
            })
            ->orderBy('s.sku_code')
            ->get([
                's.id', 's.sku_code', 's.sku_name', 's.sku_type', 's.power_source_id',
                's.package_variant_id', 's.family_name', 's.variant_label', 'c.category_name',
            ]);

        $touched = 0;
        foreach ($rows as $row) {
            DB::table('skus')->where('id', $row->id)->update([
                // Không suy diễn nguồn năng lượng theo nhóm hàng nữa: catalog thật đã
                // ghi rõ từng mã, dòng nào còn trống là hàng cơ khí thuần → "Cơ tay".
                'power_source_id' => $row->power_source_id ?? $fallbackPower,
                'package_variant_id' => $row->package_variant_id ?? $fallbackPackage,
                // Dòng máy/biến thể lấy từ dữ liệu THẬT của chính mã hàng (tên nhóm
                // hàng, mã hàng), không gắn nhãn bịa kiểu "Bản tiêu chuẩn (STANDARD)".
                'family_name' => $row->family_name ?? ($row->category_name ?? $row->sku_name),
                'variant_label' => $row->variant_label ?? $row->sku_code,
                'updated_at' => now(),
            ]);
            $touched++;
        }

        $this->command?->info("SkuAttributeBackfillSeeder: đã điền thuộc tính còn trống cho {$touched} SKU.");
    }
}
