<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Str;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;

/** Mọi thay đổi tồn phải đi qua engine này trong một DB transaction của caller. */
class StockLedger
{
    private const SCALE = 3;

    private const DEFAULT_STOCK_STATUS = 'AVAILABLE';

    public function __construct(private readonly SafeStockAlertService $safeStockAlerts) {}

    public function inbound(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate($doc->dst_warehouse_id, $line->product_id, $line->qty_actual, StockMovement::RECEIPT_IN, $doc, $line, $userId, '+', $this->businessKey(StockMovement::RECEIPT_IN, $doc->id, $line->id));
    }

    public function outbound(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $line->qty_actual, StockMovement::ISSUE_OUT, $doc, $line, $userId, '-', $this->businessKey(StockMovement::ISSUE_OUT, $doc->id, $line->id));
    }

    public function transferOut(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $line->qty_planned, StockMovement::TRANSFER_OUT, $doc, $line, $userId, '-', $this->businessKey(StockMovement::TRANSFER_OUT, $doc->id, $line->id));
    }

    public function transferIn(StockDocument $doc, StockDocumentLine $line, string $qty, string $userId): ?StockMovement
    {
        return $this->mutate($doc->dst_warehouse_id, $line->product_id, $qty, StockMovement::TRANSFER_IN, $doc, $line, $userId, '+', $this->businessKey(StockMovement::TRANSFER_IN, $doc->id, $line->id));
    }

    public function transferReturn(StockDocument $doc, StockDocumentLine $line, string $qty, string $userId): ?StockMovement
    {
        return $this->mutate($doc->src_warehouse_id, $line->product_id, $qty, StockMovement::TRANSFER_RETURN, $doc, $line, $userId, '+', $this->businessKey(StockMovement::TRANSFER_RETURN, $doc->id, $line->id));
    }

    public function reverse(StockMovement $original, string $userId): StockMovement
    {
        $reversedType = $original->movement_type === StockMovement::ISSUE_OUT
            ? StockMovement::ISSUE_REVERSED
            : StockMovement::RECEIPT_REVERSED;
        $sign = bccomp((string) $original->qty_delta, '0', self::SCALE) >= 0 ? '-' : '+';
        $qty = ltrim(bcmul((string) $original->qty_delta, '-1', self::SCALE), '-');
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
            $original->id,
            $original->stock_status ?: self::DEFAULT_STOCK_STATUS
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
        ?string $originalMovementId = null,
        string $stockStatus = self::DEFAULT_STOCK_STATUS
    ): ?StockMovement {
        if ($warehouseId === null) {
            return null;
        }

        if (StockMovement::where('business_key', $businessKey)->exists()) {
            WarehouseException::invalidStatus('Nghiệp vụ tồn kho này đã được hạch toán trước đó.');
        }

        $balanceQuery = fn () => InventoryBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('stock_status', $stockStatus);

        $balance = $balanceQuery()->lockForUpdate()->first();

        if (! $balance) {
            InventoryBalance::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'stock_status' => $stockStatus,
                'on_hand_qty' => 0,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = $balanceQuery()->lockForUpdate()->firstOrFail();
        }

        // Request cạnh tranh có thể đã commit movement trong lúc request hiện tại chờ row lock.
        // Cùng key đã được IdempotencyStore replay trước khi vào đây; movement tồn tại tại đây
        // là double-post khác key và phải trả xung đột, không được ghi status/history lần hai.
        if (StockMovement::where('business_key', $businessKey)->exists()) {
            WarehouseException::invalidStatus('Nghiệp vụ tồn kho này đã được hạch toán trước đó.');
        }

        $qty = (string) $qty;
        $qtyBefore = (string) $balance->on_hand_qty;
        $qtyAfter = $sign === '+' ? bcadd($qtyBefore, $qty, self::SCALE) : bcsub($qtyBefore, $qty, self::SCALE);
        if ($sign === '-' && bccomp($qtyAfter, '0', self::SCALE) < 0) {
            WarehouseException::insufficientStock($line?->product?->code ?? $productId);
        }

        $availableAfter = $stockStatus === self::DEFAULT_STOCK_STATUS ? $qtyAfter : (string) $balance->available_qty;
        $affected = InventoryBalance::where('id', $balance->id)
            ->where('version', $balance->version)
            ->update([
                'on_hand_qty' => $qtyAfter,
                'available_qty' => $availableAfter,
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
            'stock_status' => $stockStatus,
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

        if ($stockStatus === self::DEFAULT_STOCK_STATUS) {
            $this->safeStockAlerts->evaluateProduct($productId, $warehouseId);
        }

        return $movement;
    }

    private function businessKey(string $movementType, string $docId, string $lineId): string
    {
        return sha1("{$movementType}|{$docId}|{$lineId}");
    }
}
