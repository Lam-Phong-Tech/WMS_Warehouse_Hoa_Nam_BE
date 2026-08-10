<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Warehouse\Exceptions\WarehouseException;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;

/**
 * Compatibility ledger used by older document services.
 * New stock-changing routes use CurrentBaselineStockService. This class keeps the
 * same receipt/issue/reversal entry points but obeys the current integer-only ERD
 * and contains no safe-stock, transfer, reservation, or stocktake behavior.
 */
class StockLedger
{
    private const DEFAULT_STOCK_STATUS = 'AVAILABLE';

    public function inbound(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate(
            $doc->dst_warehouse_id,
            $line->product_id,
            $this->integerQuantity($line->qty_actual),
            StockMovement::RECEIPT_IN,
            $doc,
            $line,
            $userId,
            1,
            $this->businessKey(StockMovement::RECEIPT_IN, $doc->id, $line->id)
        );
    }

    public function outbound(StockDocument $doc, StockDocumentLine $line, string $userId): ?StockMovement
    {
        return $this->mutate(
            $doc->src_warehouse_id,
            $line->product_id,
            $this->integerQuantity($line->qty_actual),
            StockMovement::ISSUE_OUT,
            $doc,
            $line,
            $userId,
            -1,
            $this->businessKey(StockMovement::ISSUE_OUT, $doc->id, $line->id)
        );
    }

    public function reverse(StockMovement $original, string $userId): StockMovement
    {
        $doc = $original->document;
        if ($doc === null) {
            WarehouseException::invalidStatus('Movement không có source document để reversal.');
        }

        $line = $original->stock_document_line_id
            ? StockDocumentLine::find($original->stock_document_line_id)
            : null;
        $delta = (int) $original->qty_delta;
        $quantity = abs($delta);
        if ($quantity <= 0) {
            WarehouseException::invalidStatus('Movement quantity không hợp lệ để reversal.');
        }

        return $this->mutate(
            $original->warehouse_id,
            (string) ($original->sku_id ?: $original->product_id),
            $quantity,
            StockMovement::REVERSAL,
            $doc,
            $line,
            $userId,
            $delta > 0 ? -1 : 1,
            $this->businessKey(StockMovement::REVERSAL, $doc->id, $original->id),
            $original->id,
            $original->stock_status ?: self::DEFAULT_STOCK_STATUS
        );
    }

    private function mutate(
        ?string $warehouseId,
        string $productId,
        int $quantity,
        string $movementType,
        StockDocument $doc,
        ?StockDocumentLine $line,
        string $userId,
        int $sign,
        string $businessKey,
        ?string $originalMovementId = null,
        string $stockStatus = self::DEFAULT_STOCK_STATUS
    ): ?StockMovement {
        if (in_array($movementType, [StockMovement::RECEIPT_IN, StockMovement::ISSUE_OUT], true)) {
            [$doc, $line] = $this->lockAuthoritativePostingBoundary($doc, $line, $movementType);
            $warehouseId = $movementType === StockMovement::RECEIPT_IN
                ? $doc->dst_warehouse_id
                : $doc->src_warehouse_id;
            $productId = (string) $line->product_id;
            $quantity = $this->integerQuantity($line->qty_actual);
            $businessKey = $this->businessKey($movementType, $doc->id, $line->id);
        }

        if ($warehouseId === null) {
            return null;
        }
        if ($quantity <= 0) {
            WarehouseException::invalidStatus('Quantity phải là số nguyên dương.');
        }
        if (StockMovement::where('business_key', $businessKey)->exists()) {
            WarehouseException::invalidStatus('Nghiệp vụ tồn kho này đã được hạch toán trước đó.');
        }

        $balanceQuery = fn () => InventoryBalance::where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($productId): void {
                $query->where('sku_id', $productId)->orWhere('product_id', $productId);
            })
            ->where('stock_status', $stockStatus);

