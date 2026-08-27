<?php

namespace Modules\Warehouse\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-231 — truy vấn ledger dùng chung cho đối soát tồn kho (§7.5 Reconciliation Controls).
 *
 * DEC-INV-001: `stock_movements` là source of truth, `inventory_balances` chỉ là projection.
 * Mọi phép đối soát Ledger↔Balance đều quy về cùng một aggregate theo dimension
 * (warehouse_id, product_id, stock_status) nên aggregate đó phải có duy nhất một
 * implementation — nếu report và lệnh cron mỗi bên tự viết một biến thể thì hai bên có thể
 * kết luận khác nhau trên cùng dữ liệu.
 *
 * Class này CHỈ ĐỌC (REQ-SRS-037 / BR-NOSTK-01..03): không phương thức nào ghi
 * stock_movements, inventory_balances hay bất kỳ bảng tồn kho nào. Sửa sai đi theo Reversal
 * append-only (BR-REV-01), phát hiện lệch chỉ tạo reconciliation_incident.
 *
 * Hình dạng query (aggregate-then-join) là điểm tối ưu của WWHN-231:
 * gom SUM(qty_delta) trên riêng `stock_movements` — chỉ chạm 3 cột dimension + 2 cột đo —
 * rồi mới join master data lên tập đã gom. Bản cũ join warehouses/products/product_categories
 * TRƯỚC khi GROUP BY và phải gom theo 8 cột (có 4 cột text), khiến planner không dùng được
 * index dimension và phải hash-aggregate trên toàn bộ ledger. Xem
 * docs/evidence/WWHN-231/BENCHMARK.md.
 */
class LedgerReconciliationService
{
    /** Dimension đối soát theo SRS §7.5. */
    public const DIMENSION = ['warehouse_id', 'product_id', 'stock_status'];

    /**
     * Aggregate ledger thuần, chưa join master data.
     *
     * @param  array{
     *     warehouse_ids?: list<string>|null,
     *     warehouse_id?: string|null,
     *     product_id?: string|null,
     *     stock_status?: string|null,
     *     occurred_to?: string|null,
     *     product_id_in?: \Closure|null
     * }  $filters
     */
    public function ledgerAggregateQuery(array $filters = []): Builder
    {
        $query = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_qty')
            ->selectRaw('MAX(occurred_at) AS movement_updated_at')
            ->groupBy('warehouse_id', 'product_id', 'stock_status');

        // Data-scope cắt trước mọi filter do người dùng gửi lên (DEV254-P1-03).
        if (! empty($filters['warehouse_ids'])) {
            $query->whereIn('warehouse_id', $filters['warehouse_ids']);
        } elseif (isset($filters['warehouse_ids']) && $filters['warehouse_ids'] === []) {
            // Fail-closed: scope rỗng nghĩa là không được xem kho nào.
            $query->whereRaw('1 = 0');
        }

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['occurred_to'])) {
            $query->where('occurred_at', '<=', $filters['occurred_to']);
        }

        // Filter theo thuộc tính SKU (category/keyword) được đẩy thành subquery trên
        // product_id thay vì join products trước GROUP BY, để aggregate vẫn chỉ chạm ledger.
        if (isset($filters['product_id_in']) && $filters['product_id_in'] instanceof \Closure) {
            $query->whereIn('product_id', $filters['product_id_in']);
        }

        return $query;
    }

    /**
     * Aggregate ledger kèm master data (mã kho/SKU/nhóm hàng) cho báo cáo.
     *
     * Master data join SAU aggregate: quan hệ id → code/name là hàm, nên kết quả bằng
     * đúng bản join-trước-gom nhưng chỉ join trên số dòng đã gom.
     */
    public function ledgerAggregateWithMasterQuery(array $filters = []): Builder
    {
        $aggregate = $this->ledgerAggregateQuery($filters);

        return DB::query()
            ->fromSub($aggregate, 'agg')
            ->leftJoin('warehouses as w', 'w.id', '=', 'agg.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'agg.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->select(
                'agg.warehouse_id',
                'agg.product_id',
                'agg.stock_status',
                'agg.ledger_qty',
                'agg.movement_updated_at',
                'w.code as warehouse_code',
                'p.code as product_code',
                'p.name as product_name',
                'p.category_id',
                'pc.category_name',
                'p.sku_type',
            );
    }

    /**
     * Tổng ledger theo dimension, key = "warehouse|product|status".
     *
     * @return Collection<string, object>
     */
    public function ledgerTotals(array $filters = []): Collection
    {
        return $this->ledgerAggregateQuery($filters)
            ->get()
            ->keyBy(fn ($row): string => self::dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status));
    }

    /**
     * Projection tồn theo cùng dimension để đối soát.
     *
     * @return Collection<string, object>
     */
    public function balanceTotals(array $filters = []): Collection
    {
        $query = DB::table('inventory_balances')
            ->select('warehouse_id', 'product_id', 'stock_status', 'on_hand_qty', 'available_qty', 'reserved_qty', 'updated_at');

        if (! empty($filters['warehouse_ids'])) {
            $query->whereIn('warehouse_id', $filters['warehouse_ids']);
        } elseif (isset($filters['warehouse_ids']) && $filters['warehouse_ids'] === []) {
            $query->whereRaw('1 = 0');
        }

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query->get()
            ->keyBy(fn ($row): string => self::dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status));
    }

    /**
     * Đối soát Ledger↔Balance theo §7.5, trả về đúng các dimension lệch.
     *
     * Read-only: phát hiện lệch KHÔNG kéo theo bất kỳ write nào lên tồn kho. Ai muốn lưu
     * vết thì ghi reconciliation_incident (bảng sự cố), không đụng ledger/balance.
     *
     * @return list<array{warehouse_id:string,product_id:string,stock_status:string,on_hand_qty:float,ledger_qty:float,variance:float,divergence_type:string}>
     */
    public function divergences(array $filters = []): array
    {
        $balances = $this->balanceTotals($filters);
        $ledger = $this->ledgerTotals($filters);

        $rows = [];
        foreach ($balances->keys()->merge($ledger->keys())->unique()->values() as $key) {
            [$warehouseId, $productId, $stockStatus] = array_pad(explode('|', (string) $key, 3), 3, '');
            $balance = $balances->get($key);
            $movement = $ledger->get($key);
            $onHand = (float) ($balance->on_hand_qty ?? 0);
            $ledgerQty = (float) ($movement->ledger_qty ?? 0);
            $variance = bcsub((string) $onHand, (string) $ledgerQty, 3);

            if (bccomp($variance, '0', 3) === 0) {
                continue;
            }

            $rows[] = [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'stock_status' => $stockStatus,
                'on_hand_qty' => $onHand,
                'ledger_qty' => $ledgerQty,
                'variance' => (float) $variance,
                'divergence_type' => match (true) {
                    $balance === null => 'BALANCE_MISSING_FOR_LEDGER',
                    $movement === null => 'LEDGER_MISSING_FOR_BALANCE',
                    default => 'QUANTITY_MISMATCH',
                },
            ];
        }

        return $rows;
    }

    public static function dimensionKey(mixed $warehouseId, mixed $productId, mixed $stockStatus): string
    {
        return ($warehouseId ?? '').'|'.($productId ?? '').'|'.($stockStatus ?? 'AVAILABLE');
    }
}
