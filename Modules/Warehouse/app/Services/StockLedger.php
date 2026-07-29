<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Str;
use Modules\Warehouse\Exceptions\WarehouseException;
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

    public function inbound(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate($doc->dst_warehouse_id, $line->product_id, $line->qty_actual, StockMovement::RECEIPT_IN, $doc, $line, $userId, '+', $this->businessKey(StockMovement::RECEIPT_IN, $doc->id, $line->id));
    }

    public function outbound(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $line->qty_actual, StockMovement::ISSUE_OUT, $doc, $line, $userId, '-', $this->businessKey(StockMovement::ISSUE_OUT, $doc->id, $line->id));
    }

    /**
     * Ghi nhận 1 lần scan (nhận/xuất từng phần) — mỗi lần gọi là 1 sự kiện độc lập nên
     * business_key luôn khác nhau; chống retry trùng ở tầng HTTP dựa vào IdempotencyStore
     * của controller, không dựa vào business_key ở đây.
     */
    public function scanReceipt(StockDocument $doc, StockDocumentLine $line, string $qty, string $userId): ?StockMovement
    {
        return $this->mutate($doc->dst_warehouse_id, $line->product_id, $qty, StockMovement::RECEIPT_IN, $doc, $line, $userId, '+', $this->scanKey($doc->id, $line->id));
    }

    public function scanIssue(StockDocument $doc, StockDocumentLine $line, string $qty, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $qty, StockMovement::ISSUE_OUT, $doc, $line, $userId, '-', $this->scanKey($doc->id, $line->id));
    }

    /**
     * Đảo ngược 1 movement gốc (reverse/correction) — cộng/trừ ngược dấu, tham chiếu
     * `original_movement_id`. Không cho đảo ngược 1 movement đã bị đảo ngược trước đó
     * (caller — StockDocumentService::reverseDocument — chịu trách nhiệm kiểm tra).
     */
    public function reverse(StockMovement $original, string $userId): StockMovement
    {
        $reversedType = $original->movement_type === StockMovement::ISSUE_OUT
            ? StockMovement::ISSUE_REVERSED
            : StockMovement::RECEIPT_REVERSED;

        $sign = bccomp((string) $original->qty_delta, '0', self::SCALE) >= 0 ? '-' : '+';
        $qty = bcmul((string) $original->qty_delta, '-1', self::SCALE);
        $qty = ltrim($qty, '-');

        $doc = $original->document;
        $line = $original->stock_document_line_id ? StockDocumentLine::find($original->stock_document_line_id) : null;

        return $this->mutate(
            $original->warehouse_id,
            $original->product_id,
            $qty,
            $reversedType,
            $doc,
            $line,
            $userId,
            $sign,
            $this->businessKey('reverse_'.$reversedType, $doc->id, $original->id),
            $original->id
        );
    }

    private function mutate(
        ?string $warehouseId,
        string $productId,
        string|float $qty,
        string $movementType,
        StockDocument $doc,
        ?StockDocumentLine $line,
        string $userId,
        string $sign,
        string $businessKey,
        ?string $originalMovementId = null
    ): ?StockMovement {
        if ($warehouseId === null) {
            return null;
        }

        // Idempotent no-op: nếu movement này đã tồn tại (retry cùng business_key),
        // không cộng/trừ tồn lần hai.
        $existing = StockMovement::where('business_key', $businessKey)->first();
        if ($existing) {
            return $existing;
        }

        $balance = InventoryBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            $balance = InventoryBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'on_hand_qty' => 0,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'version' => 1,
            ]);
        }

        $qty = (string) $qty;
        $qtyBefore = (string) $balance->available_qty;
        $qtyAfter = $sign === '+' ? bcadd($qtyBefore, $qty, self::SCALE) : bcsub($qtyBefore, $qty, self::SCALE);

        if ($sign === '-' && bccomp($qtyAfter, '0', self::SCALE) < 0) {
            WarehouseException::insufficientStock($line?->product?->code ?? $productId);
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
            'qty_before' => $qtyBefore,
            'qty_delta' => $sign === '+' ? $qty : bcmul($qty, '-1', self::SCALE),
            'qty_after' => $qtyAfter,
            'stock_document_id' => $doc->id,
            'stock_document_line_id' => $line?->id,
            'original_movement_id' => $originalMovementId,
            'created_by' => $userId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->safeStockAlerts->evaluateProduct($productId, $warehouseId);

        return $movement;
    }

    private function businessKey(string $movementType, string $docId, string $lineId): string
    {
        return sha1("{$movementType}|{$docId}|{$lineId}");
    }

    private function scanKey(string $docId, string $lineId): string
    {
        return sha1("scan|{$docId}|{$lineId}|".Str::uuid());
    }
}
