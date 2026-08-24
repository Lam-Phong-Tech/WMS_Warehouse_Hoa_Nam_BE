<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\ProductQrPayload;

/**
 * Local-staging boundary for Mini App PRODUCT outbound.
 *
 * The Mini App keeps the pre-confirm camera batch locally. resolve-code only
 * resolves/validates an existing stock item and never creates a WMS document.
 * record re-resolves the complete N/N batch, creates the canonical outbound
 * source chain, then replays every raw scan through the canonical outbound scan
 * handler in one transaction. Any failure rolls back the complete handoff.
 *
 * This controller never decreases inventory. Only Post Issue may do that.
 */
class MiniAppOutboundStagingController extends Controller
{
    use ApiResponse;

    private const MAX_BATCH_ITEMS = 1000;
    private const RECORD_FIELDS = [
        'name',
        'warehouse_id',
        'request_no',
        'request_date',
        'recipient_name',
        'recipient_address',
        'recipient_contact_phone',
        'note',
        'expected_total_qty',
        'items',
    ];
    private const RECORD_ITEM_FIELDS = [
        'raw_code',
        'scan_source',
    ];

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly ProductIdentityResolver $identityResolver,
        private readonly MiniAppOutboundDraftController $drafts,
        private readonly MiniAppOutboundController $outbound,
    ) {}

    /**
     * Read-only preflight while Mini App fills its local scan rows.
     * No request/document/match/evidence/movement is created here.
     */
    public function resolveCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
        ]);

        $warehouseId = (string) $validated['warehouse_id'];
        $this->assertActiveWarehouse($warehouseId);
        $resolved = $this->resolveRawCode((string) $validated['raw_code'], $warehouseId, false);

        return $this->respondSuccess([
            ...$resolved,
            'warehouse_id' => $warehouseId,
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'match_created' => false,
            'scan_evidence_created' => false,
            'wms_document_created' => false,
        ], $resolved['eligible_for_outbound']
            ? 'Mã hợp lệ để đưa vào batch xuất local; chưa tạo phiếu WMS.'
            : 'Đã resolve mã nhưng item hiện chưa đủ điều kiện xuất; chưa tạo phiếu WMS.');
    }

    /**
     * Atomic handoff after local N/N review.
     *
     * Client sends raw scans only; SKU and planned lines are server-derived.
     */
    public function record(Request $request): JsonResponse
    {
        $this->assertRecordPayloadShape($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'request_no' => ['nullable', 'string', 'max:80'],
            'request_date' => ['nullable', 'date_format:Y-m-d'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'recipient_address' => ['nullable', 'string', 'max:1000'],
            'recipient_contact_phone' => ['nullable', 'string', 'max:50', 'regex:/^0\d{9}$/'],
            'note' => ['nullable', 'string', 'max:1000'],
            'expected_total_qty' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'items.*.scan_source' => ['nullable', 'string', 'in:CAMERA,SCANNER,HARDWARE,MANUAL'],
        ], [
            'recipient_contact_phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.',
        ]);

        if (count($validated['items']) !== (int) $validated['expected_total_qty']) {
            ApiError::abort(422, 'SCAN_COUNT_MISMATCH', 'Số dòng scan local phải đúng bằng số lượng đã nhập trước khi xác nhận.', [
                'expected_total_qty' => (int) $validated['expected_total_qty'],
                'scanned_total_qty' => count($validated['items']),
            ]);
        }

        $warehouseId = (string) $validated['warehouse_id'];
        $this->assertActiveWarehouse($warehouseId);

        $actorId = $this->actorId();
        $idempotencyKey = IdempotencyKeyGuard::require($request);
        $requestHash = IdempotencyStore::requestHash($request);

        [$code, $body, $replayed] = IdempotencyStore::handle(
            $idempotencyKey,
            'mini_app_outbound_record',
            $actorId,
            $requestHash,
            function () use ($validated, $request, $actorId, $idempotencyKey, $warehouseId): array {
                $result = DB::transaction(function () use ($validated, $request, $actorId, $idempotencyKey, $warehouseId): array {
                    $prepared = $this->prepareBatch((array) $validated['items'], $warehouseId);
                    $this->assertAggregateAvailability($warehouseId, $prepared['sku_counts']);

                    $draftPayload = [
                        'warehouse_id' => $warehouseId,
                        'name' => trim((string) $validated['name']),
                        'request_no' => $validated['request_no'] ?? null,
                        'request_date' => $validated['request_date'] ?? null,
                        'recipient_name' => trim((string) $validated['recipient_name']),
                        'recipient_address' => $validated['recipient_address'] ?? null,
                        'recipient_contact_phone' => $validated['recipient_contact_phone'] ?? null,
                        'note' => $validated['note'] ?? null,
                        'expected_total_qty' => (int) $validated['expected_total_qty'],
                        'lines' => [],
                    ];

                    foreach ($prepared['sku_counts'] as $skuId => $quantity) {
                        $draftPayload['lines'][] = [
                            'sku_id' => $skuId,
                            'required_qty' => $quantity,
                        ];
                    }

                    // Keep the existing direct-create endpoint as a compatibility
                    // surface, while record composes it internally so the canonical
                    // request/import/document trace chain stays identical.
                    $draftKey = 'ma-stage-draft-'.substr(hash('sha256', $actorId.'|'.$idempotencyKey), 0, 48);
                    $draftRequest = $this->childRequest($request, $draftPayload, [
                        'Idempotency-Key' => $draftKey,
                    ]);
                    $draftResponse = $this->drafts->store($draftRequest);
                    $draftBody = $draftResponse->getData(true);
                    $draft = is_array($draftBody['data'] ?? null) ? $draftBody['data'] : null;
                    if ($draftResponse->getStatusCode() !== 201 || $draft === null) {
                        ApiError::abort(409, 'OUTBOUND_DRAFT_CREATE_FAILED', 'Không thể tạo phiếu xuất từ batch local; toàn bộ transaction sẽ rollback.');
                    }

                    $documentId = (string) ($draft['document_id'] ?? '');
                    $version = (int) ($draft['version'] ?? 1);
                    if ($documentId === '') {
                        ApiError::abort(409, 'OUTBOUND_DRAFT_CREATE_FAILED', 'BE không trả document_id sau khi tạo phiếu xuất.');
                    }

                    $scanResults = [];
                    foreach ($prepared['items'] as $index => $item) {
                        $scanRequest = $this->childRequest($request, [
                            'code_value' => $item['raw_code'],
                            'quantity' => 1,
                            'scan_source' => $item['scan_source'],
                        ], [
                            'If-Match' => '"'.$version.'"',
                        ]);

                        // Canonical scan is authoritative: it re-resolves identity,
                        // rechecks reservation/availability, creates scan evidence,
                        // bumps document version and creates the Packing Label when
                        // the final planned item is matched.
                        $scanResponse = $this->outbound->scan($scanRequest, $documentId);
                        $scanBody = $scanResponse->getData(true);
                        $scanData = is_array($scanBody['data'] ?? null) ? $scanBody['data'] : null;
                        if ($scanResponse->getStatusCode() !== 200 || $scanData === null) {
                            ApiError::abort(409, 'ATOMIC_OUTBOUND_SCAN_FAILED', 'Không thể ghi toàn bộ batch scan; transaction sẽ rollback.', [
                                'row_no' => $index + 1,
                            ]);
                        }

                        $version = (int) ($scanData['version'] ?? ($version + 1));
                        $scanResults[] = [
                            'row_no' => $index + 1,
                            'raw_code' => $item['raw_code'],
                            'sku_id' => $item['sku_id'],
                            'sku_code' => $item['sku_code'],
                            'sku_name' => $item['sku_name'],
                            'item_id' => $item['item_id'],
                            'item_unique' => $item['item_unique'],
                            'serial_number' => $item['serial_number'],
                            'scan_source' => $item['scan_source'],
                            'outbound_item_match_id' => $scanData['outbound_item_match_id'] ?? ($scanData['match_id'] ?? null),
                        ];
                    }

                    $progress = $this->progressForDocument($documentId);
                    if (! $progress['full_scan']) {
                        ApiError::abort(409, 'ATOMIC_RECORD_INCOMPLETE', 'Batch N/N đã gửi nhưng phiếu chưa full-scan; toàn bộ transaction sẽ rollback.', $progress);
                    }

                    $label = DB::table('label_snapshots')
                        ->where('outbound_document_id', $documentId)
                        ->orderByDesc('created_at')
                        ->first();
                    if ($label === null) {
                        ApiError::abort(409, 'PACKING_LABEL_NOT_CREATED', 'Full scan phải tạo Packing Label DRAFT; toàn bộ transaction sẽ rollback.');
                    }

                    $document = DB::table('stock_documents')->where('id', $documentId)->first();
                    if ($document === null) {
                        ApiError::abort(409, 'OUTBOUND_DOCUMENT_NOT_FOUND', 'Phiếu xuất vừa tạo không còn tồn tại.');
                    }

                    AuditLogger::record(
                        action: 'MINI_APP_RECORD_OUTBOUND',
                        objectType: 'stock_document',
                        objectId: $documentId,
                        actorId: $actorId,
                        requestId: request()?->attributes->get('request_id'),
                        afterData: [
                            'status' => (string) $document->status,
                            'mini_app_status' => 'LABELING',
                            'expected_total_qty' => $progress['expected_total_qty'],
                            'scanned_total_qty' => $progress['scanned_total_qty'],
                            'scan_evidence_count' => count($scanResults),
                            'packing_label_id' => (string) $label->id,
                            'stock_effect' => 'NONE',
                            'version' => $version,
                        ],
                        idempotencyKey: $idempotencyKey,
                    );

                    return [
                        'document_id' => $documentId,
                        'doc_no' => (string) $document->doc_no,
                        'outbound_request_id' => $document->outbound_request_id !== null ? (string) $document->outbound_request_id : null,
                        'request_no' => $draft['request_no'] ?? null,
                        'name' => trim((string) $validated['name']),
                        'src_warehouse_id' => $warehouseId,
                        'status' => (string) $document->status,
                        'mini_app_status' => 'LABELING',
                        'full_scan' => true,
                        'ready_for_issue' => false,
                        'expected_total_qty' => $progress['expected_total_qty'],
                        'scanned_total_qty' => $progress['scanned_total_qty'],
                        'remaining_qty' => $progress['remaining_qty'],
                        'progress_percent' => 100,
                        'version' => $version,
                        'stock_effect' => 'NONE',
                        'movement_created' => false,
                        'scan_evidence_created' => true,
                        'scan_evidence_count' => count($scanResults),
                        'packing_label' => [
                            'id' => (string) $label->id,
                            'status' => (string) $label->status,
                            'applied' => $label->applied_at !== null,
                        ],
                        'sku_lines' => $this->skuLineSummary($documentId),
                        'items' => $scanResults,
                    ];
                }, 3);

                return [201, $this->envelope('Đã ghi nhận batch xuất N/N; phiếu đã full-scan và đang chờ xử lý Packing Label. Chưa trừ tồn.', $result)];
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
    private function prepareBatch(array $items, string $warehouseId): array
    {
        $prepared = [];
        $skuCounts = [];
        $seen = [];

        foreach (array_values($items) as $index => $item) {
            $rawCode = trim((string) $item['raw_code']);
            $resolved = $this->resolveRawCode($rawCode, $warehouseId, true);
            $identityKey = 'ITEM:'.strtolower((string) $resolved['item_id']);
            if (isset($seen[$identityKey])) {
                ApiError::abort(409, 'DUPLICATE_ITEM_IN_BATCH', 'Một item xuất hiện nhiều hơn một lần trong batch xuất local.', [
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

    private function resolveRawCode(string $rawCode, string $warehouseId, bool $strictEligibility): array
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
            if ($item === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Item trong QR chưa tồn tại trong WMS nên không thể xuất kho.', [
                    'item_unique' => $parsed->itemUnique,
                ]);
            }
            $this->identityResolver->assertItemMatchesSku($item, $sku, $parsed);
        } else {
            $item = $this->identityResolver->findItemByUnique((string) $parsed->normalizedCodeValue);
            if ($item === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR legacy không tồn tại hoặc không trỏ tới item đã đăng ký.');
            }
            $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();
            if ($sku === null) {
                ApiError::abort(422, 'SKU_NOT_FOUND', 'SKU của item không tồn tại trong master data.');
            }
            if (strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_INACTIVE', 'SKU của item đang không ACTIVE.');
            }
        }

        if (strtoupper((string) ($sku->sku_type ?? '')) !== 'PRODUCT') {
            ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Luồng Mini App outbound local-staging chỉ nhận PRODUCT.');
        }

        $reservation = $this->activeReservation((string) $item->item_id);
        $availableQty = $this->availableQty($warehouseId, (string) $sku->sku_id);
        $eligibilityCode = 'ELIGIBLE';
        $eligible = true;

        if (strtoupper((string) ($item->item_status ?? '')) !== 'IN_STOCK') {
            $eligible = false;
            $eligibilityCode = 'ITEM_NOT_AVAILABLE';
        } elseif ($reservation !== null) {
            $eligible = false;
            $eligibilityCode = 'ITEM_ALREADY_MATCHED';
        } elseif ($availableQty < 1) {
            $eligible = false;
            $eligibilityCode = 'INSUFFICIENT_STOCK';
        }

        if ($strictEligibility && ! $eligible) {
            $details = [
                'item_id' => (string) $item->item_id,
                'item_status' => (string) ($item->item_status ?? ''),
                'sku_id' => (string) $sku->sku_id,
                'sku_code' => (string) $sku->sku_code,
                'warehouse_id' => $warehouseId,
                'available_qty' => $availableQty,
            ];
            if ($reservation !== null) {
                $details['reserved_by'] = $reservation;
            }

            ApiError::abort(
                $eligibilityCode === 'ITEM_ALREADY_MATCHED' ? 409 : 422,
                $eligibilityCode,
                match ($eligibilityCode) {
                    'ITEM_NOT_AVAILABLE' => 'Item hiện không ở trạng thái IN_STOCK nên không thể xuất.',
                    'ITEM_ALREADY_MATCHED' => 'Item đang được một phiếu xuất khác giữ.',
                    default => 'SKU hiện không còn available_qty để ghi nhận batch xuất.',
                },
                $details
            );
        }

        return [
            'raw_code' => $rawCode,
            'payload_format' => $parsed->format,
            'payload_version' => $parsed->payloadVersion,
            'sku_id' => (string) $sku->sku_id,
            'sku_code' => (string) $sku->sku_code,
            'sku_name' => (string) ($sku->sku_name ?? ''),
            'sku_type' => strtoupper((string) ($sku->sku_type ?? '')),
            'item_id' => (string) $item->item_id,
            'item_unique' => $parsed->isComposite()
                ? (string) $parsed->itemUnique
                : (string) ($parsed->normalizedCodeValue ?? ''),
            'serial_number' => $parsed->isComposite()
                ? $parsed->serialNumber
                : ($item->serial_number ?? null),
            'normalized_code_value' => $parsed->normalizedCodeValue,
            'item_status' => (string) ($item->item_status ?? ''),
            'resolution_status' => $parsed->isComposite() ? 'EXISTING_ITEM' : 'LEGACY_ITEM',
            'eligible_for_outbound' => $eligible,
            'eligibility_code' => $eligibilityCode,
            'available_qty' => $availableQty,
            'reservation' => $reservation,
        ];
    }

    /** @param array<string,int> $skuCounts */
    private function assertAggregateAvailability(string $warehouseId, array $skuCounts): void
    {
        foreach ($skuCounts as $skuId => $requiredQty) {
            $available = $this->availableQty($warehouseId, (string) $skuId);
            if ($available < $requiredQty) {
                $sku = DB::table('sku')->where('sku_id', $skuId)->first();
                ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Số item đã quét của một SKU vượt available_qty hiện tại.', [
                    'warehouse_id' => $warehouseId,
                    'sku_id' => (string) $skuId,
                    'sku_code' => $sku?->sku_code,
                    'required_qty' => $requiredQty,
                    'available_qty' => $available,
                ]);
            }
        }
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

        ApiError::abort(422, 'VALIDATION_ERROR', 'Payload outbound/record chi duoc gui cac field da khai bao trong contract.', [
            'unexpected_fields' => array_values(array_unique($unexpected)),
            'allowed_fields' => self::RECORD_FIELDS,
            'allowed_item_fields' => self::RECORD_ITEM_FIELDS,
        ]);
    }

    private function availableQty(string $warehouseId, string $skuId): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $skuId)
            ->where('stock_status', 'AVAILABLE')
            ->sum('available_qty');
    }

    private function activeReservation(string $itemId): ?array
    {
        $row = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('m.item_id', $itemId)
            ->where('m.status', 'ACTIVE')
            ->where('d.doc_type', 'issue')
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            ->select([
                'm.id as match_id',
                'd.id as document_id',
                'd.doc_no',
                'd.status',
                'd.outbound_request_id',
            ])
            ->first();

        return $row === null ? null : [
            'match_id' => (string) $row->match_id,
            'document_id' => (string) $row->document_id,
            'doc_no' => (string) $row->doc_no,
            'status' => (string) $row->status,
            'outbound_request_id' => $row->outbound_request_id !== null ? (string) $row->outbound_request_id : null,
        ];
    }

    private function assertActiveWarehouse(string $warehouseId): void
    {
        if (! DB::table('warehouses')->where('id', $warehouseId)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Kho nguồn không tồn tại hoặc không ACTIVE.');
        }
        if (DB::table('warehouses')->where('status', 'ACTIVE')->count() !== 1) {
            ApiError::abort(422, 'ACTIVE_WAREHOUSE_BASELINE_VIOLATION', 'MVP yêu cầu chính xác một kho Hoa Nam ACTIVE.');
        }
    }

    private function progressForDocument(string $documentId): array
    {
        $row = DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->selectRaw('COALESCE(SUM(qty_planned),0) as expected_qty, COALESCE(SUM(scanned_qty),0) as scanned_qty, COUNT(*) as line_count')
            ->first();

        $expected = (int) ($row->expected_qty ?? 0);
        $scanned = (int) ($row->scanned_qty ?? 0);

        return [
            'expected_total_qty' => $expected,
            'scanned_total_qty' => $scanned,
            'remaining_qty' => max($expected - $scanned, 0),
            'full_scan' => (int) ($row->line_count ?? 0) > 0 && $expected > 0 && $expected === $scanned,
        ];
    }

    private function skuLineSummary(string $documentId): array
    {
        return DB::table('stock_document_lines as l')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $documentId)
            ->orderBy('l.line_no')
            ->get([
                'l.id as line_id',
                'l.line_no',
                'l.product_id as sku_id',
                's.sku_code',
                's.sku_name',
                'l.qty_planned as required_qty',
                'l.scanned_qty',
            ])
            ->map(fn (object $row): array => [
                'line_id' => (string) $row->line_id,
                'line_no' => (int) $row->line_no,
                'sku_id' => (string) $row->sku_id,
                'sku_code' => $row->sku_code,
                'sku_name' => $row->sku_name,
                'required_qty' => (int) $row->required_qty,
                'scanned_qty' => (int) $row->scanned_qty,
            ])
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    private function childRequest(Request $parent, array $payload, array $headers = []): Request
    {
        $child = Request::create(
            $parent->getUri(),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        $child->setUserResolver(fn () => $parent->user());
        foreach ($parent->attributes->all() as $key => $value) {
            $child->attributes->set($key, $value);
        }
        foreach ($headers as $name => $value) {
            $child->headers->set($name, $value);
        }
        $child->headers->set('Accept', 'application/json');

        return $child;
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

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Bạn chưa đăng nhập.');
        }

        return (string) $id;
    }
}
