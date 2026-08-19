<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use App\Support\ConflictCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineReversalService
{
    private const AVAILABLE = 'AVAILABLE';

    public function execute(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash,
        int $expectedVersion
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $payload, $actorId, $idempotencyKey, $requestHash, $expectedVersion): array {
            $pendingId = $payload['reversal_document_id'] ?? null;
            if ($pendingId !== null) {
                return $this->confirmPendingReturn(
                    $documentId,
                    $documentType,
                    (string) $pendingId,
                    $payload,
                    $actorId,
                    $idempotencyKey,
                    $requestHash,
                    $expectedVersion
                );
            }

            $document = $this->lockOriginalDocument($documentId, $documentType);
            $this->assertVersion($document, $expectedVersion, 'original document');
            if ($document->status !== 'POSTED') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ original document POSTED mới được reversal.');
            }

            $condition = $this->returnCondition($documentType, $payload);
            $physicalConfirmed = (bool) ($payload['physical_return_confirmed'] ?? false);
            $reversalId = $this->createDraft(
                $document,
                $documentType,
                $payload,
                $condition,
                $actorId,
                $idempotencyKey,
                $physicalConfirmed
            );

            if ($documentType === 'issue' && ! $physicalConfirmed) {
                return [
                    'original_document_id' => $documentId,
                    'original_document_status' => 'POSTED',
                    'reversal_document_id' => $reversalId,
                    'reversal_status' => 'DRAFT',
                    'physical_return_status' => 'PENDING_RETURN',
                    'stock_effect' => 'NONE',
                    'movement_ids' => [],
                ];
            }

            return $this->postDraft($reversalId, $document, $documentType, $actorId, $idempotencyKey, $requestHash);
        }, 3);
    }

    private function createDraft(
        object $document,
        string $documentType,
        array $payload,
        string $condition,
        ?string $actorId,
        string $idempotencyKey,
        bool $physicalConfirmed
    ): string {
        $lines = array_values($payload['lines'] ?? []);
        if ($lines === []) {
            ApiError::abort(422, 'REVERSAL_LINES_REQUIRED', 'Reversal phải chỉ rõ movement/quantity cần đảo; không auto-reverse toàn chứng từ.');
        }

        $warehouseId = (string) ($documentType === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id);
        $chainId = (string) (DB::table('reversal_document')
            ->where('original_document_id', $document->id)
            ->whereNotNull('correction_chain_id')
            ->value('correction_chain_id') ?: Str::uuid());
        $reversalId = (string) Str::uuid();
        $reasonText = trim((string) ($payload['reason'] ?? ''));
        $reasonCode = trim((string) ($payload['reason_code'] ?? 'CORRECTION'));

        DB::table('reversal_document')->insert([
            'reversal_document_id' => $reversalId,
            'document_no' => 'REV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'stock_document_id' => $document->id,
            'warehouse_id' => $warehouseId,
            'original_document_type' => $documentType === 'receipt' ? 'INBOUND' : 'OUTBOUND',
            'original_document_id' => $document->id,
            'correction_chain_id' => $chainId,
            'reason_code' => $reasonCode,
            'reason_text' => $reasonText,
            'reason' => $reasonText,
            'condition_status' => $condition,
            'physical_return_status' => $documentType === 'issue' ? ($physicalConfirmed ? 'CONFIRMED' : 'PENDING_RETURN') : null,
            'status' => 'DRAFT',
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stagedByMovement = [];
        $stagedItemIds = [];
        // WWHN-234 F-04: assertQuantityDependency() bên dưới lock `inventory_balances`
        // (BR-REV-03/04, đo available_qty) — hoãn đúng lệnh khoá đó sang Pha 2 sau
        // vòng lặp, sắp theo sku_id toàn cục, để nhất quán thứ tự khoá với
        // mutateBalance() ở postDraft() bên dưới. Mọi validate khác (movement,
        // qty-exceeded, item dependency) giữ nguyên thứ tự line_no ở Pha 1 này.
        $quantityDependencyChecks = [];
        foreach ($lines as $index => $lineInput) {
            $movement = DB::table('stock_movements')
                ->where('id', $lineInput['movement_id'])
                ->where('stock_document_id', $document->id)
                ->where('movement_type', $documentType === 'receipt' ? 'RECEIPT' : 'ISSUE')
                ->lockForUpdate()
                ->first();
            if ($movement === null) {
                ApiError::abort(422, 'ORIGINAL_MOVEMENT_INVALID', 'movement_id không thuộc original document hoặc không phải movement có thể reversal.');
            }

            $qty = (int) $lineInput['quantity'];
            if ($qty <= 0) {
                ApiError::abort(422, 'INVALID_QUANTITY', 'Reversal quantity phải là số nguyên dương.');
            }
            $alreadyPosted = (int) DB::table('reversal_line as rl')
                ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
                ->where('rl.original_movement_id', $movement->id)
                ->where('rd.status', 'POSTED')
                ->sum(DB::raw('COALESCE(rl.quantity_to_reverse, rl.reversal_qty)'));
            $staged = (int) ($stagedByMovement[$movement->id] ?? 0);
            $originalQty = abs((int) $movement->quantity);
            if ($alreadyPosted + $staged + $qty > $originalQty) {
                ApiError::abort(422, 'REVERSAL_QTY_EXCEEDED', 'Reversal quantity vượt reversible_qty.', [
                    'movement_id' => (string) $movement->id,
                    'reversible_qty' => $originalQty - $alreadyPosted - $staged,
                ]);
            }

            $skuId = (string) ($movement->sku_id ?? $movement->product_id);
            $sku = DB::table('sku')->where('sku_id', $skuId)->first();
            if ($sku === null) {
                ApiError::abort(422, 'SKU_NOT_FOUND', 'Không resolve được SKU của movement gốc.');
            }
            $serialScoped = strtoupper((string) $sku->sku_type) === 'PRODUCT' || (bool) $sku->is_serial_tracked;
            $itemId = $lineInput['item_id'] ?? null;
            if ($serialScoped) {
                if ($qty !== 1 || empty($itemId)) {
                    ApiError::abort(422, 'REVERSAL_ITEM_REQUIRED', 'PRODUCT/serial reversal phải chỉ rõ item_id và quantity=1 cho từng line.');
                }
                $this->assertItemBelongsToOriginal((string) $itemId, (string) $movement->stock_document_line_id, $documentType, $skuId);
                $this->assertItemNotAlreadyReversed((string) $itemId, (string) $movement->id, $stagedItemIds);
                $this->assertItemDependency((string) $itemId, $documentType);
                $stagedItemIds[(string) $itemId] = true;
            } else {
                if (! empty($itemId)) {
                    ApiError::abort(422, 'QUANTITY_MODE_ITEM_FORBIDDEN', 'SKU quantity-mode không được gán item_id tùy ý khi reversal.');
                }
                $quantityDependencyChecks[] = ['sku_id' => $skuId, 'movement' => $movement, 'qty' => $qty];
            }

            $reversalLineId = (string) Str::uuid();
            DB::table('reversal_line')->insert([
                'reversal_line_id' => $reversalLineId,
                'reversal_document_id' => $reversalId,
                'line_no' => $index + 1,
                'original_movement_id' => $movement->id,
                'sku_id' => $skuId,
                'item_id' => $itemId,
                'reversal_qty' => $qty,
                'quantity_to_reverse' => $qty,
                'stock_status' => $documentType === 'issue' ? $this->stockStatusForCondition($condition) : ($movement->stock_status ?: self::AVAILABLE),
                'return_condition' => $documentType === 'issue' ? $condition : null,
                'downstream_check_result' => json_encode(['status' => 'PASS', 'checked_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE),
                'reason' => $reasonText,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stagedByMovement[$movement->id] = $staged + $qty;
        }

        // WWHN-234 F-04 · Pha 2: khoá inventory_balances của BR-REV-03/04 theo thứ tự
        // sku_id toàn cục, không theo thứ tự line_no của Pha 1 — cùng lý do sắp thứ
        // tự với hàm sortReversalLinesForBalanceLock dùng trong postDraft() bên dưới.
        usort(
            $quantityDependencyChecks,
            fn (array $a, array $b): int => [$a['sku_id'], (string) $a['movement']->id] <=> [$b['sku_id'], (string) $b['movement']->id]
        );
        foreach ($quantityDependencyChecks as $check) {
            $this->assertQuantityDependency($check['movement'], $documentType, $check['qty']);
        }

        AuditLogger::record(
            action: 'CREATE_REVERSAL',
            objectType: 'reversal_document',
            objectId: $reversalId,
            actorId: $actorId,
            requestId: request()?->attributes->get('request_id'),
            reason: $reasonText,
            afterData: [
                'status' => 'DRAFT',
                'physical_return_status' => $documentType === 'issue' ? ($physicalConfirmed ? 'CONFIRMED' : 'PENDING_RETURN') : null,
                'original_document_id' => $document->id,
            ],
        );

        return $reversalId;
    }

    private function confirmPendingReturn(
        string $documentId,
        string $documentType,
        string $reversalId,
        array $payload,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash,
        int $expectedVersion
    ): array {
        if ($documentType !== 'issue' || ($payload['physical_return_confirmed'] ?? false) !== true) {
            ApiError::abort(422, 'PHYSICAL_RETURN_CONFIRMATION_REQUIRED', 'Pending return chỉ được Post khi physical_return_confirmed=true.');
        }

        $reversal = DB::table('reversal_document')->where('reversal_document_id', $reversalId)->lockForUpdate()->first();
        if ($reversal === null || (string) $reversal->original_document_id !== $documentId) {
            ApiError::abort(404, 'REVERSAL_NOT_FOUND', 'Không tìm thấy pending reversal của original document.');
        }
        $this->assertVersion($reversal, $expectedVersion, 'reversal document');
        if ($reversal->status !== 'DRAFT' || $reversal->physical_return_status !== 'PENDING_RETURN') {
            ApiError::abort(409, 'REVERSAL_NOT_PENDING_RETURN', 'Reversal không ở DRAFT/PENDING_RETURN.');
        }

        $document = $this->lockOriginalDocument($documentId, $documentType);
        if ($document->status !== 'POSTED') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Original document phải giữ POSTED.');
        }
        foreach (DB::table('reversal_line')->where('reversal_document_id', $reversalId)->lockForUpdate()->get() as $line) {
            if ($line->item_id !== null) {
                $this->assertItemDependency((string) $line->item_id, 'issue');
            }
        }

        DB::table('reversal_document')->where('reversal_document_id', $reversalId)->update([
            'physical_return_status' => 'CONFIRMED',
            'updated_by' => $actorId,
            'version' => $expectedVersion + 1,
            'updated_at' => now(),
        ]);

        $reversal->version = $expectedVersion + 1;

        return $this->postDraft($reversalId, $document, $documentType, $actorId, $idempotencyKey, $requestHash);
    }

    private function postDraft(
        string $reversalId,
        object $document,
        string $documentType,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        $reversal = DB::table('reversal_document')->where('reversal_document_id', $reversalId)->lockForUpdate()->first();
        if ($reversal === null || $reversal->status !== 'DRAFT') {
            ApiError::abort(409, 'REVERSAL_NOT_DRAFT', 'Reversal phải ở DRAFT trước khi Post.');
        }
        if ($documentType === 'issue' && $reversal->physical_return_status !== 'CONFIRMED') {
            ApiError::abort(409, 'PHYSICAL_RETURN_REQUIRED', 'Reverse Issue chưa xác nhận hàng về vật lý; stock effect = 0.');
        }

        $lines = DB::table('reversal_line')->where('reversal_document_id', $reversalId)->orderBy('line_no')->lockForUpdate()->get();
        if ($lines->isEmpty()) {
            ApiError::abort(422, 'REVERSAL_LINES_REQUIRED', 'Reversal DRAFT không có line.');
        }

        // WWHN-234 F-04: pha validate giữ nguyên thứ tự line_no (đã khoá ở trên) để
        // lỗi đầu tiên luôn xác định — tách khỏi thứ tự khoá inventory_balances ở
        // pha mutateBalance bên dưới, cùng cách postStockDocument()/postComponentIssue()
        // trong CurrentBaselineStockService đã làm cho hai action Post kia.
        $originalByLine = [];
        foreach ($lines as $line) {
            $original = DB::table('stock_movements')->where('id', $line->original_movement_id)->lockForUpdate()->first();
            if ($original === null || ! in_array($original->movement_type, ['RECEIPT', 'ISSUE'], true)) {
                ApiError::abort(409, 'REVERSAL_OF_REVERSAL_FORBIDDEN', 'Không reverse trực tiếp movement REVERSAL; phải correction từ movement nghiệp vụ gốc.');
            }

            $qty = (int) ($line->quantity_to_reverse ?? $line->reversal_qty);
            $postedElsewhere = (int) DB::table('reversal_line as rl')
                ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
                ->where('rl.original_movement_id', $original->id)
                ->where('rd.status', 'POSTED')
                ->where('rd.reversal_document_id', '!=', $reversalId)
                ->sum(DB::raw('COALESCE(rl.quantity_to_reverse, rl.reversal_qty)'));
            if ($postedElsewhere + $qty > abs((int) $original->quantity)) {
                ApiError::abort(409, ConflictCode::CONCURRENCY, 'Movement đã bị reversal bởi request cạnh tranh; reload reversible_qty.');
            }

            // Re-check dưới lock: hai DRAFT song song có thể cùng khai một item;
            // chỉ request Post trước mới được giữ hiệu lực (BR-REV-05 ở grain ITEM).
            // Thua race đúng nghĩa (WWHN-234 · lớp CONCURRENCY): client reload rồi thử
            // lại với CÙNG Idempotency-Key, không sinh key mới.
            if ($line->item_id !== null
                && $this->postedReversalLineForItem((string) $line->item_id, (string) $original->id, $reversalId) !== null) {
                ApiError::abort(409, ConflictCode::CONCURRENCY, 'Item đã được đảo bởi một reversal POSTED khác; reload reversible_qty.', [
                    'item_id' => (string) $line->item_id,
                    'original_movement_id' => (string) $original->id,
                ]);
            }

            $originalByLine[$line->reversal_line_id] = $original;
        }

        $movementIds = [];
        // WWHN-234 F-04: khoá inventory_balances theo thứ tự sku_id toàn cục của
        // movement gốc, không theo thứ tự line_no của reversal — hai reversal chạm
        // cùng tập SKU nhưng khai dòng ngược nhau vẫn khoá theo đúng một thứ tự.
        foreach ($this->sortReversalLinesForBalanceLock($lines, $originalByLine) as $line) {
            $original = $originalByLine[$line->reversal_line_id];
            $qty = (int) ($line->quantity_to_reverse ?? $line->reversal_qty);
            $sign = $documentType === 'receipt' ? -1 : 1;
            $stockStatus = (string) ($line->stock_status ?: self::AVAILABLE);
            $movementId = $this->mutateBalance(
                (string) $original->warehouse_id,
                (string) ($original->sku_id ?? $original->product_id),
                $qty,
                $sign,
                $stockStatus,
                $reversalId,
                (string) $line->reversal_line_id,
                (string) $original->id,
                (string) $reversal->correction_chain_id,
                $line->item_id ? (string) $line->item_id : null,
                $actorId
            );
            $movementIds[] = $movementId;

            DB::table('reversal_line')->where('reversal_line_id', $line->reversal_line_id)->update([
                'posted_movement_id' => $movementId,
                'version' => ((int) ($line->version ?? 1)) + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

            if ($line->item_id !== null) {
                DB::table('item_instances')->where('item_id', $line->item_id)->update([
                    'item_status' => $documentType === 'receipt' ? 'ADJUSTED_OUT' : 'IN_STOCK',
                    'item_condition' => $documentType === 'issue'
                        ? (($line->return_condition ?? 'SELLABLE') === 'SELLABLE' ? 'NORMAL' : $line->return_condition)
                        : DB::raw('item_condition'),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('reversal_document')->where('reversal_document_id', $reversalId)->update([
            'status' => 'POSTED',
            'posted_at' => now(),
            'posted_by' => $actorId,
            'updated_by' => $actorId,
            'version' => ((int) $reversal->version) + 1,
            'updated_at' => now(),
        ]);

        $this->recordPostingEvent($reversalId, $documentType === 'issue' ? 'REVERSE_ISSUE' : 'REVERSE_RECEIPT', $idempotencyKey, $requestHash, $movementIds, $actorId);
        AuditLogger::record(
            action: 'REVERSAL_POST',
            objectType: 'reversal_document',
            objectId: $reversalId,
            actorId: $actorId,
            requestId: request()?->attributes->get('request_id'),
            reason: $reversal->reason_text ?? $reversal->reason,
            afterData: ['status' => 'POSTED', 'movement_ids' => $movementIds, 'original_document_status' => 'POSTED'],
        );

        return [
            'original_document_id' => (string) $document->id,
            'original_document_status' => 'POSTED',
            'reversal_document_id' => $reversalId,
            'reversal_status' => 'POSTED',
            'physical_return_status' => $reversal->physical_return_status,
            'correction_chain_id' => (string) $reversal->correction_chain_id,
            'movement_ids' => $movementIds,
        ];
    }

    /**
     * WWHN-234 F-04: trả lại các dòng `reversal_line` đã `lockForUpdate()` theo thứ
     * tự `line_no`, nhưng SẮP LẠI theo sku_id/product_id của movement gốc (thứ tự
     * chuỗi ổn định, tie-break bằng `reversal_line_id`) chỉ để quyết định thứ tự gọi
     * `mutateBalance()`. Lock trên `reversal_line` đã lấy theo `line_no` ở trên và
     * KHÔNG đổi — hàm này chỉ đổi thứ tự khoá `inventory_balances` phía sau, cùng
     * nguyên lý với `CurrentBaselineStockService::sortLinesForBalanceLock()`.
     *
     * @param  array<string, object>  $originalByLine  keyed by reversal_line_id
     * @return list<object>
     */
    private function sortReversalLinesForBalanceLock(iterable $lines, array $originalByLine): array
    {
        $items = $lines instanceof \Illuminate\Support\Collection ? $lines->all() : [...$lines];
        usort($items, function (object $a, object $b) use ($originalByLine): int {
            $originalA = $originalByLine[$a->reversal_line_id];
            $originalB = $originalByLine[$b->reversal_line_id];
            $skuA = (string) ($originalA->sku_id ?? $originalA->product_id);
            $skuB = (string) ($originalB->sku_id ?? $originalB->product_id);

            return [$skuA, (string) $a->reversal_line_id] <=> [$skuB, (string) $b->reversal_line_id];
        });

        return $items;
    }

    private function mutateBalance(
        string $warehouseId,
        string $skuId,
        int $quantity,
        int $sign,
        string $stockStatus,
        string $reversalId,
        string $reversalLineId,
        string $originalMovementId,
        string $chainId,
        ?string $itemId,
        ?string $actorId
    ): string {
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
                ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Reversal receipt sẽ làm tồn âm.');
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
            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Reversal stock effect sẽ làm tồn âm.');
        }
        $availableAfter = (int) $balance->available_qty;
        if ($stockStatus === self::AVAILABLE) {
            $availableAfter += $sign * $quantity;
        }
        if ($availableAfter < 0) {
            ApiError::abort(422, 'INSUFFICIENT_AVAILABLE_STOCK', 'Reversal sẽ làm available_qty âm.');
        }

        $affected = DB::table('inventory_balances')->where('id', $balance->id)->where('version', $balance->version)->update([
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'quantity' => $after,
            'on_hand_qty' => $after,
            'available_qty' => $availableAfter,
            'version' => ((int) $balance->version) + 1,
            'updated_at' => now(),
        ]);
        if ($affected !== 1) {
            ApiError::abort(409, ConflictCode::CONCURRENCY, 'Balance thay đổi đồng thời; retry sau reload.');
        }

        $movementId = (string) Str::uuid();
        DB::table('stock_movements')->insert([
            'id' => $movementId,
            'movement_no' => 'MV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'business_key' => sha1('REVERSAL|'.$reversalId.'|'.$reversalLineId),
            'movement_type' => 'REVERSAL',
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'item_id' => $itemId,
            'stock_status' => $stockStatus,
            'quantity' => $quantity,
            'qty_before' => $before,
            'qty_delta' => $sign * $quantity,
            'qty_after' => $after,
            'source_type' => 'REVERSAL_DOCUMENT',
            'source_id' => $reversalId,
            'source_line_id' => $reversalLineId,
            'original_movement_id' => $originalMovementId,
            'correction_chain_id' => $chainId,
            'created_by' => $actorId,
            'request_id' => request()?->attributes->get('request_id'),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return $movementId;
    }

    private function assertItemBelongsToOriginal(string $itemId, string $originalLineId, string $documentType, string $skuId): void
    {
        $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
        if ($item === null || (string) $item->sku_id !== $skuId) {
            ApiError::abort(422, 'ITEM_SKU_MISMATCH', 'item_id không thuộc SKU movement gốc.');
        }

        $exists = $documentType === 'receipt'
            ? DB::table('inbound_scan_entries')->where('inbound_line_id', $originalLineId)->where('item_id', $itemId)->exists()
            : DB::table('outbound_item_matches')->where('outbound_line_id', $originalLineId)->where('item_id', $itemId)->exists();
        if (! $exists) {
            ApiError::abort(422, 'ITEM_NOT_IN_ORIGINAL_TRANSACTION', 'item_id không thuộc line giao dịch gốc.');
        }
    }

    /**
     * BR-REV-03/BR-REV-04 cho SKU quantity-mode (linh kiện).
     *
     * SKU serial-tracked chặn theo trạng thái từng Item; SKU tính theo số lượng không có
     * item nên "downstream dependency" được đo bằng tồn còn lại của bucket: nếu tồn khả
     * dụng đã thấp hơn lượng cần đảo thì đúng bằng phần đó đã bị Issue/Component Issue
     * tiêu thụ sau khi nhập, và phải đảo dependency theo thứ tự ngược trước.
     *
     * Trả 409 DOWNSTREAM_DEPENDENCY_EXISTS theo §8.2, không phải 422 INSUFFICIENT_STOCK:
     * đây là lỗi thứ tự nghiệp vụ chứ không phải lỗi dữ liệu nhập.
     */
    private function assertQuantityDependency(object $movement, string $documentType, int $qty): void
    {
        if ($documentType !== 'receipt') {
            return;
        }

        $stockStatus = (string) ($movement->stock_status ?: self::AVAILABLE);
        $skuId = (string) ($movement->sku_id ?? $movement->product_id);

        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $movement->warehouse_id)
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->where('stock_status', $stockStatus)
            ->lockForUpdate()
            ->first();

        $available = (int) ($balance->available_qty ?? 0);
        if ($available < $qty) {
            ApiError::abort(409, 'DOWNSTREAM_DEPENDENCY_EXISTS', 'Số lượng đã nhập đã bị tiêu thụ bởi giao dịch sau đó; phải đảo dependency theo thứ tự ngược trước.', [
                'movement_id' => (string) $movement->id,
                'stock_status' => $stockStatus,
                'available_qty' => $available,
                'requested_qty' => $qty,
            ]);
        }
    }

    private function assertItemDependency(string $itemId, string $documentType): void
    {
        $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
        if ($item === null) {
            ApiError::abort(422, 'ITEM_NOT_FOUND', 'Không tìm thấy item reversal.');
        }

        if ($documentType === 'receipt' && ! in_array($item->item_status, ['IN_STOCK', 'NEW'], true)) {
            ApiError::abort(409, ConflictCode::DOWNSTREAM_DEPENDENCY, 'Receipt item không còn ở trạng thái IN_STOCK/NEW (đã Issue/Warranty hoặc đã đảo); phải đảo dependency theo thứ tự ngược.');
        }
        if ($documentType === 'issue' && ! in_array($item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)) {
            ApiError::abort(409, ConflictCode::DOWNSTREAM_DEPENDENCY, 'Issue item đang ở downstream state không thể nhận return trực tiếp.');
        }
    }

    /**
     * BR-REV-05 / BR-REV-08 ở grain ITEM (WWHN-239 · DEV239-P1-04 "duplicate movement = 0").
     *
     * CurrentBaselineStockService::postStockDocument ghi MỘT movement cho mỗi
     * stock_document_line với quantity = qty_planned, nên một line serial-tracked
     * gồm N item chỉ sinh một movement quantity=N. Guard reversible_qty tính theo
     * tổng quantity vì thế KHÔNG chặn được việc khai cùng một item_id nhiều lần
     * trong hạn mức N đó. Một item vật lý chỉ tồn tại một lần: mỗi item chỉ được
     * đảo đúng một lần trên một movement gốc, nếu không ledger sinh hai movement
     * REVERSAL cho cùng một serial và balance bị trừ/cộng hai lần.
     *
     * Guard này là điều kiện enforcement đứng sau preflight
     * `GET /stock-movements/reversible` (StockMovementController::buildItemCandidateMap
     * chỉ chào item IN_STOCK/NEW); backend phải là nguồn chuẩn, không phụ thuộc UI.
     *
     * @param  array<string, bool>  $stagedItemIds
     */
    private function assertItemNotAlreadyReversed(string $itemId, string $movementId, array $stagedItemIds): void
    {
        if (isset($stagedItemIds[$itemId])) {
            ApiError::abort(422, 'REVERSAL_ITEM_DUPLICATED', 'Cùng một item không được khai nhiều dòng trong một reversal.', [
                'item_id' => $itemId,
            ]);
        }

        $existing = $this->postedReversalLineForItem($itemId, $movementId, null);
        if ($existing !== null) {
            ApiError::abort(409, 'REVERSAL_ITEM_ALREADY_REVERSED', 'Item này đã được đảo trong một reversal POSTED; append-only không cho đảo lại cùng một item (BR-REV-08).', [
                'item_id' => $itemId,
                'original_movement_id' => $movementId,
                'posted_reversal_document_id' => (string) $existing->reversal_document_id,
            ]);
        }
    }

    /**
     * Dòng reversal đã POSTED của đúng cặp (item, movement gốc), bỏ qua chính
     * reversal đang xử lý. Trả null nghĩa là item còn nguyên quyền đảo.
     */
    private function postedReversalLineForItem(string $itemId, string $movementId, ?string $excludeReversalId): ?object
    {
        $query = DB::table('reversal_line as rl')
            ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
            ->where('rl.item_id', $itemId)
            ->where('rl.original_movement_id', $movementId)
            ->where('rd.status', 'POSTED');

        if ($excludeReversalId !== null) {
            $query->where('rd.reversal_document_id', '!=', $excludeReversalId);
        }

        return $query->first(['rl.reversal_line_id', 'rl.reversal_document_id']);
    }

    private function returnCondition(string $documentType, array $payload): string
    {
        if ($documentType === 'receipt') {
            return 'SELLABLE';
        }
        $condition = strtoupper((string) ($payload['condition_status'] ?? ''));
        if (! in_array($condition, ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], true)) {
            ApiError::abort(422, 'RETURN_CONDITION_REQUIRED', 'Reverse Issue bắt buộc condition_status SELLABLE/DEFECTIVE/NON_SELLABLE.');
        }

        return $condition;
    }

    private function stockStatusForCondition(string $condition): string
    {
        return match ($condition) {
            'DEFECTIVE' => 'DEFECTIVE',
            'NON_SELLABLE' => 'NON_SELLABLE',
            default => self::AVAILABLE,
        };
    }

    /**
     * SRS §6 bước "CONFIRMED": xác nhận hàng vật lý đã quay lại kho (BR-REV-06).
     * ERD bảng 33 giữ status ∈ DRAFT/POSTED/CANCELLED nên trạng thái xác nhận được
     * biểu diễn bằng physical_return_status, không phải bằng status.
     *
     * @return array<string, mixed>
     */
    public function confirmPhysicalReturn(string $reversalId, ?string $actorId, int $expectedVersion, ?string $returnCondition = null): array
    {
        return DB::transaction(function () use ($reversalId, $actorId, $expectedVersion, $returnCondition): array {
            $reversal = $this->lockReversal($reversalId);
            $this->assertVersion($reversal, $expectedVersion, 'reversal document');
            if ((string) $reversal->status !== 'DRAFT') {
                ApiError::abort(409, 'REVERSAL_NOT_DRAFT', 'Chỉ reversal DRAFT mới xác nhận hàng về.');
            }
            if ((string) ($reversal->physical_return_status ?? '') === 'CONFIRMED') {
                return $this->reversalSnapshot($reversalId);
            }
            if ((string) ($reversal->physical_return_status ?? '') !== 'PENDING_RETURN') {
                ApiError::abort(409, 'REVERSAL_NOT_PENDING_RETURN', 'Reversal không ở trạng thái chờ hàng về.');
            }

            DB::table('reversal_document')->where('reversal_document_id', $reversalId)->update([
                'physical_return_status' => 'CONFIRMED',
                'condition_status' => $returnCondition ?? $reversal->condition_status,
                'version' => $expectedVersion + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'REVERSAL_PHYSICAL_RETURN_CONFIRMED',
                objectType: 'reversal_document',
                objectId: $reversalId,
                actorId: $actorId,
                afterData: ['physical_return_status' => 'CONFIRMED', 'stock_effect' => 'NONE'],
            );

            return $this->reversalSnapshot($reversalId);
        }, 3);
    }

    /**
     * SRS §6 bước "POSTED" — Annex C postReversal. Điểm duy nhất phát sinh
     * movement/stock effect của luồng Reversal.
     *
     * @return array<string, mixed>
     */
    public function postReversalDocument(string $reversalId, ?string $actorId, string $idempotencyKey, string $requestHash, int $expectedVersion): array
    {
        return DB::transaction(function () use ($reversalId, $actorId, $idempotencyKey, $requestHash, $expectedVersion): array {
            $reversal = $this->lockReversal($reversalId);
            $this->assertVersion($reversal, $expectedVersion, 'reversal document');
            if ((string) $reversal->status !== 'DRAFT') {
                ApiError::abort(409, 'REVERSAL_NOT_DRAFT', 'Reversal phải ở DRAFT trước khi Post.');
            }

            $documentType = strtoupper((string) $reversal->original_document_type) === 'INBOUND' ? 'receipt' : 'issue';
            $document = $this->lockOriginalDocument((string) $reversal->original_document_id, $documentType);

            return $this->postDraft($reversalId, $document, $documentType, $actorId, $idempotencyKey, $requestHash);
        }, 3);
    }

    /**
     * SRS §6 bước "CANCELLED": chỉ huỷ được DRAFT; POSTED là append-only
     * (BR-REV-08) nên phải sửa bằng reversal mới trong cùng chain.
     *
     * @return array<string, mixed>
     */
    public function cancelReversalDocument(string $reversalId, ?string $actorId, int $expectedVersion, string $reason): array
    {
        return DB::transaction(function () use ($reversalId, $actorId, $expectedVersion, $reason): array {
            $reversal = $this->lockReversal($reversalId);
            $this->assertVersion($reversal, $expectedVersion, 'reversal document');
            if ((string) $reversal->status === 'POSTED') {
                ApiError::abort(409, 'REVERSAL_POSTED_IMMUTABLE', 'Reversal POSTED là append-only; tạo reversal mới trong cùng chain (BR-REV-08).');
            }
            if ((string) $reversal->status !== 'DRAFT') {
                ApiError::abort(409, 'REVERSAL_NOT_DRAFT', 'Chỉ reversal DRAFT mới huỷ được.');
            }

            DB::table('reversal_document')->where('reversal_document_id', $reversalId)->update([
                'status' => 'CANCELLED',
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
                'version' => $expectedVersion + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'REVERSAL_CANCELLED',
                objectType: 'reversal_document',
                objectId: $reversalId,
                actorId: $actorId,
                afterData: ['status' => 'CANCELLED', 'reason' => $reason, 'stock_effect' => 'NONE'],
            );

            return $this->reversalSnapshot($reversalId);
        }, 3);
    }

    private function lockReversal(string $reversalId): object
    {
        $reversal = DB::table('reversal_document')->where('reversal_document_id', $reversalId)->lockForUpdate()->first();
        if ($reversal === null) {
            ApiError::abort(404, 'REVERSAL_NOT_FOUND', 'Không tìm thấy reversal document.');
        }

        return $reversal;
    }

    /** @return array<string, mixed> */
    private function reversalSnapshot(string $reversalId): array
    {
        $reversal = DB::table('reversal_document')->where('reversal_document_id', $reversalId)->first();

        return [
            'reversal_document_id' => $reversalId,
            'reversal_status' => (string) ($reversal->status ?? ''),
            'physical_return_status' => $reversal->physical_return_status ?? null,
            'condition_status' => $reversal->condition_status ?? null,
            'original_document_id' => (string) ($reversal->original_document_id ?? ''),
            'version' => (int) ($reversal->version ?? 0),
            'stock_effect' => 'NONE',
            'movement_ids' => [],
        ];
    }

    private function lockOriginalDocument(string $documentId, string $documentType): object
    {
        $document = DB::table('stock_documents')
            ->where('id', $documentId)
            ->where('doc_type', $documentType)
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy original document.');
        }

        return $document;
    }

    private function assertVersion(object $row, int $expected, string $resource): void
    {
        if ((int) ($row->version ?? 0) !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', ucfirst($resource).' version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) ($row->version ?? 0),
            ]);
        }
    }

    private function recordPostingEvent(string $reversalId, string $postingAction, string $key, string $hash, array $movementIds, ?string $actorId): void
    {
        if (! DB::getSchemaBuilder()->hasTable('posting_event') && ! DB::getSchemaBuilder()->hasView('posting_event')) {
            return;
        }
        DB::table('posting_event')->insert([
            'posting_event_id' => (string) Str::uuid(),
            'operation' => 'reverse_'.$reversalId,
            // posting_action (ERD bảng 31, varchar(50)) là mã ngắn cố định — xem
            // WWHN-190/191 evidence + migration 2026_08_18_150000/150100.
            'posting_action' => $postingAction,
            'idempotency_key' => $key,
            // ERD bảng 31: request_hash char(64).
            'request_hash' => CurrentBaselineStockService::postingRequestDigest($hash),
            'status' => 'COMMITTED',
            'document_id' => $reversalId,
            'movement_id' => $movementIds[0] ?? null,
            'response_snapshot' => json_encode(['reversal_document_id' => $reversalId, 'movement_ids' => $movementIds], JSON_UNESCAPED_UNICODE),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }
}
