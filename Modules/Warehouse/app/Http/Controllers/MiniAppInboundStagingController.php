<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use Modules\Warehouse\Support\ProductQrPayload;

/**
 * Mini App local-staging boundary for inbound PRODUCT intake.
 *
 * The camera phase stays local on the Mini App. This controller only provides:
 * 1) read-only preflight resolve for a raw QR/barcode; and
 * 2) one atomic "record" command that creates the WMS receipt + planned SKU lines
 *    + every scan evidence in the same database transaction.
 *
 * Inventory is never increased here. The authoritative stock mutation remains
 * POST /api/v1/inbound-documents/{id}/post-receipt.
 */
class MiniAppInboundStagingController extends Controller
{
    use ApiResponse;
    use ResolvesActor;

    private const CANONICAL_WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const MAX_BATCH_ITEMS = 1000;
    private const RECORD_FIELDS = [
        'name',
        'dst_warehouse_id',
        'expected_total_qty',
        'doc_date',
        'note',
        'items',
    ];
    private const RECORD_ITEM_FIELDS = [
        'raw_code',
        'scan_source',
    ];

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly ProductIdentityResolver $identityResolver,
        private readonly CurrentBaselineStockService $stock,
    ) {}

    /**
     * Read-only preflight used while the camera is filling local rows.
     * No WMS document, scan evidence, movement, inventory mutation or idempotency row is created.
     */
    public function resolveCode(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
        ]);

        $this->assertCanonicalWarehouse((string) $validated['warehouse_id']);
        $resolved = $this->resolveRawCode((string) $validated['raw_code'], true);

        return $this->respondSuccess([
            ...$resolved,
            'warehouse_id' => (string) $validated['warehouse_id'],
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'scan_evidence_created' => false,
            'wms_document_created' => false,
        ], 'Đã resolve mã cho local staging; chưa ghi nhận vào WMS.');
    }

    /**
     * Atomic handoff from the local confirmation screen to WMS.
     *
     * Request contains all locally scanned rows. The backend resolves every code,
     * rejects duplicates, groups by SKU, creates the receipt lines, then records
     * every scan with CurrentBaselineStockService. Any error rolls back everything.
     */
    public function record(Request $request)
    {
        $this->assertRecordPayloadShape($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'expected_total_qty' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'doc_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'items.*.scan_source' => ['nullable', 'string', 'in:CAMERA,SCANNER,HARDWARE,MANUAL'],
        ]);

        if (count($validated['items']) !== (int) $validated['expected_total_qty']) {
            ApiError::abort(422, 'SCAN_COUNT_MISMATCH', 'Số dòng scan local phải đúng bằng số lượng đã nhập trước khi xác nhận.', [
                'expected_total_qty' => (int) $validated['expected_total_qty'],
                'scanned_total_qty' => count($validated['items']),
            ]);
        }

        $this->assertCanonicalWarehouse((string) $validated['dst_warehouse_id']);

        $actorId = $this->actorId();
        $idempotencyKey = IdempotencyKeyGuard::require($request);
        $requestHash = IdempotencyStore::requestHash($request);

        [$code, $body, $replayed] = IdempotencyStore::handle(
            $idempotencyKey,
            'mini_app_inbound_record',
            $actorId,
            $requestHash,
            function () use ($validated, $actorId, $idempotencyKey): array {
                $result = DB::transaction(function () use ($validated, $actorId, $idempotencyKey): array {
                    $prepared = $this->prepareBatch($validated['items']);
                    $documentId = (string) Str::uuid();
                    $docNo = 'PN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
                    $now = now();

                    DB::table('stock_documents')->insert([
                        'id' => $documentId,
                        'doc_type' => 'receipt',
                        'doc_no' => $docNo,
                        'doc_date' => $validated['doc_date'] ?? $now->toDateString(),
                        'status' => 'DRAFT',
                        'src_warehouse_id' => null,
                        'dst_warehouse_id' => (string) $validated['dst_warehouse_id'],
                        'source_name' => trim((string) $validated['name']),
                        'outbound_request_id' => null,
                        'agency_id' => null,
                        'recipient_name' => null,
                        'recipient_address' => null,
                        'note' => $validated['note'] ?? null,
                        'version' => 1,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $lineIdsBySku = [];
                    $lineNo = 1;
                    foreach ($prepared['sku_counts'] as $skuId => $quantity) {
                        $lineId = (string) Str::uuid();
                        $lineIdsBySku[$skuId] = $lineId;
                        DB::table('stock_document_lines')->insert([
                            'id' => $lineId,
                            'stock_document_id' => $documentId,
                            'line_no' => $lineNo++,
                            'product_id' => $skuId,
                            'outbound_request_line_id' => null,
                            'import_batch_id' => null,
                            'qty_planned' => $quantity,
                            'qty_actual' => $quantity,
                            'scanned_qty' => 0,
                            'note' => null,
                            'version' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    AuditLogger::record(
                        action: 'CREATE_INBOUND',
                        objectType: 'stock_document',
                        objectId: $documentId,
                        actorId: $actorId,
                        requestId: request()?->attributes->get('request_id'),
                        afterData: [
                            'status' => 'DRAFT',
                            'version' => 1,
                            'source' => 'MINI_APP_ATOMIC_RECORD',
                            'expected_total_qty' => (int) $validated['expected_total_qty'],
                        ],
                        idempotencyKey: $idempotencyKey,
                    );

                    $version = 1;
                    $scanResults = [];
                    foreach ($prepared['items'] as $index => $item) {
                        $payload = [
                            'line_id' => $lineIdsBySku[$item['sku_id']],
                            'quantity' => 1,
                            'code_value' => $item['raw_code'],
                            'scan_source' => $item['scan_source'],
                        ];

                        $scanResult = $this->stock->scanStockDocument($documentId, 'receipt', $payload, $actorId);
                        $version++;
                        DB::table('stock_documents')->where('id', $documentId)->update([
                            'version' => $version,
                            'updated_by' => $actorId,
                            'updated_at' => now(),
                        ]);

                        $entryId = $this->attachScanEventToEvidence(
                            documentId: $documentId,
                            lineId: (string) $scanResult['line_id'],
                            itemId: $scanResult['item_id'] ?? null,
                            itemUnique: $scanResult['item_unique'] ?? null,
                            rawCode: $item['raw_code'],
                            scanSource: $item['scan_source'],
                        );

                        $scanResults[] = [
                            'row_no' => $index + 1,
                            'raw_code' => $item['raw_code'],
                            'sku_id' => $item['sku_id'],
                            'sku_code' => $item['sku_code'],
                            'sku_name' => $item['sku_name'],
                            'item_id' => $scanResult['item_id'] ?? null,
                            'item_unique' => $scanResult['item_unique'] ?? $item['item_unique'],
                            'resolution_status' => $scanResult['resolution_status'] ?? $item['resolution_status'],
                            'scan_source' => $item['scan_source'],
                            'inbound_scan_entry_id' => $entryId,
                        ];
                    }

                    $progress = $this->progressForDocument($documentId);
                    if (! $progress['ready_for_post']) {
                        ApiError::abort(409, 'ATOMIC_RECORD_INCOMPLETE', 'Batch đã ghi nhưng chưa đạt full-scan; toàn bộ transaction sẽ rollback.');
                    }

                    AuditLogger::record(
                        action: 'MINI_APP_RECORD_INBOUND',
                        objectType: 'stock_document',
                        objectId: $documentId,
                        actorId: $actorId,
                        requestId: request()?->attributes->get('request_id'),
                        afterData: [
                            'status' => 'SCANNING',
                            'mini_app_status' => 'WAITING_APPROVAL',
                            'expected_total_qty' => $progress['expected_total_qty'],
                            'scanned_total_qty' => $progress['scanned_total_qty'],
                            'scan_evidence_count' => count($scanResults),
                            'version' => $version,
                        ],
                        idempotencyKey: $idempotencyKey,
                    );

                    return [
                        'document_id' => $documentId,
                        'doc_no' => $docNo,
                        'name' => trim((string) $validated['name']),
                        'doc_date' => $validated['doc_date'] ?? $now->toDateString(),
                        'dst_warehouse_id' => (string) $validated['dst_warehouse_id'],
                        'status' => 'SCANNING',
                        'mini_app_status' => 'WAITING_APPROVAL',
                        'ready_for_post' => true,
                        'expected_total_qty' => $progress['expected_total_qty'],
                        'scanned_total_qty' => $progress['scanned_total_qty'],
                        'remaining_qty' => $progress['remaining_qty'],
                        'version' => $version,
                        'stock_effect' => 'NONE',
                        'movement_created' => false,
                        'scan_evidence_created' => true,
                        'scan_evidence_count' => count($scanResults),
                        'sku_lines' => $this->skuLineSummary($documentId),
                        'items' => $scanResults,
                    ];
                }, 3);

                return [201, $this->envelope('Đã ghi nhận hàng nhập; phiếu đang chờ duyệt và chưa tăng tồn.', $result)];
            }
        );

        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            $body['data']['replayed'] = $replayed;
        }

        $response = response()->json($body, $code)
            ->header('Idempotent-Replay', $replayed ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function prepareBatch(array $items): array
    {
        $prepared = [];
        $skuCounts = [];
        $seen = [];

        foreach (array_values($items) as $index => $item) {
            $rawCode = trim((string) $item['raw_code']);
            $resolved = $this->resolveRawCode($rawCode, true);
            if (($resolved['sku_type'] ?? null) !== 'PRODUCT') {
                ApiError::abort(422, 'MINI_APP_COMPONENT_FLOW_REQUIRES_BOX', 'Luồng atomic local-staging hiện áp dụng cho PRODUCT; COMPONENT phải đi theo luồng BOX.', [
                    'row_no' => $index + 1,
                    'sku_code' => $resolved['sku_code'] ?? null,
                ]);
            }

            $identityKey = $resolved['item_id'] !== null
                ? 'ITEM:'.strtolower((string) $resolved['item_id'])
                : 'CANDIDATE:'.strtoupper((string) $resolved['item_unique']);

            if (isset($seen[$identityKey])) {
                ApiError::abort(409, 'DUPLICATE_ITEM_IN_BATCH', 'Một item xuất hiện nhiều hơn một lần trong batch local.', [
                    'first_row_no' => $seen[$identityKey],
                    'duplicate_row_no' => $index + 1,
                    'raw_code' => $rawCode,
                ]);
            }
            $seen[$identityKey] = $index + 1;

            $source = strtoupper((string) ($item['scan_source'] ?? 'CAMERA'));
            $source = $source === 'SCANNER' ? 'HARDWARE' : $source;

            $prepared[] = [
                ...$resolved,
                'raw_code' => $rawCode,
                'scan_source' => $source,
            ];
            $skuCounts[$resolved['sku_id']] = ($skuCounts[$resolved['sku_id']] ?? 0) + 1;
        }

        return ['items' => $prepared, 'sku_counts' => $skuCounts];
    }

    private function assertRecordPayloadShape(Request $request): void
    {
        $payload = $request->all();
        $unexpected = array_values(array_diff(array_keys($payload), self::RECORD_FIELDS));

        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (array_diff(array_keys($item), self::RECORD_ITEM_FIELDS) as $field) {
                    $unexpected[] = 'items.'.($index + 1).'.'.$field;
                }
            }
        }

        if ($unexpected === []) {
            return;
        }

        ApiError::abort(422, 'VALIDATION_ERROR', 'Payload inbound/record chi duoc gui cac field da khai bao trong contract.', [
            'unexpected_fields' => array_values(array_unique($unexpected)),
            'allowed_fields' => self::RECORD_FIELDS,
            'allowed_item_fields' => self::RECORD_ITEM_FIELDS,
        ]);
    }

    private function resolveRawCode(string $rawCode, bool $checkOpenReceiving): array
    {
        $rawCode = trim($rawCode);
        $parsed = $this->parser->parse($rawCode);

        if ($parsed->parseStatus === ProductQrPayload::STATUS_UNSUPPORTED_VERSION) {
            ApiError::abort(422, 'UNSUPPORTED_PAYLOAD_VERSION', 'Version cấu trúc QR không được hỗ trợ.', [
                'payload_version' => $parsed->payloadVersion,
            ]);
        }
        if (! $parsed->isValid()) {
            ApiError::abort(422, 'INVALID_PAYLOAD', 'QR/Barcode sai cấu trúc; không thể resolve SKU + item identity.');
        }

        if ($parsed->isComposite()) {
            $sku = $this->identityResolver->requireActiveSkuByCode((string) $parsed->skuCode);
            $item = $this->identityResolver->findItemByUnique((string) $parsed->itemUnique);

            if ($item !== null) {
                $this->identityResolver->assertItemMatchesSku($item, $sku, $parsed);
                $this->assertItemReceivable($item);
                if ($checkOpenReceiving) {
                    $this->assertExistingItemNotReceiving((string) $item->item_id);
                }

                return $this->resolvedPayload($rawCode, $parsed, $sku, $item, 'EXISTING_ITEM');
            }

            if ($this->identityResolver->identityOccupied((string) $parsed->itemUnique)) {
                ApiError::abort(409, 'ITEM_ALREADY_REGISTERED', 'item_unique đã bị một định danh khác chiếm dụng.', [
                    'item_unique' => $parsed->itemUnique,
                ]);
            }
            if ($checkOpenReceiving) {
                $this->assertCandidateNotReceiving((string) $parsed->itemUnique);
            }

            return $this->resolvedPayload($rawCode, $parsed, $sku, null, 'NEW_ITEM_CANDIDATE');
        }

        $item = $this->identityResolver->findItemByUnique((string) $parsed->normalizedCodeValue);
        if ($item === null) {
            ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR legacy không tồn tại hoặc không trỏ tới item đã đăng ký.');
        }
        $this->assertItemReceivable($item);
        if ($checkOpenReceiving) {
            $this->assertExistingItemNotReceiving((string) $item->item_id);
        }

        $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();
        if ($sku === null) {
            ApiError::abort(422, 'SKU_NOT_FOUND', 'SKU của item không tồn tại trong master data.');
        }
        if (strtoupper((string) $sku->status) !== 'ACTIVE') {
            ApiError::abort(422, 'SKU_INACTIVE', 'SKU của item đang không ACTIVE.');
        }

        return $this->resolvedPayload($rawCode, $parsed, $sku, $item, 'LEGACY_ITEM');
    }

    private function resolvedPayload(string $rawCode, ProductQrPayload $parsed, object $sku, ?object $item, string $resolutionStatus): array
    {
        return [
            'raw_code' => $rawCode,
            'payload_format' => $parsed->format,
            'payload_version' => $parsed->payloadVersion,
            'sku_id' => (string) $sku->sku_id,
            'sku_code' => (string) $sku->sku_code,
            'sku_name' => (string) ($sku->sku_name ?? ''),
            'sku_type' => strtoupper((string) ($sku->sku_type ?? '')),
            'item_id' => $item?->item_id !== null ? (string) $item->item_id : null,
            'item_unique' => $parsed->isComposite() ? (string) $parsed->itemUnique : (string) ($parsed->normalizedCodeValue ?? ''),
            'serial_number' => $parsed->isComposite() ? $parsed->serialNumber : ($item?->serial_number ?? null),
            'normalized_code_value' => $parsed->normalizedCodeValue,
            'item_status' => $item?->item_status ?? null,
            'resolution_status' => $resolutionStatus,
        ];
    }

    private function assertCanonicalWarehouse(string $warehouseId): void
    {
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first(['id', 'code', 'name', 'status']);
        if ($warehouse === null) {
            ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
        }
        if (strtoupper((string) $warehouse->status) !== 'ACTIVE') {
            ApiError::abort(422, 'WAREHOUSE_INACTIVE', 'Kho đang không ACTIVE.');
        }
        if (strtoupper(trim((string) $warehouse->code)) !== self::CANONICAL_WAREHOUSE_CODE) {
            ApiError::abort(422, 'INBOUND_WAREHOUSE_NOT_CANONICAL', 'Luồng nhập kho MVP chỉ dùng Kho tổng Hoa Nam.', [
                'required_warehouse_code' => self::CANONICAL_WAREHOUSE_CODE,
            ]);
        }
    }

    private function assertItemReceivable(object $item): void
    {
        if (! in_array((string) ($item->item_status ?? ''), ['NEW', 'ADJUSTED_OUT'], true)) {
            ApiError::abort(422, 'ITEM_NOT_RECEIVABLE', 'Item đã nằm trong luồng tồn khác và không thể nhập lại.', [
                'item_id' => (string) ($item->item_id ?? ''),
                'item_status' => (string) ($item->item_status ?? ''),
            ]);
        }
    }

    private function assertExistingItemNotReceiving(string $itemId): void
    {
        $exists = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            ->where('e.item_id', $itemId)
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '<>', 'REMOVED');
            })
            ->exists();

        if ($exists) {
            ApiError::abort(409, 'ITEM_ALREADY_RECEIVING', 'Item đang thuộc một inbound chưa Post khác.');
        }
    }

    private function assertCandidateNotReceiving(string $itemUnique): void
    {
        if (! Schema::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            ApiError::abort(422, 'INVALID_PAYLOAD', 'Schema chưa hỗ trợ new item candidate; hãy chạy migration mới nhất.');
        }

        $exists = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            ->where('e.candidate_item_unique', strtoupper(trim($itemUnique)))
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '<>', 'REMOVED');
            })
            ->exists();

        if ($exists) {
            ApiError::abort(409, 'ITEM_ALREADY_RECEIVING', 'Item candidate đang thuộc một inbound chưa Post khác.');
        }
    }

    private function attachScanEventToEvidence(string $documentId, string $lineId, mixed $itemId, mixed $itemUnique, string $rawCode, string $scanSource): ?string
    {
        $scanEventId = null;
        if (Schema::hasColumn('inbound_scan_entries', 'scan_event_id')
            && (Schema::hasTable('scan_event') || Schema::hasView('scan_event'))) {
            $eventQuery = DB::table('scan_event as se')
                ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
                ->where('sb.source_id', $documentId);
            if (Schema::hasColumn('scan_event', 'raw_code')) {
                $eventQuery->where('se.raw_code', trim($rawCode));
            }
            if ($itemId !== null) {
                $eventQuery->where('se.resolved_object_id', $itemId);
            }
            $scanEventId = $eventQuery->orderByDesc('se.created_at')->value('se.scan_event_id');
        }

        $entryQuery = DB::table('inbound_scan_entries')
            ->where('inbound_line_id', $lineId)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '<>', 'REMOVED');
            });

        if ($itemId !== null) {
            $entryQuery->where('item_id', $itemId);
        } elseif ($itemUnique !== null && Schema::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            $entryQuery->where('candidate_item_unique', strtoupper(trim((string) $itemUnique)));
        }

        $entry = $entryQuery->orderByDesc('created_at')->first();
        if ($entry === null) {
            ApiError::abort(500, 'SCAN_EVIDENCE_NOT_FOUND', 'Không tìm thấy scan entry vừa tạo trong atomic record.');
        }

        $updates = ['scan_source' => $scanSource, 'updated_at' => now()];
        if ($scanEventId !== null && Schema::hasColumn('inbound_scan_entries', 'scan_event_id')) {
            $updates['scan_event_id'] = $scanEventId;
        }
        DB::table('inbound_scan_entries')->where('id', $entry->id)->update($updates);

        return (string) $entry->id;
    }

    private function progressForDocument(string $documentId): array
    {
        $document = DB::table('stock_documents')->where('id', $documentId)->first();
        $lines = DB::table('stock_document_lines')->where('stock_document_id', $documentId)->get();
        $expected = (int) $lines->sum(fn ($line) => (int) $line->qty_planned);
        $scanned = (int) $lines->sum(fn ($line) => (int) $line->scanned_qty);
        $ready = $document !== null
            && (string) $document->status === 'SCANNING'
            && $lines->isNotEmpty()
            && $lines->every(fn ($line) => (int) $line->scanned_qty === (int) $line->qty_planned);

        return [
            'expected_total_qty' => $expected,
            'scanned_total_qty' => $scanned,
            'remaining_qty' => max(0, $expected - $scanned),
            'ready_for_post' => $ready,
            'mini_app_status' => $ready ? 'WAITING_APPROVAL' : (string) ($document->status ?? 'SCANNING'),
        ];
    }

    private function skuLineSummary(string $documentId): array
    {
        return DB::table('stock_document_lines as l')
            ->join('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $documentId)
            ->orderBy('l.line_no')
            ->get([
                'l.id as line_id',
                'l.line_no',
                'l.product_id as sku_id',
                's.sku_code',
                's.sku_name',
                'l.qty_planned',
                'l.scanned_qty',
            ])
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

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
}
