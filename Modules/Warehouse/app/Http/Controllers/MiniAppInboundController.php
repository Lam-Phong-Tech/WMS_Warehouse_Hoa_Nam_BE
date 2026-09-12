<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\Database\SchemaCache;
use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\ScanRequestFingerprint;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use Modules\Warehouse\Support\SkuInboundInfoRule;

/**
 * Thin adapter for the warehouse Mini App.
 *
 * This controller deliberately reuses the canonical inbound services and tables.
 * It does NOT introduce a second stock state machine: DRAFT -> SCANNING -> POSTED
 * remains authoritative and only Post Receipt changes inventory.
 *
 * Mini-App-only conveniences provided here:
 * - single-SKU create shortcut (sku_code/sku_id + expected_qty -> canonical lines[]);
 * - scan_source CAMERA/HARDWARE/MANUAL persisted into scan evidence;
 * - raw scan code returned through scan-entries;
 * - computed ready_for_post / mini_app_status for the review/approval UI;
 * - semantic /approve alias that still executes the canonical Post Receipt command.
 */
class MiniAppInboundController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    /**
     * Dữ liệu Mini App là dữ liệu MÁY QUÉT đọc: cũ một nhịp ở đây nguy hiểm hơn hẳn mọi
     * chỗ khác, nên dùng TTL ngắn nhất ('stock_short' = 20s) và bám vào `stock:version`
     * — namespace đã được mọi mutation tồn kho bump. Mọi đường ghi của chính controller
     * này (scan + các lệnh uỷ quyền) cũng bump, xem từng phương thức bên dưới.
     */
    private const CACHE_VERSION_NS = 'stock:version';

    /** @var array<string, array<string, mixed>|null> */
    private array $warehouseStateCache = [];

    public function __construct(
        private readonly CurrentBaselineDocumentController $documents,
        private readonly CurrentBaselineStockFlowController $stockFlow,
        private readonly CurrentBaselineInboundBoxController $boxes,
        private readonly CurrentBaselineStockService $stock,
    ) {}

    public function index(Request $request)
    {
        // Kiểm tra tham số TRƯỚC cache: status sai phải trả 422 ngay, không phụ thuộc
        // việc khoá cache đã có entry hay chưa.
        $status = strtoupper(trim((string) $request->query('status', '')));
        $readyFilter = $this->readyFilter($request);

        if ($status === 'WAITING_APPROVAL') {
            $status = 'SCANNING';
            $readyFilter = true;
        }

        if ($status !== '' && ! in_array($status, ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'status không hợp lệ cho danh sách phiếu nhập Mini App.');
        }

        $perPage = $this->perPage($request);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // TTL 'stock_short' (20s): tiến độ scan đổi liên tục. Warehouse-scope đã nằm sẵn
        // trong khoá qua readCacheScopeIds(), phân trang chạy TRONG SQL nên page/per_page
        // cũng phải nằm trong khoá.
        $cached = $this->cachedReadPayload(
            'miniapp:inbound:list',
            $this->readFiltersWithPaging($request),
            function () use ($request, $status, $readyFilter, $perPage): array {
                $page = $this->indexQuery($request, $status, $readyFilter)
                    ->orderByDesc('created_at')
                    ->paginate($perPage);

                return [
                    'items' => $this->decorateDocuments($page->items()),
                    'total' => $page->total(),
                ];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        return $this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ));
    }

    /** Truy vấn danh sách phiếu nhập Mini App — tách khỏi index() để nằm gọn trong closure cache. */
    private function indexQuery(Request $request, string $status, ?bool $readyFilter)
    {
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', StockDocument::TYPE_RECEIPT);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($readyFilter === true) {
            $query->where('status', StockDocument::STATUS_SCANNING)
                ->whereHas('lines')
                ->whereDoesntHave('lines', fn ($line) => $line->whereColumn('scanned_qty', '<>', 'qty_planned'));
        } elseif ($readyFilter === false) {
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
                $query->whereIn('dst_warehouse_id', $warehouseScopeIds);
            }
        }

        $this->applyKeyword($query, $request, ['doc_no', 'source_name', 'note']);

        return $query;
    }

    public function show(string $id)
    {
        // Cùng TTL/version với index(). Route param không nằm trong query string nên phải
        // đưa vào khoá thủ công. 404 cũng được cache, an toàn vì store() bump ngay khi
        // tạo phiếu mới.
        $cached = $this->cachedReadPayload(
            'miniapp:inbound:detail',
            ['document_id' => $id],
            function () use ($id): array {
                $document = StockDocument::query()
                    ->with(['lines', 'statusHistory'])
                    ->where('doc_type', StockDocument::TYPE_RECEIPT)
                    ->find($id);

                return ['document' => $document === null ? null : $this->decorateDocument($document)];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        if (($cached['document'] ?? null) === null) {
            return $this->respondNotFound('Không tìm thấy phiếu nhập.');
        }

        return $this->respondSuccess($cached['document']);
    }

    /**
     * Canonical create still requires planned SKU lines. For the common Mini App
     * single-SKU case, accept sku_code/sku_id + expected_qty and translate it to
     * the canonical lines[] payload before delegating to the baseline controller.
     */
    public function store(Request $request)
    {
        if (! $request->has('lines')) {
            $shortcut = $request->validate([
                'expected_qty' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
                'product_id' => ['nullable', 'uuid', 'required_without_all:sku_id,sku_code'],
                'sku_id' => ['nullable', 'uuid', 'required_without_all:product_id,sku_code'],
                'sku_code' => ['nullable', 'string', 'max:100', 'required_without_all:product_id,sku_id'],
            ]);

            $skuId = $shortcut['product_id'] ?? $shortcut['sku_id'] ?? null;
            if (! empty($shortcut['sku_code'])) {
                $sku = DB::table('sku')
                    ->whereRaw('UPPER(sku_code) = ?', [strtoupper(trim((string) $shortcut['sku_code']))])
                    ->where('status', 'ACTIVE')
                    ->first();
                if ($sku === null) {
                    ApiError::abort(422, 'SKU_NOT_ACTIVE', 'Không tìm thấy SKU ACTIVE theo sku_code.');
                }
                if ($skuId !== null && strtolower((string) $skuId) !== strtolower((string) $sku->sku_id)) {
                    ApiError::abort(422, 'WRONG_SKU', 'sku_code không khớp sku_id/product_id được gửi.');
                }
                $skuId = (string) $sku->sku_id;
            }

            $request->merge([
                'lines' => [[
                    'product_id' => (string) $skuId,
                    'qty_planned' => (int) $shortcut['expected_qty'],
                ]],
            ]);
        }

        $response = $this->documents->storeInbound($request);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function cancel(Request $request, string $id)
    {
        $response = $this->documents->cancelInbound($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    /**
     * Bump cache đọc sau MỌI đường ghi của controller này.
     *
     * Scan/unscan/box/post-receipt đổi `scanned_qty` và trạng thái phiếu — thứ mà
     * index()/show()/scanEntries() đang cache. Riêng scan nhập là stock-neutral nên
     * KHÔNG đi qua CurrentBaselineStockService::mutateBalance(), tức là không có ai bump
     * `stock:version` hộ; thiếu lời gọi này thì máy quét đọc lại tiến độ cũ tới 20s.
     *
     * Đặt trong DB::afterCommit: nếu còn transaction đang mở thì chờ commit xong mới
     * bump (không nạp lại cache từ dữ liệu chưa commit); các lệnh uỷ quyền ở đây đã
     * commit trước khi trả về nên callback chạy ngay.
     */
    private function invalidateAfterWrite(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
    }

    /**
     * Mini App scan endpoint with explicit scan source. Stock behavior is delegated
     * to CurrentBaselineStockService, so all QR/SKU/item/duplicate/full-post rules
     * stay identical to Web WMS.
     */
    public function scan(Request $request, string $id)
    {
        $payload = $this->scanPayload($request);
        $expectedVersion = $this->expectedVersion($request);
        $idempotencyKey = IdempotencyKeyGuard::optional($request);
        $fingerprint = ScanRequestFingerprint::for('inbound_scan', $id, $payload);

        [$code, $body, $replayed] = IdempotencyStore::handle(
            $idempotencyKey,
            'inbound_scan_'.$id,
            $this->actorId(),
            $fingerprint,
            function () use ($id, $payload, $expectedVersion): array {
                $result = DB::transaction(function () use ($id, $payload, $expectedVersion): array {
                    $document = $this->lockDocument($id);
                    $this->assertVersion($document, $expectedVersion);
                    $this->assertDraftNotExpired($document);
                    if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan.');
                    }

                    if (! empty($payload['line_id'])) {
                        $lineExists = DB::table('stock_document_lines')
                            ->where('id', $payload['line_id'])
                            ->where('stock_document_id', $id)
                            ->exists();
                        if (! $lineExists) {
                            ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy line trong phiếu nhập.');
                        }
                    }

                    $resolvedItemId = $this->stock->resolveExistingItemIdForPayload($payload);
                    if ($resolvedItemId !== null) {
                        $this->assertNoDuplicateItemInDocument($id, $resolvedItemId);
                    }

                    $result = $this->stock->scanStockDocument($id, 'receipt', $payload, $this->actorId());
                    $newVersion = $expectedVersion + 1;
                    DB::table('stock_documents')->where('id', $id)->update([
                        'version' => $newVersion,
                        'updated_by' => $this->actorId(),
                        'updated_at' => now(),
                    ]);

                    $entryId = $this->attachScanEventToEvidence(
                        $id,
                        (string) $result['line_id'],
                        $result['item_id'] ?? null,
                        $result['item_unique'] ?? null,
                        (string) $payload['scan_source']
                    );

                    $result['version'] = $newVersion;
                    $result['inbound_scan_entry_id'] = $entryId;
                    $result['scan_source'] = (string) $payload['scan_source'];
                    $result += $this->progressForDocument($id);

                    return $result;
                }, 3);

                return [200, $this->envelope('Inbound scan validated; stock_effect=NONE.', $result)];
            }
        );

        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            $body['data']['replayed'] = $replayed;
        }

        // Transaction bên trong IdempotencyStore::handle() đã commit tại đây.
        $this->invalidateAfterWrite();

        $response = response()->json($body, $code)
            ->header('Idempotent-Replay', $replayed ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    public function unscan(Request $request, string $id)
    {
        $response = $this->stockFlow->inboundUnscan($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function registerBox(Request $request, string $id)
    {
        $response = $this->boxes->store($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function completeCartonScan(Request $request, string $id, string $containerId)
    {
        $response = $this->stockFlow->completeInboundCartonScan($request, $id, $containerId);
        $this->invalidateAfterWrite();

        return $response;
    }

    /**
     * Both /approve and /post-receipt route here. Permission inbound.post is
     * enforced at route level. This remains the only stock-increasing action.
     */
    public function approve(Request $request, string $id)
    {
        $response = $this->stockFlow->postReceipt($request, $id);
        $this->invalidateAfterWrite();

        return $response;
    }

    public function scanEntries(Request $request, string $id)
    {
        // Phiếu không tồn tại phải trả 404 NGAY — kiểm tra TRƯỚC cache, nếu để sau thì
        // một cache hit sẽ nuốt mất 404.
        $document = DB::table('stock_documents')
            ->where('id', $id)
            ->where('doc_type', 'receipt')
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
        }

        $statusFilter = strtoupper(trim((string) $request->query('status', 'ACTIVE')));
        if (! in_array($statusFilter, ['ACTIVE', 'REMOVED', 'ALL'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'status scan-entry chỉ nhận ACTIVE, REMOVED hoặc ALL.');
        }

        $perPage = $this->perPage($request, 50);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // TTL 'stock_short' (20s): đây là bằng chứng scan mà máy quét vừa ghi xong đọc lại
        // ngay; scan()/unscan() bên trên bump `stock:version` nên danh sách đổi tức thì.
        $cached = $this->cachedReadPayload(
            'miniapp:inbound:scan-entries',
            // array_merge chứ KHÔNG dùng `+`: `+` để vế trái thắng khi trùng khoá, nên
            // `?document_id=<phiếu khác>` sẽ ghi đè id thật của route và hai phiếu dùng
            // chung một entry.
            array_merge($this->readFiltersWithPaging($request), ['document_id' => $id]),
            function () use ($id, $statusFilter, $perPage): array {
                $page = $this->scanEntriesQuery($id, $statusFilter)
                    ->orderByDesc('e.created_at')
                    ->paginate($perPage);

                return ['items' => $page->items(), 'total' => $page->total()];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        return $this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ));
    }

    /** Truy vấn scan-entry của một phiếu nhập — tách khỏi scanEntries() để nằm gọn trong closure cache. */
    private function scanEntriesQuery(string $id, string $statusFilter)
    {
        $columns = [
            'e.id',
            'e.inbound_line_id',
            'e.item_id',
            'e.container_id',
            'e.quantity',
            'e.scan_source',
            'e.status',
            'e.created_at',
            'l.line_no',
            'l.product_id',
            's.sku_code',
            's.sku_name',
            'i.serial_number',
        ];

        if (SchemaCache::hasColumn('item_instances', 'item_code')) {
            $columns[] = 'i.item_code';
        }
        if (SchemaCache::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            $columns[] = 'e.candidate_item_unique';
            $columns[] = 'e.candidate_serial_number';
        }

        $hasScanEvent = SchemaCache::hasColumn('inbound_scan_entries', 'scan_event_id')
            && (SchemaCache::hasTable('scan_event') || SchemaCache::hasView('scan_event'))
            && SchemaCache::hasRelationColumn('scan_event', 'raw_code');

        $query = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'e.item_id')
            ->where('l.stock_document_id', $id);

        if ($hasScanEvent) {
            $query->leftJoin('scan_event as se', 'se.scan_event_id', '=', 'e.scan_event_id');
            $columns[] = 'se.raw_code';
        } else {
            $columns[] = DB::raw('NULL as raw_code');
        }

        $query->when($statusFilter === 'ACTIVE', function ($builder): void {
            $builder->where(function ($nested): void {
                $nested->whereNull('e.status')->orWhere('e.status', '<>', 'REMOVED');
            });
        })->when($statusFilter === 'REMOVED', fn ($builder) => $builder->where('e.status', 'REMOVED'));

        return $query->select($columns);
    }

    private function scanPayload(Request $request): array
    {
        if ($request->has('code_value') && $request->input('code_value') !== null) {
            $request->merge(['code_value' => trim((string) $request->input('code_value'))]);
        }

        $validated = $request->validate([
            'line_id' => ['nullable', 'required_without:code_value', 'uuid'],
            'quantity' => ['required_without:qty', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'qty' => ['required_without:quantity', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'code_value' => ['nullable', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'item_id' => ['nullable', 'uuid'],
            'container_id' => ['nullable', 'uuid'],
            'printer_config_id' => ['nullable', 'uuid', 'exists:printer_configs,id'],
            'scan_source' => ['nullable', 'string', 'in:CAMERA,SCANNER,HARDWARE,MANUAL'],
        ]);

        $validated['quantity'] = (int) ($request->input('quantity') ?? $request->input('qty'));
        $defaultSource = ! empty($validated['code_value']) ? 'CAMERA' : 'MANUAL';
        $source = strtoupper((string) ($validated['scan_source'] ?? $defaultSource));
        $validated['scan_source'] = $source === 'SCANNER' ? 'HARDWARE' : $source;

        return $validated;
    }

    private function attachScanEventToEvidence(
        string $documentId,
        string $lineId,
        mixed $itemId,
        mixed $itemUnique,
        string $scanSource
    ): ?string {
        $scanEventId = null;
        if (SchemaCache::hasColumn('inbound_scan_entries', 'scan_event_id')
            && (SchemaCache::hasTable('scan_event') || SchemaCache::hasView('scan_event'))) {
            $scanEventId = DB::table('scan_event as se')
                ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
                ->where('sb.source_id', $documentId)
                ->when($itemId, fn ($query) => $query->where('se.resolved_object_id', $itemId))
                ->orderByDesc('se.created_at')
                ->value('se.scan_event_id');
        }

        $entryQuery = DB::table('inbound_scan_entries')
            ->where('inbound_line_id', $lineId)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '<>', 'REMOVED');
            });

        if ($itemId !== null) {
            $entryQuery->where('item_id', $itemId);
        } elseif ($itemUnique !== null && SchemaCache::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            $entryQuery->where('candidate_item_unique', $itemUnique);
        }

        $entry = $entryQuery->orderByDesc('created_at')->first();
        if ($entry === null) {
            return null;
        }

        $updates = ['scan_source' => $scanSource, 'updated_at' => now()];
        if ($scanEventId !== null && SchemaCache::hasColumn('inbound_scan_entries', 'scan_event_id')) {
            $updates['scan_event_id'] = $scanEventId;
        }
        DB::table('inbound_scan_entries')->where('id', $entry->id)->update($updates);

        return (string) $entry->id;
    }

    private function assertNoDuplicateItemInDocument(string $documentId, string $itemId): void
    {
        $exists = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('e.item_id', $itemId)
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '<>', 'REMOVED');
            })
            ->exists();

        if ($exists) {
            ApiError::abort(409, 'DUPLICATE_ITEM_IN_DOCUMENT', 'Một item chỉ được scan một lần trong cùng phiếu nhập.');
        }
    }

    /** @return array<string,mixed> */
    private function decorateDocument(StockDocument $document): array
    {
        return $this->decorateDocuments([$document])[0];
    }

    /**
     * Trang trí theo LÔ: thông tin SKU của mọi dòng trong cả trang được đọc bằng
     * đúng hai truy vấn (sku + luật thiếu thông tin), thay vì mỗi phiếu một lần.
     *
     * @param  iterable<int,StockDocument>  $documents
     * @return list<array<string,mixed>>
     */
    private function decorateDocuments(iterable $documents): array
    {
        $documents = is_array($documents) ? $documents : iterator_to_array($documents);

        $documentIds = [];
        $productIds = [];
        foreach ($documents as $document) {
            $documentIds[] = (string) $document->id;
            foreach ($this->linesOf($document) as $line) {
                if ($line->product_id !== null) {
                    $productIds[] = (string) $line->product_id;
                }
            }
        }

        $skus = $productIds === []
            ? collect()
            : DB::table('sku')
                ->whereIn('sku_id', array_values(array_unique($productIds)))
                ->get(['sku_id', 'sku_code', 'sku_name', 'sku_type', 'unit'])
                ->keyBy(fn ($row) => (string) $row->sku_id);

        $incompleteByDocument = SkuInboundInfoRule::incompleteSkusOfDocuments($documentIds);

        $decorated = [];
        foreach ($documents as $document) {
            $decorated[] = $this->decorateOneDocument(
                $document,
                $skus,
                $incompleteByDocument[(string) $document->id] ?? [],
            );
        }

        return $decorated;
    }

    /**
     * @param  \Illuminate\Support\Collection<string,object>  $skus
     * @param  list<array{sku_id:string, sku_code:string, sku_name:string, missing_fields:list<string>}>  $incompleteSkus
     * @return array<string,mixed>
     */
    private function decorateOneDocument(StockDocument $document, $skus, array $incompleteSkus): array
    {
        $data = $document->toArray();
        $lines = $this->linesOf($document);
        $expected = (float) $lines->sum(fn ($line) => (float) $line->qty_planned);
        $scanned = (float) $lines->sum(fn ($line) => (float) $line->scanned_qty);
        $ready = $document->status === StockDocument::STATUS_SCANNING
            && $lines->isNotEmpty()
            && $lines->every(fn ($line) => (float) $line->scanned_qty === (float) $line->qty_planned);

        // `lines[].product_id` chỉ là UUID thô: Mini App không tra được tên SKU từ
        // đó (danh sách SKU của Mini App có phân trang, KHÔNG chứa SKU vừa sinh ra
        // lúc quét). Gắn thẳng mã/tên/ĐVT + cờ thiếu thông tin lên từng dòng, cùng
        // tên field với kênh canonical (InteractsWithStockDocuments::show()).
        if (isset($data['lines']) && is_array($data['lines'])) {
            $data['lines'] = array_map(function (array $line) use ($skus): array {
                $sku = $skus->get((string) ($line['product_id'] ?? ''));
                $missingInfo = SkuInboundInfoRule::missingFields(
                    $sku->sku_code ?? null,
                    $sku->sku_name ?? null,
                    $sku->unit ?? null,
                );

                return $line + [
                    'product_code' => $sku->sku_code ?? null,
                    'product_name' => $sku->sku_name ?? null,
                    'product_type' => $sku->sku_type ?? null,
                    'product_unit' => $sku->unit ?? null,
                    'product_missing_info' => $sku === null ? [] : $missingInfo,
                    'product_info_complete' => $sku === null ? true : $missingInfo === [],
                ];
            }, $data['lines']);
        }

        // Phiếu ĐÃ POSTED thì luật thiếu thông tin không còn ý nghĩa (SKU có thể bị
        // đổi tên sau đó); chỉ soi phiếu còn mở.
        $open = in_array((string) $document->status, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], true);
        $pendingInfo = $open ? $incompleteSkus : [];
        $infoComplete = $pendingInfo === [];

        $draft = $this->draftState($document);
        $warehouse = $this->warehouseState((string) $document->dst_warehouse_id);
        $canContinue = in_array((string) $document->status, [StockDocument::STATUS_DRAFT, StockDocument::STATUS_SCANNING], true)
            && ! $draft['is_expired']
            && ($warehouse === null || ! $warehouse['is_suspended']);

        return $data + [
            'draft' => $draft,
            'warehouse' => $warehouse,
            'can_continue' => $canContinue,
            'continue_from' => $canContinue ? ($ready ? 'REVIEW' : 'SCAN') : null,
            'expected_total_qty' => $this->number($expected),
            'scanned_total_qty' => $this->number($scanned),
            'remaining_qty' => $this->number(max(0, $expected - $scanned)),
            'progress_percent' => $expected > 0 ? round(min(100, ($scanned / $expected) * 100), 2) : 0,
            'ready_for_post' => $ready,
            // mini_app_status GIỮ NGUYÊN nghĩa cũ (quét đủ = WAITING_APPROVAL) để
            // bản Mini App đang chạy không mất phiếu khỏi hàng chờ duyệt. Việc
            // "chưa Post được vì thiếu thông tin SKU" nói bằng các field MỚI dưới
            // đây, client cũ không đọc thì hành vi y như trước.
            'mini_app_status' => $ready ? 'WAITING_APPROVAL' : (string) $document->status,
            'product_info_complete' => $infoComplete,
            'pending_product_info_count' => count($pendingInfo),
            'skus_pending_info' => $pendingInfo,
            // Post Receipt sẽ 422 INBOUND_SKU_INFO_INCOMPLETE nếu còn SKU thiếu
            // thông tin: nói trước ở đây để Mini App ẩn/khoá nút duyệt và chỉ
            // đường "vào web bổ sung" thay vì để người quét bấm rồi ăn lỗi.
            'can_post' => $ready && $infoComplete,
            'post_blocked_reason' => $ready && ! $infoComplete ? 'INBOUND_SKU_INFO_INCOMPLETE' : null,
        ];
    }

    /** @return \Illuminate\Support\Collection<int,\Modules\Warehouse\Models\StockDocumentLine> */
    private function linesOf(StockDocument $document)
    {
        return $document->relationLoaded('lines') ? $document->lines : $document->lines()->get();
    }

    /** @return array<string,mixed> */
    private function progressForDocument(string $documentId): array
    {
        $document = DB::table('stock_documents')->where('id', $documentId)->first();
        $lines = DB::table('stock_document_lines')->where('stock_document_id', $documentId)->get();
        $expected = (float) $lines->sum(fn ($line) => (float) $line->qty_planned);
        $scanned = (float) $lines->sum(fn ($line) => (float) $line->scanned_qty);
        $ready = $document !== null
            && (string) $document->status === 'SCANNING'
            && $lines->isNotEmpty()
            && $lines->every(fn ($line) => (float) $line->scanned_qty === (float) $line->qty_planned);

        return [
            'expected_total_qty' => $this->number($expected),
            'scanned_total_qty' => $this->number($scanned),
            'remaining_qty' => $this->number(max(0, $expected - $scanned)),
            'ready_for_post' => $ready,
            'mini_app_status' => $ready ? 'WAITING_APPROVAL' : (string) ($document->status ?? 'SCANNING'),
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
            : \Illuminate\Support\Carbon::parse($document->created_at)->addWeek();
        if ($expiresAt !== null && now()->greaterThanOrEqualTo($expiresAt)) {
            ApiError::abort(409, 'DRAFT_EXPIRED', 'Phiếu nháp đã hết hạn sau 07 ngày. Hãy hủy phiếu hoặc tạo phiếu mới.', [
                'document_id' => (string) $document->id,
                'draft_expires_at' => $expiresAt,
            ]);
        }
    }

    private function readyFilter(Request $request): ?bool
    {
        if (! $request->has('ready_for_post')) {
            return null;
        }

        $value = filter_var($request->query('ready_for_post'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'ready_for_post phải là true hoặc false.');
        }

        return $value;
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho scan/post mutation.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function assertVersion(object $document, int $expected): void
    {
        if ((int) ($document->version ?? 0) !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Document version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) ($document->version ?? 0),
            ]);
        }
    }

    private function lockDocument(string $id): object
    {
        $document = DB::table('stock_documents')
            ->where('id', $id)
            ->where('doc_type', 'receipt')
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
        }

        return $document;
    }

    /** @return array<string,mixed> */
    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()?->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    private function number(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
