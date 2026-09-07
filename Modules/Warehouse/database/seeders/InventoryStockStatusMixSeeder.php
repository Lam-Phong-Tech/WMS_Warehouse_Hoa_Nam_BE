<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Warehouse;

/**
 * Dữ liệu mẫu cho "Báo cáo · Tồn kho & đối soát" (RPT-INV-01,
 * OperationalReportController::inventoryBalance/inventoryReconciliation) — yêu cầu
 * người dùng: hai widget "Cơ cấu tồn kho hiện tại" và "Tỷ lệ đối soát" phải "sinh
 * động": donut trải đủ 4 trạng thái tồn (AVAILABLE/NON_SELLABLE/DEFECTIVE/QUARANTINE)
 * thay vì gần như 100% AVAILABLE, và bảng đối soát có thêm SKU khớp.
 *
 * NGUYÊN TẮC BẤT BIẾN (DEC-INV-001 §7.5): report đối soát so
 *   inventory_balances.on_hand_qty  ==  SUM(stock_movements.qty_delta)
 * theo từng dimension (warehouse_id, product_id, stock_status). Seeder LUÔN tạo
 * cặp movement + balance CÙNG dimension, CÙNG số lượng → mọi dòng thêm vào đều
 * KHỚP (không đẻ MISMATCH giả, không đụng guard/incident đối soát). Các dòng lệch
 * sẵn có (nếu có) được giữ nguyên để bảng vẫn phản ánh thực trạng.
 *
 * Hai nhóm bổ sung, tất cả stock-neutral về mặt đối soát:
 *  A. Thêm tồn ở trạng thái phi-AVAILABLE cho SKU ĐANG có tồn — cho donut đủ lát cắt
 *     (không sửa dòng AVAILABLE hiện có nên không ảnh hưởng khớp/lệch của SKU đó).
 *  B. Cấp tồn matched (AVAILABLE + 1 trạng thái phi-AVAILABLE) cho vài SKU CHƯA có
 *     tồn — xuất hiện thành SKU "khớp" mới, tăng tổng SKU của bảng đối soát.
 *
 * movement_type='OPENING' (tồn đầu kỳ mang trạng thái) — kiểu duy nhất hợp lệ cho
 * tồn không sinh từ chứng từ nhập/xuất (chk_stock_movement_type_current). Idempotent
 * theo business_key 'SEED-STKMIX:<status>:<sku_id>'. Chỉ chạy pgsql.
 */
class InventoryStockStatusMixSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const BK_PREFIX = 'SEED-STKMIX';

    /** Trạng thái tồn phi-AVAILABLE cần phủ để donut đủ 4 lát cắt. */
    private const NON_AVAILABLE_STATUSES = ['DEFECTIVE', 'QUARANTINE', 'NON_SELLABLE'];

    private string $actorId;

    private string $warehouseId;

    public function run(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first()
            ?? Warehouse::query()->orderBy('created_at')->first();
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id')
            ?? DB::table('app_user')->orderBy('created_at')->value('user_id');
        if ($warehouse === null || $actorId === null) {
            $this->command?->warn('InventoryStockStatusMixSeeder: thiếu kho/user — chạy Warehouse*/Auth*Seeder trước.');

            return;
        }
        $this->actorId = (string) $actorId;
        $this->warehouseId = (string) $warehouse->id;

        $added = 0;
        foreach ($this->buckets() as [$skuCode, $status, $qty]) {
            $added += $this->addMatchedBucket($skuCode, $status, (int) $qty) ? 1 : 0;
        }

        $this->command?->info("InventoryStockStatusMixSeeder: đã thêm {$added} lát cắt tồn (matched) qua 4 trạng thái cho báo cáo tồn kho & đối soát.");
    }

    /**
     * Danh sách lát cắt cần thêm, CHỌN ĐỘNG từ danh mục thật đang có trong CSDL.
     *
     * Bản cũ ghi cứng 25 mã do seeder demo bịa ra (`PUMP-HUS-0003`,
     * `ENG-HOA-0004`...). Sau khi chuyển sang danh mục DongCheng thật, những mã đó
     * không còn tồn tại và seeder sẽ lặng lẽ không thêm gì — donut tồn kho lại
     * gần 100% AVAILABLE. Nay:
     *  - Nhóm A: 12 SKU ĐANG có tồn AVAILABLE nhiều nhất, mỗi SKU 1-2 lát cắt
     *    phi-AVAILABLE (không sửa dòng AVAILABLE nên không đẻ lệch đối soát).
     *  - Nhóm B: 6 SKU ACTIVE CHƯA có tồn, cấp cặp AVAILABLE + 1 trạng thái phụ
     *    → thành SKU "khớp" mới của bảng đối soát.
     * Số lượng suy từ vị trí trong danh sách nên chạy lại vẫn ra đúng bộ dữ liệu.
     *
     * @return list<array{0:string,1:string,2:int}> [sku_code, stock_status, qty]
     */
    private function buckets(): array
    {
        $withStock = DB::table('inventory_balances as ib')
            ->join('skus as s', 's.id', '=', 'ib.sku_id')
            ->where('ib.warehouse_id', $this->warehouseId)
            ->where('ib.on_hand_qty', '>', 0)
            ->groupBy('s.sku_code')
            ->orderByRaw('SUM(ib.on_hand_qty) DESC')
            ->limit(12)
            ->pluck('s.sku_code')
            ->all();

        $withoutStock = DB::table('skus as s')
            ->where('s.status', 'ACTIVE')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('inventory_balances as ib')
                ->whereColumn('ib.sku_id', 's.id'))
            ->orderBy('s.sku_code')
            ->limit(6)
            ->pluck('s.sku_code')
            ->all();

        $buckets = [];
        foreach (array_values($withStock) as $i => $code) {
            $buckets[] = [(string) $code, self::NON_AVAILABLE_STATUSES[$i % 3], 2 + ($i % 7)];
            if ($i % 3 === 0) {
                $buckets[] = [(string) $code, self::NON_AVAILABLE_STATUSES[($i + 1) % 3], 2 + ($i % 4)];
            }
        }
        foreach (array_values($withoutStock) as $i => $code) {
            $buckets[] = [(string) $code, 'AVAILABLE', 9 + ($i * 3)];
            $buckets[] = [(string) $code, self::NON_AVAILABLE_STATUSES[$i % 3], 2 + ($i % 4)];
        }

        return $buckets;
    }

    /**
     * Tạo cặp movement (OPENING, +qty, mang stock_status) + balance cùng dimension.
     * Trả về false nếu bỏ qua (SKU không tồn tại / đã seed trước đó).
     */
    private function addMatchedBucket(string $skuCode, string $status, int $qty): bool
    {
        $skuId = DB::table('skus')->where('sku_code', $skuCode)->value('id');
        if ($skuId === null) {
            return false;
        }
        $skuId = (string) $skuId;

        $businessKey = self::BK_PREFIX.':'.$status.':'.$skuId;
        if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
            return false;
        }

        $now = now();

        // Ledger: OPENING +qty ở đúng dimension (kho, sku, trạng thái).
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'movement_no' => 'SM-STKMIX-'.strtoupper(Str::substr((string) Str::uuid(), 0, 12)),
            'business_key' => $businessKey,
            'movement_type' => 'OPENING',
            'warehouse_id' => $this->warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => $status,
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => $qty,
            'quantity' => $qty,
            'source_type' => 'SEEDER',
            'created_by' => $this->actorId,
            'request_id' => 'seed-stkmix-'.strtolower($status),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        // Projection: balance cùng dimension, cùng số lượng => variance = 0 (MATCH).
        $existingBalanceId = DB::table('inventory_balances')
            ->where('warehouse_id', $this->warehouseId)
            ->where('sku_id', $skuId)
            ->where('stock_status', $status)
            ->value('id');

        if ($existingBalanceId === null) {
            DB::table('inventory_balances')->insert([
                'id' => (string) Str::uuid(),
                'warehouse_id' => $this->warehouseId,
                'product_id' => $skuId,
                'sku_id' => $skuId,
                'stock_status' => $status,
                'on_hand_qty' => $qty,
                'available_qty' => $status === 'AVAILABLE' ? $qty : 0,
                'reserved_qty' => 0,
                'quantity' => $qty,
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            // Bucket đã tồn tại (vd trạng thái AVAILABLE của SKU nhóm A) — cộng dồn
            // để vẫn khớp với ledger vừa thêm.
            DB::table('inventory_balances')->where('id', $existingBalanceId)->update([
                'on_hand_qty' => DB::raw('on_hand_qty + '.$qty),
                'available_qty' => DB::raw('available_qty + '.($status === 'AVAILABLE' ? $qty : 0)),
                'quantity' => DB::raw('quantity + '.$qty),
                'updated_at' => $now,
            ]);
        }

        return true;
    }
}