        $balance = $balanceQuery()->lockForUpdate()->first();
        if (! $balance) {
            if ($sign < 0) {
                WarehouseException::insufficientStock($line?->product?->code ?? $productId);
            }

            InventoryBalance::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'sku_id' => $productId,
                'stock_status' => $stockStatus,
                'quantity' => 0,
                'on_hand_qty' => 0,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $balance = $balanceQuery()->lockForUpdate()->firstOrFail();
        }

        if (StockMovement::where('business_key', $businessKey)->exists()) {
            WarehouseException::invalidStatus('Nghiệp vụ tồn kho này đã được hạch toán trước đó.');
        }

        $before = (int) $balance->quantity;
        $after = $before + ($sign * $quantity);
        if ($after < 0) {
            WarehouseException::insufficientStock($line?->product?->code ?? $productId);
        }

        $availableBefore = (int) $balance->available_qty;
        $availableAfter = $stockStatus === self::DEFAULT_STOCK_STATUS
            ? $availableBefore + ($sign * $quantity)
            : $availableBefore;
        if ($availableAfter < 0) {
            WarehouseException::insufficientStock($line?->product?->code ?? $productId);
        }

        $affected = InventoryBalance::where('id', $balance->id)
            ->where('version', $balance->version)
            ->update([
                'product_id' => $productId,
                'sku_id' => $productId,
                'quantity' => $after,
                'on_hand_qty' => $after,
                'available_qty' => $availableAfter,
                'version' => ((int) $balance->version) + 1,
                'updated_at' => now(),
            ]);
        if ($affected !== 1) {
            WarehouseException::versionConflict();
        }

        return StockMovement::create([
            'movement_no' => 'MV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'business_key' => $businessKey,
            'movement_type' => $movementType,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'sku_id' => $productId,
            'stock_status' => $stockStatus,
            'quantity' => $quantity,
            'qty_before' => $before,
            'qty_delta' => $sign * $quantity,
            'qty_after' => $after,
            'stock_document_id' => $doc->id,
            'stock_document_line_id' => $line?->id,
            'source_type' => 'STOCK_DOCUMENT',
            'source_id' => $doc->id,
            'source_line_id' => $line?->id,
            'original_movement_id' => $originalMovementId,
            'created_by' => $userId,
            'request_id' => request()?->attributes->get('request_id'),
            'occurred_at' => $doc->doc_date ? Carbon::parse($doc->doc_date)->endOfDay() : now(),
            'created_at' => now(),
        ]);
    }

    /** @return array{0:StockDocument,1:StockDocumentLine} */
    private function lockAuthoritativePostingBoundary(
        StockDocument $document,
        ?StockDocumentLine $line,
        string $movementType
    ): array {
        $currentDocument = StockDocument::query()
            ->whereKey($document->getKey())
            ->lockForUpdate()
            ->first();

        if ($currentDocument === null || $line === null) {
            WarehouseException::versionConflict();
        }

        $expectedType = $movementType === StockMovement::RECEIPT_IN
            ? StockDocument::TYPE_RECEIPT
            : StockDocument::TYPE_ISSUE;

        if ($currentDocument->doc_type !== $expectedType
            || $currentDocument->status !== StockDocument::STATUS_SCANNING) {
            WarehouseException::invalidStatus(
                "Document {$currentDocument->id} is no longer in SCANNING status for posting."
            );
        }

        $currentLine = StockDocumentLine::query()
            ->whereKey($line->getKey())
            ->where('stock_document_id', $currentDocument->id)
            ->lockForUpdate()
            ->first();
        if ($currentLine === null) {
            WarehouseException::versionConflict();
        }

        return [$currentDocument, $currentLine];
    }

    private function integerQuantity(mixed $value): int
    {
        if (! is_numeric($value) || (float) $value !== (float) (int) $value || (int) $value <= 0) {
            WarehouseException::invalidStatus('Current ERD chỉ cho phép quantity nguyên dương.');
        }

        return (int) $value;
    }

    private function businessKey(string $movementType, string $docId, string $lineId): string
    {
        return sha1("{$movementType}|{$docId}|{$lineId}");
    }
}
