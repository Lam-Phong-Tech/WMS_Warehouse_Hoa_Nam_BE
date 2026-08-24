<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic extension of the CURRENT aligned outbound stock service.
 *
 * Important: this class must inherit AlignedOutboundStockService, not the older
 * CurrentBaselineStockService directly. That preserves the current PRODUCT
 * outbound semantics (full match, RFID optional, stock changes only at Post
 * Issue) while enriching ITEM_ALREADY_MATCHED with the exact active document.
 */
class DiagnosticCurrentBaselineStockService extends AlignedOutboundStockService
{
    private const ACTIVE_OUTBOUND_STATUSES = ['DRAFT', 'SCANNING'];

    public function scanStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId
    ): array {
        if ($documentType === 'issue') {
            $itemId = $this->resolveExistingItemIdForPayload($payload);
            if ($itemId !== null) {
                $this->assertNoActiveOutboundReservation($documentId, $itemId);
            }
        }

        return parent::scanStockDocument($documentId, $documentType, $payload, $actorId);
    }

    private function assertNoActiveOutboundReservation(string $documentId, string $itemId): void
    {
        // Only enrich conflicts inside the current source warehouse so the error
        // does not disclose metadata from another warehouse data scope. Parent
        // canonical guards remain the last line of defense for races/conflicts.
        $currentWarehouseId = DB::table('stock_documents')
            ->where('id', $documentId)
            ->where('doc_type', 'issue')
            ->value('src_warehouse_id');

        $conflictQuery = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('m.item_id', $itemId)
            ->where('d.id', '!=', $documentId)
            ->where('d.doc_type', 'issue')
            ->whereIn('d.status', self::ACTIVE_OUTBOUND_STATUSES)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            });

        if ($currentWarehouseId !== null) {
            $conflictQuery->where('d.src_warehouse_id', $currentWarehouseId);
        }

        $conflict = $conflictQuery
            ->select([
                'm.id as match_id',
                'd.id as document_id',
                'd.doc_no',
                'd.status',
                'd.outbound_request_id',
            ])
            ->first();

        if ($conflict === null) {
            return;
        }

        $documentNo = trim((string) ($conflict->doc_no ?? ''));
        $displayDocument = $documentNo !== ''
            ? $documentNo
            : (string) $conflict->document_id;
        $status = strtoupper((string) $conflict->status);

        ApiError::abort(
            409,
            'ITEM_ALREADY_MATCHED',
            "Item đang được giữ bởi phiếu xuất {$displayDocument} ({$status}). Hãy gỡ match, hủy hoặc hoàn tất phiếu đó trước khi quét item vào phiếu hiện tại.",
            [
                'item_id' => $itemId,
                'conflicting_document_id' => (string) $conflict->document_id,
                'conflicting_document_no' => $documentNo !== '' ? $documentNo : null,
                'conflicting_document_status' => $status,
                'conflicting_outbound_request_id' => $conflict->outbound_request_id !== null
                    ? (string) $conflict->outbound_request_id
                    : null,
                'conflicting_match_id' => (string) $conflict->match_id,
            ]
        );
    }
}
