<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\StockDocument;

/**
 * Thin adapter for Warehouse Mini App outbound.
 *
 * No second outbound state machine is introduced. Outbound documents are still
 * created only from the canonical outbound-request confirm flow. Mini App only
 * consumes that queue and delegates every mutation to the canonical handlers:
 * scan -> full match -> Packing Label -> apply -> Post Issue.
 *
 * Only Post Issue decreases inventory. Scan/label/cancel-before-post remain
 * stock-neutral exactly as Web WMS.
 */
class MiniAppOutboundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CurrentBaselineDocumentController $documents,
        private readonly AlignedOutboundFlowController $flow,
        private readonly CurrentBaselineOperationsController $operations,
    ) {}

    public function index(Request $request)
    {
        $status = strtoupper(trim((string) $request->query('status', '')));
        if ($status !== '' && ! in_array($status, ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'status không hợp lệ cho danh sách phiếu xuất Mini App.');
        }

        $readyForIssue = $this->booleanQuery($request, 'ready_for_issue');
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', StockDocument::TYPE_ISSUE);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($readyForIssue === true) {
            $query->where('status', StockDocument::STATUS_SCANNING)
                ->whereHas('lines')
                ->whereDoesntHave('lines', fn ($line) => $line->whereColumn('scanned_qty', '<>', 'qty_planned'));
        } elseif ($readyForIssue === false) {
            $query->where(function ($outer): void {
                $outer->where('status', '<>', StockDocument::STATUS_SCANNING)
                    ->orWhereDoesntHave('lines')
                    ->orWhereHas('lines', fn ($line) => $line->whereColumn('scanned_qty', '<>', 'qty_planned'));
            });
        }

        $user = $request->user();
        if ($user !== null && method_exists($user, 'warehouseScopeIds')) {
            $warehouseScopeIds = $user->warehouseScopeIds();
            if (is_array($warehouseScopeIds)) {
                $query->whereIn('src_warehouse_id', $warehouseScopeIds);
            }
        }

        $this->applyKeyword($query, $request, ['doc_no', 'recipient_name', 'recipient_address', 'note']);

        $page = $query->orderByDesc('created_at')->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(fn (StockDocument $document) => $this->decorateDocument($document)));

        return $this->respondPaginated($page);
    }

    public function show(string $id)
    {
        $document = StockDocument::query()
            ->with(['lines', 'movements', 'statusHistory'])
            ->where('doc_type', StockDocument::TYPE_ISSUE)
            ->find($id);

        if ($document === null) {
            return $this->respondNotFound('Không tìm thấy phiếu xuất.');
        }

        $payload = $this->decorateDocument($document);
        $payload['item_matches'] = $this->scanEntryQuery($id, 'ALL')->get()->map(fn (object $row) => (array) $row)->values()->all();

        return $this->respondSuccess($payload);
    }

    public function cancel(Request $request, string $id)
    {
        return $this->documents->cancelOutbound($request, $id);
    }

    /** Camera Mini App may send SCANNER as a friendly alias of canonical HARDWARE. */
    public function scan(Request $request, string $id)
    {
        if (strtoupper(trim((string) $request->input('scan_source', ''))) === 'SCANNER') {
            $request->merge(['scan_source' => 'HARDWARE']);
        }

        return $this->flow->outboundScan($request, $id);
    }

    public function unmatch(Request $request, string $id)
    {
        return $this->flow->outboundUnmatch($request, $id);
    }

    public function scanEntries(Request $request, string $id)
    {
        $this->requireDocument($id);
        $status = strtoupper(trim((string) $request->query('status', 'ACTIVE')));
        if (! in_array($status, ['ACTIVE', 'REMOVED', 'ALL'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'status scan-entry chỉ nhận ACTIVE, REMOVED hoặc ALL.');
        }

        $page = $this->scanEntryQuery($id, $status)
            ->orderByDesc('m.created_at')
            ->paginate($this->perPage($request, 50));

        return $this->respondPaginated($page);
    }

    public function packingLabel(string $id)
    {
        $this->requireDocument($id);
        $label = $this->labelForDocument($id);

        if ($label === null) {
            ApiError::abort(404, 'LABEL_NOT_FOUND', 'Phiếu xuất chưa có Packing Label. Chỉ full match mới tạo Packing Label.');
        }

        return $this->respondSuccess($label);
    }

    public function previewPackingLabel(Request $request, string $id, string $labelId)
    {
        return $this->flow->previewPackingLabel($request, $id, $labelId);
    }

    public function readyPackingLabel(Request $request, string $id, string $labelId)
    {
        return $this->flow->readyPackingLabel($request, $id, $labelId);
    }

    public function printPackingLabel(Request $request, string $id, string $labelId)
    {
        return $this->operations->printPackingLabelForDocument($request, $id, $labelId);
    }

    public function reprintPackingLabel(Request $request, string $id, string $labelId)
    {
        return $this->operations->reprintPackingLabelForDocument($request, $id, $labelId);
    }

    public function applyPackingLabel(Request $request, string $id, string $labelId)
    {
        $belongs = DB::table('label_snapshots')
            ->where('id', $labelId)
            ->where('outbound_document_id', $id)
            ->exists();
        if (! $belongs) {
            ApiError::abort(404, 'LABEL_NOT_FOUND', 'Không tìm thấy Packing Label của phiếu xuất này.');
        }

        return $this->flow->applyPackingLabel($request, $labelId);
    }

    /** The only inventory-decreasing action in the Mini App outbound adapter. */
    public function postIssue(Request $request, string $id)
    {
        return $this->flow->postIssue($request, $id, bypassPackingLabelGate: true);
    }

    private function requireDocument(string $id): object
    {
        $document = DB::table('stock_documents')
            ->where('id', $id)
            ->where('doc_type', 'issue')
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất.');
        }

        return $document;
    }

    private function decorateDocument(StockDocument $document): array
    {
        $lines = $document->relationLoaded('lines') ? $document->lines : $document->lines()->get();
        $required = (int) $lines->sum('qty_planned');
        $scanned = (int) $lines->sum('scanned_qty');
        $fullScan = $lines->isNotEmpty()
            && $lines->every(fn ($line): bool => (int) $line->qty_planned > 0 && (int) $line->qty_planned === (int) $line->scanned_qty);
        $label = $this->labelForDocument((string) $document->id);
        $readyForIssue = (string) $document->status === StockDocument::STATUS_SCANNING
            && $fullScan;

        return [
            ...$document->toArray(),
            'required_total_qty' => $required,
            'scanned_total_qty' => $scanned,
            'remaining_qty' => max($required - $scanned, 0),
            'progress_percent' => $required > 0 ? round(min($scanned / $required, 1) * 100, 2) : 0,
            'full_scan' => $fullScan,
            'ready_for_issue' => $readyForIssue,
            'mini_app_status' => $this->miniAppStatus((string) $document->status, $fullScan, $label),
            'packing_label' => $label,
            'rfid_required' => false,
        ];
    }

    private function miniAppStatus(string $status, bool $fullScan, ?array $label): string
    {
        if ($status === StockDocument::STATUS_POSTED) {
            return 'COMPLETED';
        }
        if ($status === StockDocument::STATUS_CANCELLED) {
            return 'CANCELLED';
        }
        if (! $fullScan) {
            return 'PICKING';
        }

        return 'READY_TO_ISSUE';
    }

    private function labelForDocument(string $documentId): ?array
    {
        $label = DB::table('label_snapshots')
            ->where('outbound_document_id', $documentId)
            ->orderByDesc('created_at')
            ->first();
        if ($label === null) {
            return null;
        }

        $payload = json_decode((string) ($label->label_payload ?? '{}'), true);
        if (! is_array($payload)) {
            $payload = [];
        }

        return [
            'id' => (string) $label->id,
            'version' => (int) ($label->version ?? 0),
            'status' => (string) $label->status,
            'applied' => $label->applied_at !== null,
            'applied_at' => $label->applied_at,
            'reprint_count' => (int) ($label->reprint_count ?? 0),
            'label_code' => $payload['label_code'] ?? null,
            'outbound_no' => $payload['outbound_no'] ?? null,
            'source_request_no' => $payload['source_request_no'] ?? null,
            'recipient_name' => $payload['recipient_name'] ?? null,
            'recipient_address' => $payload['recipient_address'] ?? null,
            'customer_phone' => $payload['customer_phone'] ?? null,
            'item_count' => (int) ($payload['item_count'] ?? 0),
            'sku_count' => (int) ($payload['sku_count'] ?? 0),
            'sku_summary' => is_array($payload['sku_summary'] ?? null) ? $payload['sku_summary'] : [],
        ];
    }

    private function scanEntryQuery(string $documentId, string $status)
    {
        $columns = [
            'm.id',
            'm.outbound_line_id as line_id',
            'm.item_id',
            'm.container_id',
            'm.quantity',
            'm.status',
            'm.matched_at',
            'm.removed_at',
            'l.line_no',
            'l.product_id as sku_id',
            's.sku_code',
            's.sku_name',
            'i.item_code',
            'i.serial_number',
        ];

        $query = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'm.item_id')
            ->where('l.stock_document_id', $documentId);

        $hasScanEvent = Schema::hasColumn('outbound_item_matches', 'scan_event_id')
            && (Schema::hasTable('scan_event') || Schema::hasView('scan_event'));
        if ($hasScanEvent) {
            $query->leftJoin('scan_event as se', 'se.scan_event_id', '=', 'm.scan_event_id');
            if (Schema::hasColumn('scan_event', 'raw_code')) {
                $columns[] = 'se.raw_code';
            } else {
                $columns[] = DB::raw('NULL as raw_code');
            }
            if (Schema::hasColumn('scan_event', 'scan_source')) {
                $columns[] = 'se.scan_source';
            } else {
                $columns[] = DB::raw('NULL as scan_source');
            }
        } else {
            $columns[] = DB::raw('NULL as raw_code');
            $columns[] = DB::raw('NULL as scan_source');
        }

        if ($status === 'ACTIVE') {
            $query->where(function ($nested): void {
                $nested->whereNull('m.status')->orWhere('m.status', '<>', 'REMOVED');
            });
        } elseif ($status === 'REMOVED') {
            $query->where('m.status', 'REMOVED');
        }

        return $query->select($columns);
    }

    private function booleanQuery(Request $request, string $key): ?bool
    {
        if (! $request->has($key)) {
            return null;
        }
        $value = filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            ApiError::abort(422, 'VALIDATION_ERROR', "{$key} phải là true hoặc false.");
        }

        return $value;
    }
}
