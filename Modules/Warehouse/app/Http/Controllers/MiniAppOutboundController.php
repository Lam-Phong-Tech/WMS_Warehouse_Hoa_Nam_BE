<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Database\SchemaCache;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Support\P12DocumentReadContract;

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
    use CachesReadPayload;

    /**
     * Máy quét đọc dữ liệu này nên dùng TTL ngắn nhất ('stock_short' = 20s) và bám vào
     * `stock:version`. Mọi lệnh ghi ở đây (scan/unmatch/label/post-issue) đều uỷ quyền
     * sang controller khác nhưng vẫn bump ngay sau khi lệnh đó trả về — xem
     * invalidateAfterWrite().
     */
    private const CACHE_VERSION_NS = 'stock:version';

    /** @var array<string, array<string, mixed>|null> */
    private array $warehouseStateCache = [];

    public function __construct(
        private readonly CurrentBaselineDocumentController $documents,
        private readonly AlignedOutboundFlowController $flow,
        private readonly CurrentBaselineOperationsController $operations,
    ) {}

    public function index(Request $request)
    {
        // Validate before a read-cache hit; retain the existing permission/scope middleware.
        P12DocumentReadContract::validateList($request);

        // Kiểm tra tham số TRƯỚC cache: status sai phải trả 422 ngay.
        $status = strtoupper(trim((string) $request->query('status', '')));
        if ($status !== '' && ! in_array($status, ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'status không hợp lệ cho danh sách phiếu xuất Mini App.');
        }

        $readyForIssue = $this->booleanQuery($request, 'ready_for_issue');
        $perPage = $this->perPage($request);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // TTL 'stock_short' (20s): tiến độ pick/match đổi liên tục. Warehouse-scope đã nằm
        // sẵn trong khoá qua readCacheScopeIds(); phân trang chạy TRONG SQL nên page/
        // per_page cũng phải nằm trong khoá.
        $cached = $this->cachedReadPayload(
            'miniapp:outbound:list:p12-read-v1',
            $this->readFiltersWithPaging($request),
            function () use ($request, $status, $readyForIssue, $perPage): array {
                $page = $this->indexQuery($request, $status, $readyForIssue)
                    ->orderBy('created_at', $request->query('sort_order', 'desc'))
                    ->orderBy('id', $request->query('sort_order', 'desc'))
                    ->paginate($perPage);

                return [
                    'items' => collect($page->items())
                        ->map(fn (StockDocument $document): array => $this->decorateDocument($document))
                        ->all(),
                    'total' => $page->total(),
                ];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        return P12DocumentReadContract::listResponse($this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page', 'query' => $request->query()],
        )), $request, 'OUTBOUND');
    }

    /** Truy vấn danh sách phiếu xuất Mini App — tách khỏi index() để nằm gọn trong closure cache. */
    private function indexQuery(Request $request, string $status, ?bool $readyForIssue)
    {
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', StockDocument::TYPE_ISSUE)
            ->when($request->filled('warehouse_id'), fn ($filtered) => $filtered->where(
                'src_warehouse_id',
                $request->input('warehouse_id'),
            ));

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

        P12DocumentReadContract::applyCreatedRange($query, $request);

        return $query;
    }

    public function show(string $id)
    {
        // Cùng TTL/version với index(). Route param không nằm trong query string nên phải
        // đưa vào khoá thủ công; 404 cũng được cache và được các đường ghi bump giải toả.
        $cached = $this->cachedReadPayload(
            'miniapp:outbound:detail',
            ['document_id' => $id],
            function () use ($id): array {
                $document = StockDocument::query()
                    ->with(['lines', 'movements', 'statusHistory'])
                    ->where('doc_type', StockDocument::TYPE_ISSUE)
                    ->find($id);

                if ($document === null) {
                    return ['document' => null];
                }

                $payload = $this->decorateDocument($document);
                $payload['item_matches'] = $this->scanEntryQuery($id, 'ALL')->get()->map(fn (object $row) => (array) $row)->values()->all();

                return ['document' => $payload];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        if (($cached['document'] ?? null) === null) {
            return $this->respondNotFound('Không tìm thấy phiếu xuất.');
        }

        return $this->respondSuccess($cached['document']);
    }

    /**
     * Bump cache đọc sau MỌI đường ghi của controller này.
     *
     * Scan/unmatch/preview/ready/print/apply/cancel đều là chuyển trạng thái STOCK-NEUTRAL
     * nên KHÔNG đi qua CurrentBaselineStockService::mutateBalance() — không có ai bump
     * `stock:version` hộ. Thiếu lời gọi này thì máy quét đọc lại tiến độ match hoặc trạng
     * thái Packing Label cũ tới 20s. Các lệnh uỷ quyền đã commit trước khi trả về nên
     * callback của DB::afterCommit chạy ngay.
     */
    private function invalidateAfterWrite(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
    }

    public function cancel(Request $request, string $id)
    {
        $response = $this->documents->cancelOutbound($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    /** Camera Mini App may send SCANNER as a friendly alias of canonical HARDWARE. */
    public function scan(Request $request, string $id)
    {
        $this->assertDraftNotExpired($this->requireDocument($id));
        if (strtoupper(trim((string) $request->input('scan_source', ''))) === 'SCANNER') {
            $request->merge(['scan_source' => 'HARDWARE']);
        }

        $response = $this->flow->outboundScan($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function unmatch(Request $request, string $id)
    {
        $response = $this->flow->outboundUnmatch($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function scanEntries(Request $request, string $id)
    {
        P12DocumentReadContract::validateEvidence($request);

        // Phiếu không tồn tại phải trả 404 và status sai phải trả 422 NGAY — cả hai kiểm
        // tra đứng TRƯỚC cache để một cache hit không nuốt mất chúng.
        $this->requireDocument($id);
        $status = strtoupper(trim((string) $request->query('status', 'ACTIVE')));
        if (! in_array($status, ['ACTIVE', 'REMOVED', 'ALL'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'status scan-entry chỉ nhận ACTIVE, REMOVED hoặc ALL.');
        }

        $perPage = $this->perPage($request, 50);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // TTL 'stock_short' (20s): bằng chứng match mà máy quét vừa ghi xong đọc lại ngay;
        // scan()/unmatch() bump `stock:version` nên danh sách đổi tức thì.
        $cached = $this->cachedReadPayload(
            'miniapp:outbound:scan-entries:p12-v1',
            // array_merge chứ KHÔNG dùng `+`: `+` để vế trái thắng khi trùng khoá, nên
            // `?document_id=<phiếu khác>` sẽ ghi đè id thật của route và hai phiếu dùng
            // chung một entry.
            array_merge($this->readFiltersWithPaging($request), ['document_id' => $id]),
            function () use ($id, $status, $perPage): array {
                $base = $this->scanEntryQuery($id, $status);
                $page = (clone $base)
                    ->orderByDesc('m.created_at')
                    ->orderByDesc('m.id')
                    ->paginate($perPage);

                return ['items' => $page->items(), 'total' => $page->total(), 'summary' => P12DocumentReadContract::evidenceSummary($base)];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        return P12DocumentReadContract::evidenceResponse($this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page', 'query' => $request->query()],
        )), $cached['summary'], $id, $status);
    }

    public function packingLabel(string $id)
    {
        // 404 phiếu xuất phải trả NGAY, trước cache.
        $this->requireDocument($id);

        // TTL 'stock_short' (20s): trạng thái nhãn (PREVIEWED/READY/PRINTED/applied) đổi
        // theo từng lệnh in; các lệnh đó bump `stock:version` ngay trong controller này.
        $cached = $this->cachedReadPayload(
            'miniapp:outbound:packing-label',
            ['document_id' => $id],
            fn (): array => ['label' => $this->labelForDocument($id)],
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        if (($cached['label'] ?? null) === null) {
            ApiError::abort(404, 'LABEL_NOT_FOUND', 'Phiếu xuất chưa có Packing Label. Chỉ full match mới tạo Packing Label.');
        }

        return $this->respondSuccess($cached['label']);
    }

    public function previewPackingLabel(Request $request, string $id, string $labelId)
    {
        $response = $this->flow->previewPackingLabel($request, $id, $labelId);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function readyPackingLabel(Request $request, string $id, string $labelId)
    {
        $response = $this->flow->readyPackingLabel($request, $id, $labelId);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function printPackingLabel(Request $request, string $id, string $labelId)
    {
        $response = $this->operations->printPackingLabelForDocument($request, $id, $labelId);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function reprintPackingLabel(Request $request, string $id, string $labelId)
    {
        $response = $this->operations->reprintPackingLabelForDocument($request, $id, $labelId);
        $this->invalidateAfterWrite();

        return $response;
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

        $response = $this->flow->applyPackingLabel($request, $labelId);
        $this->invalidateAfterWrite();

        return $response;
    }

    /** The only inventory-decreasing action in the Mini App outbound adapter. */
    public function postIssue(Request $request, string $id)
    {
        $response = $this->flow->postIssue($request, $id, bypassPackingLabelGate: true, bypassSource: 'MINI_APP');
        $this->invalidateAfterWrite();

        return $response;
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

        $draft = $this->draftState($document);
        $warehouse = $this->warehouseState((string) $document->src_warehouse_id);
        $miniAppStatus = $this->miniAppStatus((string) $document->status, $fullScan, $label);
        $canContinue = in_array((string) $document->status, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], true)
            && ! $draft['is_expired']
            && ($warehouse === null || ! $warehouse['is_suspended']);

        return [
            ...$document->toArray(),
            'draft' => $draft,
            'warehouse' => $warehouse,
            'can_continue' => $canContinue,
            'continue_from' => $canContinue ? $miniAppStatus : null,
            'required_total_qty' => $required,
            'scanned_total_qty' => $scanned,
            'remaining_qty' => max($required - $scanned, 0),
            'progress_percent' => $required > 0 ? round(min($scanned / $required, 1) * 100, 2) : 0,
            'full_scan' => $fullScan,
            'ready_for_issue' => $readyForIssue,
            'mini_app_status' => $miniAppStatus,
            'packing_label' => $label,
            'rfid_required' => false,
        ];
    }

    /** @return array{expires_at:?string,is_expired:bool,retention_days:int} */
    private function draftState(StockDocument $document): array
    {
        $expiresAt = $document->created_at?->copy()->addWeek();
        $expired = (string) $document->status === StockDocument::STATUS_DRAFT
            && $expiresAt !== null
            && now()->greaterThanOrEqualTo($expiresAt);

        return ['expires_at' => $expiresAt?->toIso8601String(), 'is_expired' => $expired, 'retention_days' => 7];
    }

    /** @return array<string,mixed>|null */
    private function warehouseState(string $warehouseId): ?array
    {
        if ($warehouseId === '') {
            return null;
        }
        if (! array_key_exists($warehouseId, $this->warehouseStateCache)) {
            $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
            $this->warehouseStateCache[$warehouseId] = $warehouse === null ? null : [
                'id' => (string) $warehouse->id,
                'code' => (string) $warehouse->code,
                'name' => (string) $warehouse->name,
                'status' => (string) $warehouse->status,
                'is_suspended' => strtoupper((string) $warehouse->status) !== 'ACTIVE',
                'suspension_reason' => $warehouse->suspension_reason ?? null,
            ];
        }

        return $this->warehouseStateCache[$warehouseId];
    }

    private function assertDraftNotExpired(object $document): void
    {
        if ((string) $document->status !== 'DRAFT') {
            return;
        }
        $expiresAt = empty($document->created_at)
            ? null
            : Carbon::parse($document->created_at)->addWeek();
        if ($expiresAt !== null && now()->greaterThanOrEqualTo($expiresAt)) {
            ApiError::abort(409, 'DRAFT_EXPIRED', 'Phiếu nháp đã hết hạn sau 07 ngày. Hãy hủy phiếu hoặc tạo phiếu mới.', [
                'document_id' => (string) $document->id,
                'draft_expires_at' => $expiresAt,
            ]);
        }
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
            'm.created_at',
            'm.removed_at',
            'l.line_no',
            'l.product_id as sku_id',
            's.sku_code',
            's.sku_name',
            's.unit',
            'l.stock_document_id as document_id',
            'i.item_code',
            'i.serial_number',
        ];

        $query = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'm.item_id')
            ->where('l.stock_document_id', $documentId);

        $hasScanEvent = SchemaCache::hasColumn('outbound_item_matches', 'scan_event_id')
            && (SchemaCache::hasTable('scan_event') || SchemaCache::hasView('scan_event'));
        if ($hasScanEvent) {
            $query->leftJoin('scan_event as se', 'se.scan_event_id', '=', 'm.scan_event_id');
            if (SchemaCache::hasRelationColumn('scan_event', 'raw_code')) {
                $columns[] = 'se.raw_code';
            } else {
                $columns[] = DB::raw('NULL as raw_code');
            }
            if (SchemaCache::hasRelationColumn('scan_event', 'scan_source')) {
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
