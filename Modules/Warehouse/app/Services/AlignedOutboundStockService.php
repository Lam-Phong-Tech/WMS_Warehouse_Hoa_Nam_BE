<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * Current business baseline for PRODUCT outbound:
 * - every unit must be matched to a concrete item;
 * - RFID is OPTIONAL metadata and never a Post Issue gate;
 * - stock changes only at Post Issue, after full scan + Packing Label gate.
 *
 * Receipt/component/reversal behaviour stays in CurrentBaselineStockService.
 */
class AlignedOutboundStockService extends CurrentBaselineStockService
{
    private const AVAILABLE = 'AVAILABLE';

    public function postStockDocument(
        string $documentId,
        string $documentType,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        if ($documentType !== 'issue') {
            return parent::postStockDocument($documentId, $documentType, $actorId, $idempotencyKey, $requestHash);
        }

        return DB::transaction(function () use ($documentId, $actorId, $idempotencyKey, $requestHash): array {
            $document = $this->lockIssueDocument($documentId);
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
                $this->assertProductItemsMatched($line);
            }

            $movementIds = [];
            foreach ($lines as $line) {
                if ($document->src_warehouse_id === null) {
                    ApiError::abort(422, 'WAREHOUSE_REQUIRED', 'Phiếu xuất thiếu kho nguồn.');
                }
                $movement = $this->mutateIssueBalance(
                    (string) $document->src_warehouse_id,
                    (string) $line->product_id,
                    (int) $line->qty_planned,
                    $documentId,
                    (string) $line->id,
                    $actorId,
                    sha1('ISSUE|'.$documentId.'|'.$line->id),
                    $this->resolveLineItemId((string) $line->id),
                );
                $movementIds[] = $movement['id'];
            }

            $this->applyIssueSubledger($documentId);

            DB::table('stock_documents')->where('id', $documentId)->update([
                'status' => 'POSTED',
                'posted_by' => $actorId,
                'posted_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordDocumentStatus($documentId, 'SCANNING', 'POSTED', 'post_issue', $actorId);
            $this->recordPostingEvent(
                'post_issue_'.$documentId,
                'POST_ISSUE',
                $idempotencyKey,
                $requestHash,
                $documentId,
                $movementIds[0] ?? null,
                $actorId,
                ['document_id' => $documentId, 'status' => 'POSTED', 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: 'POST_ISSUE',
                objectType: 'stock_document',
                objectId: $documentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                beforeData: ['status' => 'SCANNING'],
                afterData: [
                    'status' => 'POSTED',
                    'movement_ids' => $movementIds,
                    'rfid_gate' => 'OPTIONAL',
                ],
            );

            return [
                'document_id' => $documentId,
                'status' => 'POSTED',
                'movement_ids' => $movementIds,
                'stock_effect' => 'DECREASE',
                'rfid_required' => false,
            ];
        }, 3);
    }

    private function assertProductItemsMatched(object $line): void
    {
        $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
        if ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
            return;
        }
        $count = DB::table('outbound_item_matches')
            ->where('outbound_line_id', $line->id)
            ->whereNotNull('item_id')
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
            })
            ->count();
        if ($count !== (int) $line->qty_planned) {
            ApiError::abort(422, 'PRODUCT_ITEM_MATCH_REQUIRED', 'Mỗi PRODUCT unit phải được match bằng item cụ thể trước Post Issue.', [
                'line_id' => (string) $line->id,
                'required_qty' => (int) $line->qty_planned,
                'matched_items' => $count,
            ]);
        }
    }

    private function mutateIssueBalance(
        string $warehouseId,
        string $skuId,
        int $quantity,
        string $documentId,
        string $lineId,
        ?string $actorId,
        string $businessKey,
        ?string $itemId
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
            ->where('stock_status', self::AVAILABLE)
            ->lockForUpdate()
            ->first();
        if ($balance === null) {
            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Không đủ tồn cho stock effect.');
        }

        $before = (int) $balance->quantity;
        $after = $before - $quantity;
        $availableBefore = (int) $balance->available_qty;
        $availableAfter = $availableBefore - $quantity;
        if ($after < 0 || $availableAfter < 0) {
            ApiError::abort(422, 'INSUFFICIENT_AVAILABLE_STOCK', 'Post Issue sẽ làm tồn/available_qty âm.');
        }

        $updated = DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->where('version', $balance->version)
            ->update([
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
            'movement_type' => 'ISSUE',
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => self::AVAILABLE,
            'quantity' => $quantity,
            'qty_before' => $before,
            'qty_delta' => -$quantity,
            'qty_after' => $after,
            'stock_document_id' => $documentId,
            'stock_document_line_id' => $lineId,
            'source_type' => 'STOCK_DOCUMENT',
            'source_id' => $documentId,
            'source_line_id' => $lineId,
            'item_id' => $itemId,
            'created_by' => $actorId,
            'request_id' => request()?->attributes->get('request_id'),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return ['id' => $movementId, 'before' => $before, 'after' => $after];
    }

    private function resolveLineItemId(string $lineId): ?string
    {
        $itemIds = DB::table('outbound_item_matches')
            ->where('outbound_line_id', $lineId)
            ->whereNotNull('item_id')
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
            })
            ->pluck('item_id');

        return $itemIds->count() === 1 ? (string) $itemIds->first() : null;
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
        if ($activeMatches->isNotEmpty()) {
            DB::table('outbound_item_matches')
                ->whereIn('id', $activeMatches->pluck('id'))
                ->update(['status' => 'POSTED', 'updated_at' => now()]);
        }
    }

    private function lockIssueDocument(string $documentId): object
    {
        $document = DB::table('stock_documents')
            ->where('id', $documentId)
            ->where('doc_type', 'issue')
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất.');
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
        string $postingAction,
        string $idempotencyKey,
        string $requestHash,
        string $documentId,
        ?string $movementId,
        ?string $actorId,
        array $snapshot
    ): void {
        if (! Schema::hasTable('posting_event') && ! Schema::hasView('posting_event')) {
            return;
        }
        DB::table('posting_event')->insert([
            'posting_event_id' => (string) Str::uuid(),
            'operation' => $operation,
            // posting_action (ERD bảng 31, varchar(50)) là mã ngắn cố định, không
            // fallback từ $operation (có thể vượt 50 ký tự vì nhúng UUID — xem
            // WWHN-190/191 evidence + migration 2026_08_18_150000).
            'posting_action' => $postingAction,
            'idempotency_key' => $idempotencyKey,
            // ERD bảng 31: request_hash char(64).
            'request_hash' => self::postingRequestDigest($requestHash),
            'status' => 'COMMITTED',
            'document_id' => $documentId,
            'movement_id' => $movementId,
            'response_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }
}
