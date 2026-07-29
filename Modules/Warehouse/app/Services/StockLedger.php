<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Str;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\InTransitLedger;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;

/**
 * Engine cập nhật tồn (port từ StockLedger của ERP-Backend M2) — mọi thay đổi tồn PHẢI đi
 * qua đây. bcmath để tránh sai số dấu phẩy động, khoá optimistic qua `inventory_balances.version`.
 * Caller (StockDocumentService) chịu trách nhiệm mở DB::transaction() trước khi gọi.
 *
 * Khác bản gốc: bỏ lot_no/expiry_date (chưa có trong base — 1 dòng balance/kho+sản phẩm),
 * bỏ reserveIssue/releaseIssueReservation (ERP gốc đã comment "Phương án A: không giữ tồn
 * khi duyệt xuất" — dead code, không còn được gọi ở đâu trong StockDocumentService gốc).
 */
class StockLedger
{
    private const SCALE = 3;

    public function __construct(private readonly SafeStockAlertService $safeStockAlerts) {}

    public function inbound(StockDocument $doc, StockDocumentLine $line, string $userId, string|float|null $qty = null, ?string $businessKeySuffix = null): ?StockMovement
    {
        return $this->mutate($doc->dst_warehouse_id, $line->product_id, $qty ?? $line->qty_actual, StockMovement::RECEIPT_IN, $doc, $line, $userId, '+', $businessKeySuffix);
    }

    public function outbound(StockDocument $doc, StockDocumentLine $line, string $userId, string|float|null $qty = null, ?string $businessKeySuffix = null): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $qty ?? $line->qty_actual, StockMovement::ISSUE_OUT, $doc, $line, $userId, '-', $businessKeySuffix);
    }

    /** Xuất điều chuyển: kho nguồn -qty_planned. */
    public function transferOut(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $line->qty_planned, StockMovement::TRANSFER_OUT, $doc, $line, $userId, '-');
    }

    /** Nhận điều chuyển: kho đích +qty (có thể nhận từng phần). */
    public function transferIn(StockDocument $doc, StockDocumentLine $line, string $qty, string $userId, ?string $businessKeySuffix = null): ?StockMovement
    {
        return $this->mutate($doc->dst_warehouse_id, $line->product_id, $qty, StockMovement::TRANSFER_IN, $doc, $line, $userId, '+', $businessKeySuffix);
    }

    /** Trả lại điều chuyển: kho nguồn +qty (hàng trả về sau khi đã dispatch). */
    public function transferReturn(StockDocument $doc, StockDocumentLine $line, string $qty, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $qty, StockMovement::TRANSFER_RETURN, $doc, $line, $userId, '+');
    }

    private function mutate(
        ?string $warehouseId,
        string $productId,
        string|float $qty,
        string $movementType,
        StockDocument $doc,
        StockDocumentLine $line,
        string $userId,
        string $sign,
        ?string $businessKeySuffix = null
    ): ?StockMovement {
        if ($warehouseId === null) {
            return null;
        }

        $businessKey = $this->businessKey($movementType, $doc->id, $line->id, $businessKeySuffix);

        // Idempotent no-op: nếu movement này đã tồn tại (retry cùng document/line/action),
        // không cộng/trừ tồn lần hai.
        $existing = StockMovement::where('business_key', $businessKey)->first();
        if ($existing) {
            return $existing;
        }

        $balance = InventoryBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('stock_status', 'AVAILABLE')
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            $balance = InventoryBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'on_hand_qty' => 0,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'stock_status' => 'AVAILABLE',
                'version' => 1,
            ]);
        }

        $qty = (string) $qty;
        $qtyBefore = (string) $balance->available_qty;
        $qtyAfter = $sign === '+' ? bcadd($qtyBefore, $qty, self::SCALE) : bcsub($qtyBefore, $qty, self::SCALE);

        if ($sign === '-' && bccomp($qtyAfter, '0', self::SCALE) < 0) {
            WarehouseException::insufficientStock($line->product->code ?? $productId);
        }

        $affected = InventoryBalance::where('id', $balance->id)
            ->where('version', $balance->version)
            ->update([
                'on_hand_qty' => $sign === '+' ? bcadd((string) $balance->on_hand_qty, $qty, self::SCALE) : bcsub((string) $balance->on_hand_qty, $qty, self::SCALE),
                'available_qty' => $qtyAfter,
                'version' => $balance->version + 1,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            WarehouseException::versionConflict();
        }

        $movement = StockMovement::create([
            'movement_no' => 'MV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'business_key' => $businessKey,
            'movement_type' => $movementType,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'stock_status' => 'AVAILABLE',
            'qty_before' => $qtyBefore,
            'qty_delta' => $sign === '+' ? $qty : bcmul($qty, '-1', self::SCALE),
            'qty_after' => $qtyAfter,
            'stock_document_id' => $doc->id,
            'stock_document_line_id' => $line->id,
            'created_by' => $userId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->safeStockAlerts->evaluateProduct($productId, $warehouseId);

        return $movement;
    }

    private function businessKey(string $movementType, string $docId, string $lineId, ?string $suffix = null): string
    {
        // Khác ERP gốc (idempotency_key theo action rời rạc từng bước nhận/mất mát): base chỉ
        // cần 1 business_key cố định theo (movement_type, document, line) vì receive/loss ở
        // base xử lý toàn bộ phần còn lại của InTransitLedger trong 1 lần gọi duy nhất, không
        // chia nhiều lần nhận từng phần như ERP gốc.
        return sha1("{$movementType}|{$docId}|{$lineId}|".($suffix ?? 'full'));
    }
}
