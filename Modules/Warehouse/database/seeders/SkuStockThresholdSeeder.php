<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Đặt "Định mức tồn kho" (min_stock / max_stock) cho MỌI SKU đang ACTIVE để:
 *  - 2 cột "Định mức tối thiểu" / "Định mức tối đa" ở màn Danh sách SKU có dữ liệu
 *    (không còn "—").
 *  - Card "Định mức tồn kho" (dashboard-quick: below_min_stock_count /
 *    above_max_stock_count) có số liệu cân đối: ~1/3 SKU dưới định mức, ~1/3 trong
 *    ngưỡng, ~1/3 vượt định mức — tính theo available_qty thực tế của từng SKU.
 *
 * Ràng buộc DB (chk_skus_stock_thresholds): min>=0, max>=1, max>min — công thức
 * dưới đây luôn thoả. Idempotent: chạy lại chỉ tính lại theo tồn hiện tại.
 */
class SkuStockThresholdSeeder extends Seeder
{
    public function run(): void
    {
        // available_qty theo từng SKU = SUM(inventory_balances.available_qty) ở
        // stock_status=AVAILABLE — CÙNG nguồn với dashboard-quick để card khớp số.
        // Chia band theo row_number để phân bố dưới/trong/vượt định mức cân đối.
        // Định mức mang tính nghiệp vụ: đa số SKU có min "sàn" thực tế (>=5) và max
        // cao (>=35) → cột hiển thị chuyên nghiệp; riêng ~14% SKU (rn%7=0) là band
        // "overstock" cố ý đặt max thấp hơn tồn thực để card có "Tồn vượt định mức"
        // khác 0. Ràng buộc DB max>min luôn thoả.
        $affected = DB::update(<<<'SQL'
            WITH avail AS (
              SELECT s.id AS sku_id,
                     COALESCE((
                        SELECT SUM(ib.available_qty) FROM inventory_balances ib
                        WHERE (ib.sku_id = s.id OR ib.product_id = s.id)
                          AND ib.stock_status = 'AVAILABLE'
                     ), 0)::int AS a,
                     row_number() OVER (ORDER BY s.sku_code) AS rn
              FROM skus s
              WHERE s.status = 'ACTIVE'
            )
            UPDATE skus s SET
              min_stock = CASE (av.rn % 3)
                 WHEN 0 THEN GREATEST(8, av.a + 5)                 -- dưới định mức
                 WHEN 1 THEN GREATEST(3, av.a - 2)                 -- trong ngưỡng
                 ELSE GREATEST(2, floor(av.a * 0.4)::int)         -- vượt định mức (min thấp)
              END,
              max_stock = CASE (av.rn % 3)
                 WHEN 0 THEN GREATEST(8, av.a + 5) + 42
                 WHEN 1 THEN GREATEST(GREATEST(3, av.a - 2) + 18, av.a + 22)
                 ELSE GREATEST(GREATEST(2, floor(av.a * 0.4)::int) + 1, av.a - 1)  -- max < tồn khi tồn đủ lớn
              END,
              updated_at = now()
            FROM avail av
            WHERE av.sku_id = s.id
        SQL);

        $this->command?->info("SkuStockThresholdSeeder: đã đặt định mức min/max cho {$affected} SKU (ACTIVE).");

        $below = DB::table('skus as s')
            ->whereNotNull('s.min_stock')
            ->whereRaw('COALESCE((SELECT SUM(ib.available_qty) FROM inventory_balances ib WHERE (ib.sku_id=s.id OR ib.product_id=s.id) AND ib.stock_status=\'AVAILABLE\'),0) < s.min_stock')
            ->count();
        $above = DB::table('skus as s')
            ->whereNotNull('s.max_stock')
            ->whereRaw('COALESCE((SELECT SUM(ib.available_qty) FROM inventory_balances ib WHERE (ib.sku_id=s.id OR ib.product_id=s.id) AND ib.stock_status=\'AVAILABLE\'),0) > s.max_stock')
            ->count();
        $this->command?->info("SkuStockThresholdSeeder: tồn dưới định mức={$below} SKU, tồn vượt định mức={$above} SKU.");
    }
}
