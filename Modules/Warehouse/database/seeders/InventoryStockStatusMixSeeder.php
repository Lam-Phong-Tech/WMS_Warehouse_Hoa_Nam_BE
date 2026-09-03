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

    /**
     * Nhóm A — SKU đang có tồn AVAILABLE, thêm lát cắt phi-AVAILABLE cho donut.
     *
     * @var list<array{0:string,1:string,2:int}> [sku_code, stock_status, qty]
     */
    private const EXISTING_SKU_BUCKETS = [
        ['PUMP-HUS-0003', 'DEFECTIVE', 4],
        ['PUMP-HUS-0003', 'QUARANTINE', 2],
        ['PUMP-HON-0002', 'NON_SELLABLE', 5],
        ['PUMP-KUB-0005', 'DEFECTIVE', 3],
        ['PUMP-HOA-0004', 'QUARANTINE', 3],
        ['PUMP-HOA-0004', 'NON_SELLABLE', 2],
        ['PUMP-YAN-0001', 'DEFECTIVE', 2],
        ['PUMP-YAN-0006', 'QUARANTINE', 4],
        ['SPR-HON-0002', 'NON_SELLABLE', 8],
        ['SPR-YAN-0001', 'DEFECTIVE', 5],
        ['SPR-HUS-0003', 'QUARANTINE', 3],
        ['HN-COMP-SEED-002', 'NON_SELLABLE', 6],
        ['HN-COMP-SEED-002', 'DEFECTIVE', 4],
    ];

    /**
     * Nhóm B — SKU chưa có tồn: cấp tồn matched đủ 2 trạng thái => SKU "khớp" mới.
     *
     * @var list<array{0:string,1:string,2:int}> [sku_code, stock_status, qty]
     */
    private const FRESH_SKU_BUCKETS = [
        ['ENG-HOA-0004', 'AVAILABLE', 18],
        ['ENG-HOA-0004', 'DEFECTIVE', 3],
        ['ENG-HOA-0009', 'AVAILABLE', 14],
        ['ENG-HOA-0009', 'QUARANTINE', 2],
        ['ENG-HOA-0014', 'AVAILABLE', 22],
        ['ENG-HOA-0014', 'NON_SELLABLE', 4],
        ['ENG-HOA-0019', 'AVAILABLE', 9],
        ['ENG-HOA-0019', 'DEFECTIVE', 2],
        ['ENG-HOA-0024', 'AVAILABLE', 16],
        ['ENG-HOA-0024', 'QUARANTINE', 3],
        ['ENG-HOA-0029', 'AVAILABLE', 12],
        ['ENG-HOA-0029', 'NON_SELLABLE', 3],
    ];

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
        foreach ([...self::EXISTING_SKU_BUCKETS, ...self::FRESH_SKU_BUCKETS] as [$skuCode, $status, $qty]) {
            $added += $this->addMatchedBucket($skuCode, $status, (int) $qty) ? 1 : 0;
        }

        $this->command?->info("InventoryStockStatusMixSeeder: đã thêm {$added} lát cắt tồn (matched) qua 4 trạng thái cho báo cáo tồn kho & đối soát.");
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
