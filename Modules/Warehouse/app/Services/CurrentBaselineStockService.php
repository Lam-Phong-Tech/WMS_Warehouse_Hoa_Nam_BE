<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineStockService
{
    private const ACTIVE_DOCUMENT_STATUSES = ['DRAFT', 'SCANNING'];

    private const AVAILABLE = 'AVAILABLE';

    public function scanStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $payload, $actorId): array {
            $document = $this->lockDocument($documentId, $documentType);
            if (! in_array($document->status, self::ACTIVE_DOCUMENT_STATUSES, true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ chứng từ DRAFT/SCANNING mới được scan.');
            }

            $line = DB::table('stock_document_lines')
                ->where('id', $payload['line_id'])
                ->where('stock_document_id', $documentId)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy dòng chứng từ.');
            }

            $quantity = (int) $payload['quantity'];
            if ($quantity <= 0) {
                ApiError::abort(422, 'INVALID_QUANTITY', 'Số lượng scan phải là số nguyên lớn hơn 0.');
            }

            $planned = (int) $line->qty_planned;
            $scanned = (int) $line->scanned_qty;
            if ($scanned + $quantity > $planned) {
                ApiError::abort(422, 'SCAN_EXCEEDS_REQUIRED', 'Số lượng scan vượt số lượng yêu cầu của dòng.');
            }

            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU không tồn tại hoặc không ACTIVE.');
            }

            $item = $this->resolveItem($payload, (string) $line->product_id);
            $requiresItem = strtoupper((string) $sku->sku_type) === 'PRODUCT' || (bool) $sku->is_serial_tracked;
            if ($requiresItem && $item === null) {
                ApiError::abort(422, 'ITEM_REQUIRED', 'PRODUCT/serial-tracked line bắt buộc scan một item cụ thể.');
            }
            if ($item !== null && $quantity !== 1) {
                ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Scan item cụ thể chỉ cho phép quantity=1.');
            }

            if ($documentType === 'issue') {
                $this->assertIssueAvailability($document, $line, $quantity);
                if ($item !== null) {
                    $this->assertItemAvailableForIssue($item, $documentId);
                }
            } elseif ($item !== null) {
                $this->assertItemAvailableForReceipt($item, $documentId);
            }

            if ($document->status === 'DRAFT') {
                DB::table('stock_documents')->where('id', $documentId)->update([
                    'status' => 'SCANNING',
                    'submitted_by' => $actorId,
                    'submitted_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordDocumentStatus($documentId, 'DRAFT', 'SCANNING', 'scan_start', $actorId);
            }

            if ($documentType === 'receipt') {
                $this->recordInboundScan((string) $line->id, $item, $payload, $quantity, $actorId);
            } else {
                $this->recordOutboundMatch((string) $line->id, $item, $payload, $quantity, $actorId);
            }

            DB::table('stock_document_lines')->where('id', $line->id)->update([
                'scanned_qty' => $scanned + $quantity,
                'updated_at' => now(),
            ]);

            $this->recordScanEvidence($document, $line, $item, $payload, $actorId);

            return [
                'document_id' => $documentId,
                'line_id' => (string) $line->id,
                'sku_id' => (string) $line->product_id,
                'item_id' => $item?->item_id,
                'quantity' => $quantity,
                'required_quantity' => $planned,
                'scanned_quantity' => $scanned + $quantity,
                'status' => 'SCANNING',
                'stock_effect' => 'NONE',
                'movement_created' => false,
            ];
        }, 3);
    }

    public function postStockDocument(
        string $documentId,
        string $documentType,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $actorId, $idempotencyKey, $requestHash): array {
            $document = $this->lockDocument($documentId, $documentType);
            if ($document->status !== 'SCANNING') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ chứng từ SCANNING mới được Post.');
            }

            $lines = DB::table('stock_document_lines')
                ->where('stock_document_id', $documentId)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();
            if ($lines->isEmpty()) {
                ApiError::abort(422, 'DOCUMENT_HAS_NO_LINES', 'Chứng từ phải có ít nhất một dòng.');
            }

            foreach ($lines as $line) {
                if ((int) $line->scanned_qty !== (int) $line->qty_planned) {
                    ApiError::abort(422, 'FULL_POST_REQUIRED', 'Mọi dòng phải scan đủ chính xác trước khi Post.', [
                        'line_id' => (string) $line->id,
                        'required_qty' => (int) $line->qty_planned,
                        'scanned_qty' => (int) $line->scanned_qty,
                    ]);
                }
            }

            if ($documentType === 'issue') {
                $this->assertMatchedProductRfids($documentId, $lines);
            }

            $movementIds = [];
            foreach ($lines as $line) {
                $warehouseId = $documentType === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id;
                if ($warehouseId === null) {
                    ApiError::abort(422, 'WAREHOUSE_REQUIRED', 'Chứng từ thiếu warehouse stock-effect.');
                }

                $movement = $this->mutateBalance(
                    (string) $warehouseId,
                    (string) $line->product_id,
                    (int) $line->qty_planned,
                    $documentType === 'receipt' ? 'RECEIPT' : 'ISSUE',
                    $documentType === 'receipt' ? 1 : -1,
                    'STOCK_DOCUMENT',
                    $documentId,
                    (string) $line->id,
                    $actorId,
                    sha1(($documentType === 'receipt' ? 'RECEIPT' : 'ISSUE').'|'.$documentId.'|'.$line->id),
                    self::AVAILABLE,
                    null,
                    null,
                    $this->resolveLineItemId($documentType, (string) $line->id)
                );
                $movementIds[] = $movement['id'];
            }

            if ($documentType === 'receipt') {
                $this->applyReceiptSubledger($documentId);
            } else {
                $this->applyIssueSubledger($documentId);
            }

            DB::table('stock_documents')->where('id', $documentId)->update([
                'status' => 'POSTED',
                'posted_by' => $actorId,
                'posted_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordDocumentStatus($documentId, 'SCANNING', 'POSTED', $documentType === 'receipt' ? 'post_receipt' : 'post_issue', $actorId);
            $this->recordPostingEvent(
                ($documentType === 'receipt' ? 'post_receipt_' : 'post_issue_').$documentId,
                $idempotencyKey,
                $requestHash,
                $documentId,
                $movementIds[0] ?? null,
                $actorId,
                ['document_id' => $documentId, 'status' => 'POSTED', 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: $documentType === 'receipt' ? 'POST_RECEIPT' : 'POST_ISSUE',
                objectType: 'stock_document',
                objectId: $documentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                beforeData: ['status' => 'SCANNING'],
                afterData: ['status' => 'POSTED', 'movement_ids' => $movementIds],
            );

            return [
                'document_id' => $documentId,
                'status' => 'POSTED',
                'movement_ids' => $movementIds,
                'stock_effect' => $documentType === 'receipt' ? 'INCREASE' : 'DECREASE',
            ];
        }, 3);
    }

    public function reverseStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $payload, $actorId, $idempotencyKey, $requestHash): array {
            $document = $this->lockDocument($documentId, $documentType);
            if ($document->status !== 'POSTED') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ chứng từ POSTED mới được reversal.');
            }

            $condition = strtoupper((string) ($payload['condition_status'] ?? 'SELLABLE'));
            if (! in_array($condition, ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], true)) {
                ApiError::abort(422, 'INVALID_RETURN_CONDITION', 'condition_status không hợp lệ.');
            }

            if ($documentType === 'receipt') {
                $this->assertReceiptDependenciesReversible($documentId);
            }

            $originalMovements = DB::table('stock_movements')
                ->where('stock_document_id', $documentId)
                ->whereIn('movement_type', [$documentType === 'receipt' ? 'RECEIPT' : 'ISSUE'])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();
            if ($originalMovements->isEmpty()) {
                ApiError::abort(422, 'NO_REVERSIBLE_MOVEMENT', 'Không có movement gốc hợp lệ để reversal.');
            }

            $requestedLines = collect($payload['lines'] ?? [])->keyBy('movement_id');
            $reversalDocumentId = (string) Str::uuid();
            $chainId = (string) Str::uuid();
            DB::table('reversal_document')->insert([
                'reversal_document_id' => $reversalDocumentId,
                'document_no' => 'REV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'stock_document_id' => $documentId,
                'status' => 'DRAFT',
                'reason' => (string) ($payload['reason'] ?? 'Correction'),
                'condition_status' => $condition,
                'correction_chain_id' => $chainId,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $movementIds = [];
            foreach ($originalMovements as $movement) {
                $alreadyReversed = (int) DB::table('reversal_line as rl')
                    ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
                    ->where('rl.original_movement_id', $movement->id)
                    ->where('rd.status', 'POSTED')
                    ->sum('rl.reversal_qty');
                $originalQty = abs((int) $movement->quantity);
                $remaining = $originalQty - $alreadyReversed;
                if ($remaining <= 0) {
                    continue;
                }

                $requested = $requestedLines->get($movement->id);
                $qty = $requested ? (int) $requested['quantity'] : $remaining;
                if ($qty <= 0 || $qty > $remaining) {
                    ApiError::abort(422, 'REVERSAL_QTY_EXCEEDED', 'Reversal quantity vượt reversible_qty.', [
                        'movement_id' => (string) $movement->id,
                        'reversible_qty' => $remaining,
                    ]);
                }

                $stockStatus = $documentType === 'issue'
                    ? match ($condition) {
                        'DEFECTIVE' => 'DEFECTIVE',
                        'NON_SELLABLE' => 'NON_SELLABLE',
                        default => 'AVAILABLE',
                    }
                : (string) ($movement->stock_status ?: self::AVAILABLE);
                $sign = $documentType === 'receipt' ? -1 : 1;

                $reversalLineId = (string) Str::uuid();
                DB::table('reversal_line')->insert([
                    'reversal_line_id' => $reversalLineId,
                    'reversal_document_id' => $reversalDocumentId,
                    'original_movement_id' => $movement->id,
                    'reversal_qty' => $qty,
                    'sku_id' => $movement->sku_id ?? $movement->product_id,
                    'item_id' => $movement->item_id ?? null,
                    'stock_status' => $stockStatus,
                    'reason' => (string) ($payload['reason'] ?? 'Correction'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $newMovement = $this->mutateBalance(
                    (string) $movement->warehouse_id,
                    (string) ($movement->sku_id ?? $movement->product_id),
                    $qty,
                    'REVERSAL',
                    $sign,
                    'REVERSAL_DOCUMENT',
                    $reversalDocumentId,
                    $reversalLineId,
                    $actorId,
                    sha1('REVERSAL|'.$reversalDocumentId.'|'.$movement->id.'|'.$qty),
                    $stockStatus,
                    (string) $movement->id,
                    $chainId
                );
                $movementIds[] = $newMovement['id'];
            }

            if ($movementIds === []) {
                ApiError::abort(409, 'ALREADY_FULLY_REVERSED', 'Chứng từ đã được reversal toàn bộ.');
            }

            if ($documentType === 'issue') {
                $this->applyReverseIssueSubledger($documentId, $condition);
            } else {
                $this->applyReverseReceiptSubledger($documentId);
            }

            DB::table('reversal_document')->where('reversal_document_id', $reversalDocumentId)->update([
                'status' => 'POSTED',
                'posted_at' => now(),
                'posted_by' => $actorId,
                'updated_at' => now(),
            ]);

            $this->recordPostingEvent(
                'reverse_stock_document_'.$documentId,
                $idempotencyKey,
                $requestHash,
                $reversalDocumentId,
                $movementIds[0] ?? null,
                $actorId,
                ['reversal_document_id' => $reversalDocumentId, 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: 'REVERSAL_POST',
                objectType: 'reversal_document',
                objectId: $reversalDocumentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                reason: $payload['reason'] ?? null,
                afterData: ['original_document_id' => $documentId, 'movement_ids' => $movementIds],
            );

            return [
                'original_document_id' => $documentId,
                'original_document_status' => 'POSTED',
                'reversal_document_id' => $reversalDocumentId,
                'reversal_status' => 'POSTED',
                'correction_chain_id' => $chainId,
                'movement_ids' => $movementIds,
            ];
        }, 3);
    }

    public function postComponentIssue(
        string $documentId,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        return DB::transaction(function () use ($documentId, $actorId, $idempotencyKey, $requestHash): array {
            $document = DB::table('component_issue_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
            }
            if ($document->status !== 'DRAFT') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ Component Issue DRAFT mới được Post.');
            }
            if (strtoupper((string) $document->purpose) !== 'WARRANTY') {
                ApiError::abort(422, 'COMPONENT_PURPOSE_INVALID', 'Component Issue chỉ cho purpose=WARRANTY.');
            }

            $case = DB::table('warranty_case')->where('warranty_case_id', $document->warranty_case_id)->lockForUpdate()->first();
            if ($case === null || in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Component Issue yêu cầu warranty case đang active.');
            }

            $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $documentId)->orderBy('line_no')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                ApiError::abort(422, 'DOCUMENT_HAS_NO_LINES', 'Component Issue phải có ít nhất một dòng.');
            }

            $movementIds = [];
            foreach ($lines as $line) {
                $sku = DB::table('sku')->where('sku_id', $line->sku_id)->first();
                if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                    ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Mọi dòng Component Issue phải là SKU loại COMPONENT.');
                }
                if ((int) $line->scanned_qty !== (int) $line->required_qty) {
                    ApiError::abort(422, 'FULL_POST_REQUIRED', 'Component Issue chỉ Post khi scanned_qty = required_qty.');
                }

                $itemIds = DB::table('component_issue_items')->where('component_issue_line_id', $line->id)->whereNotNull('item_id')->pluck('item_id');

                $movement = $this->mutateBalance(
                    (string) $document->warehouse_id,
                    (string) $line->sku_id,
                    (int) $line->required_qty,
                    'COMPONENT_ISSUE',
                    -1,
                    'COMPONENT_ISSUE_DOCUMENT',
                    $documentId,
                    (string) $line->id,
                    $actorId,
                    sha1('COMPONENT_ISSUE|'.$documentId.'|'.$line->id),
                    self::AVAILABLE,
                    null,
                    null,
                    $itemIds->count() === 1 ? (string) $itemIds->first() : null
                );
                $movementIds[] = $movement['id'];

                if ($itemIds->isNotEmpty()) {
                    DB::table('item_instances')->whereIn('item_id', $itemIds)->update([
                        'item_status' => 'ISSUED',
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('component_issue_documents')->where('id', $documentId)->update([
                'status' => 'POSTED',
                'idempotency_key' => $idempotencyKey,
                'posted_at' => now(),
                'posted_by' => $actorId,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);
            $this->recordPostingEvent(
                'post_component_issue_'.$documentId,
                $idempotencyKey,
                $requestHash,
                $documentId,
                $movementIds[0] ?? null,
                $actorId,
                ['component_issue_document_id' => $documentId, 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: 'POST_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $documentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                afterData: ['status' => 'POSTED', 'movement_ids' => $movementIds],
            );

            return [
                'component_issue_document_id' => $documentId,
                'status' => 'POSTED',
                'movement_ids' => $movementIds,
                'stock_effect' => 'DECREASE',
            ];
        }, 3);
    }

    private function resolveItem(array $payload, string $skuId): ?object
    {
        $itemId = $payload['item_id'] ?? null;
        if (isset($payload['code_value'])) {
            $physical = DB::table('physical_codes')
                ->where('code_value', strtoupper(trim((string) $payload['code_value'])))
                ->where('status', 'ACTIVE')
                ->first();
            if ($physical === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR không tồn tại hoặc không ACTIVE.');
            }

            $resolvedId = $physical->assigned_object_type === 'ITEM'
                ? $physical->assigned_object_id
                : DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->value('item_id');
            if ($resolvedId === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_UNASSIGNED', 'Mã vật lý chưa được map vào ITEM.');
            }
            if ($itemId !== null && strtolower((string) $itemId) !== strtolower((string) $resolvedId)) {
                ApiError::abort(422, 'PHYSICAL_CODE_ITEM_MISMATCH', 'code_value và item_id không trỏ cùng item.');
            }
            $itemId = $resolvedId;
        }

        if ($itemId === null) {
            return null;
        }

        $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
        if ($item === null || (string) $item->sku_id !== $skuId) {
            ApiError::abort(422, 'ITEM_SKU_MISMATCH', 'Item không tồn tại hoặc không thuộc SKU của dòng.');
        }

        return $item;
    }

    private function assertItemAvailableForIssue(object $item, string $documentId): void
    {
        if ((string) $item->item_status !== 'IN_STOCK') {
            ApiError::abort(422, 'ITEM_NOT_AVAILABLE', 'Item phải ở trạng thái IN_STOCK trước khi match outbound.');
        }

        $conflict = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('m.item_id', $item->item_id)
            ->where('d.id', '!=', $documentId)
            ->whereIn('d.status', self::ACTIVE_DOCUMENT_STATUSES)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->exists();
        if ($conflict) {
            ApiError::abort(409, 'ITEM_ALREADY_MATCHED', 'Item đang được match ở một outbound khác.');
        }
    }

    private function assertItemAvailableForReceipt(object $item, string $documentId): void
    {
        if (! in_array((string) $item->item_status, ['NEW', 'ADJUSTED_OUT'], true)) {
            ApiError::abort(422, 'ITEM_NOT_RECEIVABLE', 'Item đã nằm trong luồng tồn khác.');
        }

        $conflict = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('e.item_id', $item->item_id)
            ->where('d.id', '!=', $documentId)
            ->whereIn('d.status', self::ACTIVE_DOCUMENT_STATUSES)
            ->exists();
        if ($conflict) {
            ApiError::abort(409, 'ITEM_ALREADY_RECEIVING', 'Item đang thuộc một inbound chưa Post khác.');
        }
    }

    private function assertIssueAvailability(object $document, object $line, int $quantity): void
    {
        $available = (int) (DB::table('inventory_balances')
            ->where('warehouse_id', $document->src_warehouse_id)
            ->where(function ($query) use ($line): void {
                $query->where('sku_id', $line->product_id)->orWhere('product_id', $line->product_id);
            })
            ->where('stock_status', self::AVAILABLE)
            ->value('quantity') ?? 0);
        if ($available < $quantity) {
            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Không đủ tồn khả dụng để scan outbound.');
        }
    }

    private function recordInboundScan(string $lineId, ?object $item, array $payload, int $quantity, ?string $actorId): void
    {
        DB::table('inbound_scan_entries')->insert([
            'id' => (string) Str::uuid(),
            'inbound_line_id' => $lineId,
            'item_id' => $item?->item_id,
            'container_id' => $payload['container_id'] ?? null,
            'quantity' => $quantity,
            'scan_source' => isset($payload['code_value']) ? 'CAMERA_OR_MANUAL_CODE' : 'MANUAL',
            'created_at' => now(),
            'created_by' => $actorId,
            'updated_at' => now(),
            'updated_by' => $actorId,
            'version' => 0,
        ]);
    }

    private function recordOutboundMatch(string $lineId, ?object $item, array $payload, int $quantity, ?string $actorId): void
    {
        if ($item !== null && DB::table('outbound_item_matches')
            ->where('outbound_line_id', $lineId)
            ->where('item_id', $item->item_id)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
            })
            ->exists()) {
            ApiError::abort(409, 'DUPLICATE_ITEM_MATCH', 'Item đã được match vào dòng outbound này.');
        }

        DB::table('outbound_item_matches')->insert([
            'id' => (string) Str::uuid(),
            'outbound_line_id' => $lineId,
            'item_id' => $item?->item_id,
            'container_id' => $payload['container_id'] ?? null,
            'quantity' => $quantity,
            'status' => 'ACTIVE',
            'matched_at' => now(),
            'matched_by' => $actorId,
            'created_at' => now(),
            'created_by' => $actorId,
            'updated_at' => now(),
            'updated_by' => $actorId,
            'version' => 0,
        ]);
    }

    private function recordScanEvidence(object $document, object $line, ?object $item, array $payload, ?string $actorId): void
    {
        if (! Schema::hasTable('scan_batch') || ! Schema::hasTable('scan_event')) {
            return;
        }

        // NFR-SRS-004: mọi scan (camera/nhập tay code_value lẫn chọn item_id trực
        // tiếp) đều để lại scan_event; raw_code fallback về mã vật lý của item.
        $rawCode = isset($payload['code_value']) ? strtoupper(trim((string) $payload['code_value'])) : null;
        $physicalId = null;
        if ($rawCode !== null && $rawCode !== '') {
            $physicalId = DB::table('physical_codes')->where('code_value', $rawCode)->value('physical_code_id');
        } elseif ($item !== null) {
            $physical = DB::table('physical_codes')
                ->where('assigned_object_type', 'ITEM')
                ->where('assigned_object_id', $item->item_id)
                ->where('status', 'ACTIVE')
                ->orderBy('created_at')
                ->first(['physical_code_id', 'code_value']);
            $physicalId = $physical?->physical_code_id;
            $rawCode = $physical?->code_value !== null ? (string) $physical->code_value : (string) $item->item_id;
        } else {
            $rawCode = (string) $line->product_id;
        }

        $batchId = (string) Str::uuid();
        DB::table('scan_batch')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => strtoupper((string) $document->doc_type).'_DOCUMENT',
            'source_id' => $document->id,
            'warehouse_id' => $document->doc_type === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
        DB::table('scan_event')->insert([
            'scan_event_id' => (string) Str::uuid(),
            'scan_batch_id' => $batchId,
            'raw_code' => $rawCode,
            'physical_code_id' => $physicalId,
            'resolved_object_type' => $item ? 'ITEM' : 'SKU',
            'resolved_object_id' => $item?->item_id ?? $line->product_id,
            'result_status' => 'VALID',
            'message' => 'Validated against current baseline',
            'created_at' => now(),
        ]);
    }

    private function assertMatchedProductRfids(string $documentId, $lines): void
    {
        foreach ($lines as $line) {
            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                continue;
            }

            $matches = DB::table('outbound_item_matches')
                ->where('outbound_line_id', $line->id)
                ->whereNotNull('item_id')
                ->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
                })
                ->get();
            if ($matches->count() !== (int) $line->qty_planned) {
                ApiError::abort(422, 'PRODUCT_ITEM_MATCH_REQUIRED', 'Mỗi PRODUCT unit phải được match bằng item cụ thể trước Post Issue.', [
                    'line_id' => (string) $line->id,
                ]);
            }

            foreach ($matches as $match) {
                $hasRfid = DB::table('physical_codes')
                    ->where('assigned_object_type', 'ITEM')
                    ->where('assigned_object_id', $match->item_id)
                    ->where('carrier_type', 'RFID')
                    ->where('status', 'ACTIVE')
                    ->whereNotNull('hardware_uid')
                    ->exists();
                if (! $hasRfid) {
                    ApiError::abort(422, 'ITEM_RFID_REQUIRED', 'PRODUCT item thiếu active RFID/hardware_uid trước Post Issue.', [
                        'item_id' => (string) $match->item_id,
                    ]);
                }
            }
        }
    }

    private function applyReceiptSubledger(string $documentId): void
    {
        $itemIds = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('e.item_id')
            ->pluck('e.item_id');
        if ($itemIds->isNotEmpty()) {
            DB::table('item_instances')->whereIn('item_id', $itemIds)->update([
                'item_status' => 'IN_STOCK',
                'updated_at' => now(),
            ]);
        }
    }

    private function applyIssueSubledger(string $documentId): void
    {
        $activeMatches = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->get(['m.id', 'm.item_id']);

        $itemIds = $activeMatches->pluck('item_id')->filter();
        if ($itemIds->isNotEmpty()) {
            $update = ['item_status' => 'ISSUED', 'updated_at' => now()];
            if (Schema::hasColumn('item_instances', 'issued_at')) {
                $update['issued_at'] = now();
            }
            DB::table('item_instances')->whereIn('item_id', $itemIds)->update($update);
        }

        // ERD state machine: match ACTIVE -> POSTED tại Post Issue.
        if ($activeMatches->isNotEmpty()) {
            DB::table('outbound_item_matches')
                ->whereIn('id', $activeMatches->pluck('id'))
                ->update(['status' => 'POSTED', 'updated_at' => now()]);
        }
    }

    private function applyReverseIssueSubledger(string $documentId, string $condition): void
    {
        $itemIds = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('m.item_id')
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->pluck('m.item_id');
        if ($itemIds->isEmpty()) {
            return;
        }

        $update = [
            'item_status' => 'IN_STOCK',
            'item_condition' => $condition === 'SELLABLE' ? 'NORMAL' : $condition,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('item_instances', 'issued_at')) {
            $update['issued_at'] = null;
        }
        DB::table('item_instances')->whereIn('item_id', $itemIds)->update($update);
    }

    private function applyReverseReceiptSubledger(string $documentId): void
    {
        $itemIds = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('e.item_id')
            ->pluck('e.item_id');
        if ($itemIds->isNotEmpty()) {
            DB::table('item_instances')->whereIn('item_id', $itemIds)->update([
                'item_status' => 'ADJUSTED_OUT',
                'updated_at' => now(),
            ]);
        }
    }

    private function assertReceiptDependenciesReversible(string $documentId): void
    {
        $blocked = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('item_instances as i', 'i.item_id', '=', 'e.item_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('e.item_id')
            ->whereNotIn('i.item_status', ['IN_STOCK', 'NEW', 'ADJUSTED_OUT'])
            ->exists();
        if ($blocked) {
            ApiError::abort(409, 'REVERSAL_DEPENDENCY_EXISTS', 'Receipt có item đã Issue/Warranty; phải đảo dependency theo thứ tự ngược.');
        }
    }

    private function mutateBalance(
        string $warehouseId,
        string $skuId,
        int $quantity,
        string $movementType,
        int $sign,
        string $sourceType,
        string $sourceId,
        ?string $sourceLineId,
        ?string $actorId,
        string $businessKey,
        string $stockStatus = self::AVAILABLE,
        ?string $originalMovementId = null,
        ?string $correctionChainId = null,
        ?string $itemId = null
    ): array {
        if ($quantity <= 0) {
            ApiError::abort(422, 'INVALID_QUANTITY', 'Stock quantity phải là số nguyên dương.');
        }
        if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
            ApiError::abort(409, 'DUPLICATE_STOCK_EFFECT', 'Stock effect đã được ghi trước đó.');
        }

        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->where('stock_status', $stockStatus)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            if ($sign < 0) {
                ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Không đủ tồn cho stock effect.');
            }
            $balanceId = (string) Str::uuid();
            DB::table('inventory_balances')->insert([
                'id' => $balanceId,
                'warehouse_id' => $warehouseId,
                'product_id' => $skuId,
                'sku_id' => $skuId,
                'stock_status' => $stockStatus,
                'quantity' => 0,
                'on_hand_qty' => 0,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $balance = DB::table('inventory_balances')->where('id', $balanceId)->lockForUpdate()->first();
        }

        $before = (int) $balance->quantity;
        $after = $before + ($sign * $quantity);
        if ($after < 0) {
            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Stock effect sẽ làm tồn âm.');
        }

        $availableBefore = (int) $balance->available_qty;
        $availableAfter = $stockStatus === self::AVAILABLE ? $availableBefore + ($sign * $quantity) : $availableBefore;
        if ($availableAfter < 0) {
            ApiError::abort(422, 'INSUFFICIENT_AVAILABLE_STOCK', 'Stock effect sẽ làm available_qty âm.');
        }

        $updated = DB::table('inventory_balances')->where('id', $balance->id)->where('version', $balance->version)->update([
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'quantity' => $after,
            'on_hand_qty' => $after,
            'available_qty' => $availableAfter,
            'version' => ((int) $balance->version) + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            ApiError::abort(409, 'VERSION_CONFLICT', 'Inventory balance changed concurrently; retry against fresh state.');
        }

        $movementId = (string) Str::uuid();
        DB::table('stock_movements')->insert([
            'id' => $movementId,
            'movement_no' => 'MV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'business_key' => $businessKey,
            'movement_type' => $movementType,
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => $stockStatus,
            'quantity' => $quantity,
            'qty_before' => $before,
            'qty_delta' => $sign * $quantity,
            'qty_after' => $after,
            'stock_document_id' => $sourceType === 'STOCK_DOCUMENT' ? $sourceId : null,
            'stock_document_line_id' => $sourceType === 'STOCK_DOCUMENT' ? $sourceLineId : null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_line_id' => $sourceLineId,
            'item_id' => $itemId,
            'original_movement_id' => $originalMovementId,
            'correction_chain_id' => $correctionChainId,
            'created_by' => $actorId,
            'request_id' => request()?->attributes->get('request_id'),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return ['id' => $movementId, 'before' => $before, 'after' => $after];
    }

    /**
     * Resolve the single item backing a stock_document_line's posted movement, when
     * unambiguous. stock_movements.item_id is 1:1 (see CurrentBaselineReversalService,
     * which always sets it per reversal_line); a document line can carry multiple scanned
     * items when qty_planned > 1 for serial-tracked SKUs, and in that case the aggregate
     * movement genuinely has no single item to attribute — item_id stays null, matching the
     * documented read-side fallback in StockMovementController::traceByPhysicalCode().
     */
    private function resolveLineItemId(string $documentType, string $lineId): ?string
    {
        $itemIds = $documentType === 'receipt'
            ? DB::table('inbound_scan_entries')->where('inbound_line_id', $lineId)->whereNotNull('item_id')->pluck('item_id')
            : DB::table('outbound_item_matches')
                ->where('outbound_line_id', $lineId)
                ->whereNotNull('item_id')
                ->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
                })
                ->pluck('item_id');

        return $itemIds->count() === 1 ? (string) $itemIds->first() : null;
    }

    private function lockDocument(string $documentId, string $documentType): object
    {
        $document = DB::table('stock_documents')
            ->where('id', $documentId)
            ->where('doc_type', $documentType)
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
        }

        return $document;
    }

    private function recordDocumentStatus(string $documentId, ?string $from, string $to, string $action, ?string $actorId): void
    {
        if ($actorId === null || ! Schema::hasTable('document_status_history')) {
            return;
        }
        DB::table('document_status_history')->insert([
            'id' => (string) Str::uuid(),
            'stock_document_id' => $documentId,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'changed_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    private function recordPostingEvent(
        string $operation,
        string $idempotencyKey,
        string $requestHash,
        string $documentId,
        ?string $movementId,
        ?string $actorId,
        array $snapshot
    ): void {
        if (! Schema::hasTable('posting_event')) {
            return;
        }
        DB::table('posting_event')->insert([
            'posting_event_id' => (string) Str::uuid(),
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'status' => 'COMMITTED',
            'document_id' => $documentId,
            'movement_id' => $movementId,
            'response_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }
}
