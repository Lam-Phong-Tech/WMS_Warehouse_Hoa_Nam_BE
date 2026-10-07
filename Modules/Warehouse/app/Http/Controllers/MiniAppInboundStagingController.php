<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Database\SchemaCache;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Events\InboundSkuInfoPending;
use Modules\Warehouse\Services\BoxCodeParser;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Services\SkuAggregateService;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use Modules\Warehouse\Support\ProductQrPayload;
use Modules\Warehouse\Support\SkuInboundInfoRule;
use Throwable;

/**
 * Mini App local-staging boundary for inbound intake (PRODUCT item + COMPONENT box).
 *
 * The camera phase stays local on the Mini App. This controller only provides:
 * 1) read-only preflight resolve for a raw QR/barcode; and
 * 2) one atomic "record" command that creates the WMS receipt + planned SKU lines
 *    + every scan evidence in the same database transaction.
 *
 * Loại dòng do CHÍNH MÃ QUÉT quyết định, không do cờ client chọn trước:
 *  - mã hộp `BOX-<SKU>-<số hộp>` (BoxCodeParser) -> item_type=BOX, SKU COMPONENT,
 *    ghi nhận theo `quantity` đếm tay;
 *  - còn lại -> item_type=ITEM, mỗi mã là một hiện vật (+1).
 * `item_type` client gửi lên chỉ để đối chiếu; lệch với mã quét thì 422 thay vì
 * đoán hộ.
 *
 * Inventory is never increased here. The authoritative stock mutation remains
 * POST /api/v1/inbound-documents/{id}/post-receipt.
 */
class MiniAppInboundStagingController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    private const CANONICAL_WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const MAX_BATCH_ITEMS = 1000;

    /** Trần số lượng đếm tay của MỘT hộp linh kiện. */
    private const MAX_BOX_QUANTITY = 100000;

    private const RECORD_FIELDS = [
        'name',
        // Alias của dst_warehouse_id: bước resolve-code nhận `warehouse_id` nên
        // Mini App gửi lại đúng field đó ở bước record. Xem normalizeWarehouseAlias().
        'warehouse_id',
        'dst_warehouse_id',
        'expected_total_qty',
        'doc_date',
        'note',
        'items',
    ];

    // expected_total_qty giữ trong whitelist để không vỡ client cũ còn gửi field
    // này, nhưng không còn required/validate khớp — nhận đúng số lượng đã quét
    // thực tế, không cần khai trước ("quét đến đâu nhận đến đấy").
    private const RECORD_ITEM_FIELDS = [
        'raw_code',
        'scan_source',
        // Loại dòng + số lượng: BE tự nhận diện từ raw_code, client gửi lên để
        // đối chiếu (item_type) và để khai số đếm tay của hộp (quantity).
        'item_type',
        'quantity',
        'box_number',
        // Trả lời của người quét cho câu hỏi "Sản phẩm hay Linh kiện?" khi mã
        // mang một sku_code chưa từng đăng ký (resolve-code báo trước bằng
        // requires_new_sku_type). Bỏ trống = giữ hành vi cũ: tạo PRODUCT.
        'new_sku_type',
        // + RECORD_ITEM_ECHO_FIELDS (ghép trong recordItemFields()).
    ];

    /**
     * Field client chép nguyên từ response resolve-code về row local rồi gửi lại.
     *
     * Được NHẬN để Mini App không phải bóc tách row trước khi submit, nhưng
     * KHÔNG tham gia quyết định: raw_code luôn là nguồn sự thật và BE resolve lại
     * từ đầu. Riêng `sku_code` được đối chiếu (assertEchoSkuCodeMatches) vì lệch
     * sku_code là dấu hiệu row local đã cũ so với mã thật.
     */
    private const RECORD_ITEM_ECHO_FIELDS = [
        'sku_id',
        'sku_code',
        'sku_name',
        'sku_type',
        'item_id',
        'item_unique',
        'item_status',
        'serial_number',
        'payload_format',
        'payload_version',
        'normalized_code_value',
        'resolution_status',
        'box_code',
        'requires_quantity',
        'is_serial_tracked',
        'requires_new_sku_type',
    ];

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly ProductIdentityResolver $identityResolver,
        private readonly CurrentBaselineStockService $stock,
        private readonly SkuAggregateService $skuAggregate,
        private readonly BoxCodeParser $boxParser,
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
        // Preview thuần đọc: KHÔNG tự tạo SKU ở đây (giữ đúng cam kết "chưa ghi
        // nhận vào WMS" của resolve-code). Nếu QR hợp lệ nhưng sku_code chưa
        // tồn tại, resolveRawCode() trả về resolution_status=NEW_SKU_CANDIDATE
        // thay vì abort — SKU thật sự chỉ được tạo khi record() chạy.
        $resolved = $this->resolveScannedCode((string) $validated['raw_code'], true, false);

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
     * Nhận diện loại mã rồi resolve tương ứng.
     *
     * Mã hộp `BOX-<SKU>-<số hộp>` tự mang SKU COMPONENT nên không đi qua
     * ProductQrPayloadParser (parser đó chỉ hiểu QR item composite/legacy và sẽ
     * trả INVALID_PAYLOAD cho mã hộp).
     *
     * @return array<string,mixed>
     */
    private function resolveScannedCode(
        string $rawCode,
        bool $checkOpenReceiving,
        bool $allowAutoCreateSku,
        ?string $newSkuType = null
    ): array {
        $box = $this->boxParser->parse($rawCode);
        if ($box !== null) {
            return [
                ...$this->resolveBoxCode($box, $allowAutoCreateSku),
                // Mã hộp tự mang loại (COMPONENT đếm theo hộp) nên không phải hỏi ai.
                'requires_new_sku_type' => false,
            ];
        }

        $resolved = $this->resolveRawCode($rawCode, $checkOpenReceiving, $allowAutoCreateSku, $newSkuType);

        return [
            ...$resolved,
            'item_type' => 'ITEM',
            'box_code' => null,
            'box_number' => null,
            // Mỗi mã item là một hiện vật: FE không hỏi số lượng.
            'requires_quantity' => false,
            // SKU chưa từng đăng ký: Mini App phải hỏi "Sản phẩm hay Linh kiện?"
            // NGAY khi quét rồi gửi kèm new_sku_type ở bước record — cùng câu hỏi
            // mà kênh web hỏi qua 422 NEW_SKU_TYPE_REQUIRED. Không hỏi thì vẫn
            // chạy như trước (auto PRODUCT), chỉ là đoán hộ.
            'requires_new_sku_type' => ($resolved['resolution_status'] ?? null) === 'NEW_SKU_CANDIDATE',
        ];
    }

    /**
     * Mã HỘP linh kiện: SKU nằm ngay trong mã nên quét hộp là biết SKU COMPONENT.
     * Preview (allowAutoCreateSku=false) không ghi gì — SKU chưa có thì trả
     * NEW_SKU_CANDIDATE để FE báo "linh kiện mới" y như nhánh item.
     *
     * @param  array{box_code: string, sku_code: string, box_seq: string}  $box
     * @return array<string,mixed>
     */
    private function resolveBoxCode(array $box, bool $allowAutoCreateSku): array
    {
        $sku = $this->resolveOrCreateSku($box['sku_code'], $allowAutoCreateSku, 'COMPONENT', false);

        if ($sku !== null && strtoupper((string) ($sku->sku_type ?? '')) !== 'COMPONENT') {
            ApiError::abort(422, 'BOX_SKU_NOT_COMPONENT', 'Mã hộp trỏ tới SKU không phải linh kiện (COMPONENT).', [
                'box_code' => $box['box_code'],
                'sku_code' => $box['sku_code'],
                'sku_type' => strtoupper((string) ($sku->sku_type ?? '')),
            ]);
        }

        return [
            'raw_code' => $box['box_code'],
            'payload_format' => 'BOX_CODE',
            'payload_version' => null,
            'item_type' => 'BOX',
            'box_code' => $box['box_code'],
            'box_number' => $box['box_seq'],
            // Linh kiện nhỏ không dán mã từng cái: FE phải hiện ô nhập số lượng.
            'requires_quantity' => true,
            'sku_id' => $sku !== null ? (string) $sku->sku_id : null,
            'sku_code' => $sku !== null ? (string) $sku->sku_code : $box['sku_code'],
            'sku_name' => $sku !== null ? (string) ($sku->sku_name ?? '') : null,
            'sku_type' => 'COMPONENT',
            'item_id' => null,
            'item_unique' => null,
            'serial_number' => null,
            'normalized_code_value' => $box['box_code'],
            'item_status' => null,
            'resolution_status' => $sku !== null ? 'EXISTING_BOX_SKU' : 'NEW_SKU_CANDIDATE',
        ];
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
        $this->normalizeWarehouseAlias($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'warehouse_id' => ['nullable', 'uuid'],
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'expected_total_qty' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'doc_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'items.*.scan_source' => ['nullable', 'string', 'in:CAMERA,SCANNER,HARDWARE,MANUAL'],
            'items.*.item_type' => ['nullable', 'string', 'in:ITEM,BOX'],
            'items.*.new_sku_type' => ['nullable', 'string', 'in:PRODUCT,COMPONENT'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BOX_QUANTITY],
            // box_number nhận cả '0001' lẫn 1 (FE gửi số hộp kiểu nào cũng được);
            // đối chiếu theo giá trị số ở assertBoxNumberMatches().
            'items.*.box_number' => ['nullable', 'regex:/^[0-9]{1,20}$/'],
        ]);

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
                            // Số lượng, không phải số dòng: một dòng BOX mang
                            // nhiều đơn vị linh kiện.
                            'expected_total_qty' => (int) array_sum($prepared['sku_counts']),
                            'scan_row_count' => count($prepared['items']),
                        ],
                        idempotencyKey: $idempotencyKey,
                    );

                    $version = 1;
                    $scanResults = [];
                    foreach ($prepared['items'] as $index => $item) {
                        $isBox = ($item['item_type'] ?? 'ITEM') === 'BOX';
                        $payload = [
                            'line_id' => $lineIdsBySku[$item['sku_id']],
                            'quantity' => (int) $item['quantity'],
                            'code_value' => $item['raw_code'],
                            'scan_source' => $item['scan_source'],
                        ];

                        // Mã hộp: DiagnosticCurrentBaselineStockService nhận diện
                        // code_value là BOX, tự đăng ký/tái dùng container rồi đổi
                        // payload sang dạng đếm theo hộp (line_id + container_id).
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
                            itemUnique: $isBox ? null : ($scanResult['item_unique'] ?? null),
                            rawCode: $item['raw_code'],
                            scanSource: $item['scan_source'],
                            boxCode: $isBox ? (string) $item['box_code'] : null,
                        );

                        $scanResults[] = [
                            'row_no' => $index + 1,
                            'raw_code' => $item['raw_code'],
                            'item_type' => $isBox ? 'BOX' : 'ITEM',
                            'quantity' => (int) $item['quantity'],
                            'box_code' => $item['box_code'] ?? null,
                            'box_number' => $item['box_number'] ?? null,
                            'sku_id' => $item['sku_id'],
                            'sku_code' => $item['sku_code'],
                            'sku_name' => $item['sku_name'],
                            'item_id' => $scanResult['item_id'] ?? null,
                            'item_unique' => $isBox ? null : ($scanResult['item_unique'] ?? $item['item_unique']),
                            'resolution_status' => $isBox
                                ? $item['resolution_status']
                                : ($scanResult['resolution_status'] ?? $item['resolution_status']),
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
                    // Tạo phiếu nhập + bằng chứng quét là stock-neutral (chưa
                    // post-receipt) nhưng đã hiện trong read cache danh sách/chi tiết
                    // phiếu nhập của Mini App. Bump nằm trong closure IdempotencyStore
                    // nên replay không xoá cache vô ích.
                    DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

                    // SKU vừa sinh ra lúc quét mới chỉ có mã (tên tạm = mã, ĐVT
                    // rỗng) nên Post Receipt sẽ bị chặn 422 INBOUND_SKU_INFO_INCOMPLETE.
                    // Phiếu VẪN được ghi nhận và nằm ở "Đang quét nhận hàng" —
                    // người ở web bổ sung tên + ĐVT rồi mới Ghi nhận nhập kho.
                    // Nói ngay trong response để Mini App báo đúng việc phải làm
                    // thay vì để người quét bấm duyệt rồi ăn lỗi nghiệp vụ.
                    // Thông báo được bắn SAU khi transaction đóng (xem cuối
                    // record()) chứ không phải ở đây: DB::afterCommit lồng trong
                    // transaction ngoài sẽ không bao giờ chạy dưới test dùng
                    // RefreshDatabase, mà một luật nghiệp vụ không kiểm được thì
                    // sớm muộn cũng trôi.
                    $pendingInfo = SkuInboundInfoRule::incompleteSkusOfDocument($documentId);

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
                        'product_info_complete' => $pendingInfo === [],
                        'pending_product_info_count' => count($pendingInfo),
                        'skus_pending_info' => $pendingInfo,
                        'can_post' => $pendingInfo === [],
                        'post_blocked_reason' => $pendingInfo === [] ? null : 'INBOUND_SKU_INFO_INCOMPLETE',
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

        // P03 wraps record + draft receipt in an outer transaction. Notify only
        // after that transaction commits; legacy callers keep their original path.
        if ($request->attributes->get('p03.draft_handoff', false)) {
            DB::afterCommit(fn () => $this->notifyPendingSkuInfo($body, $replayed, $actorId));
        } else {
            $this->notifyPendingSkuInfo($body, $replayed, $actorId);
        }

        $response = response()->json($body, $code)
            ->header('Idempotent-Replay', $replayed ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    /**
     * Báo cho người ở web biết vừa có phiếu Mini App cần bổ sung thông tin SKU.
     *
     * Gọi SAU khi transaction đã đóng: phiếu chắc chắn tồn tại rồi mới báo, và
     * replay của cùng một Idempotency-Key không bắn chuông lần hai.
     *
     * @param  mixed  $body
     */
    private function notifyPendingSkuInfo($body, bool $replayed, ?string $actorId): void
    {
        if ($replayed || ! is_array($body)) {
            return;
        }

        $pending = data_get($body, 'data.skus_pending_info');
        $documentId = (string) data_get($body, 'data.document_id', '');
        if (! is_array($pending) || $pending === [] || $documentId === '') {
            return;
        }

        event(new InboundSkuInfoPending(
            documentId: $documentId,
            docNo: (string) data_get($body, 'data.doc_no', ''),
            warehouseId: (string) data_get($body, 'data.dst_warehouse_id', '') ?: null,
            actorId: $actorId,
            skuCodes: array_values(array_filter(array_map(
                static fn ($sku): string => (string) ($sku['sku_code'] ?? ''),
                $pending
            ))),
        ));
    }

    /** @param array<int,array<string,mixed>> $items */
    private function prepareBatch(array $items): array
    {
        $prepared = [];
        $skuCounts = [];
        $seen = [];

        foreach (array_values($items) as $index => $item) {
            $rowNo = $index + 1;
            $rawCode = trim((string) $item['raw_code']);
            $box = $this->boxParser->parse($rawCode);
            $this->assertDeclaredItemTypeMatches($item, $box !== null ? 'BOX' : 'ITEM', $rowNo, $rawCode);

            $source = strtoupper((string) ($item['scan_source'] ?? 'CAMERA'));
            $source = $source === 'SCANNER' ? 'HARDWARE' : $source;

            if ($box !== null) {
                // Mã hộp: SKU COMPONENT lấy thẳng từ mã, số lượng do nhân viên
                // đếm tay nên bắt buộc phải có quantity.
                $resolved = $this->resolveBoxCode($box, true);
                $this->assertEchoSkuCodeMatches($item, (string) $resolved['sku_code'], $rowNo, $rawCode);
                $this->assertBoxNumberMatches($item, $box, $rowNo);

                $quantity = (int) ($item['quantity'] ?? 0);
                if ($quantity <= 0) {
                    ApiError::abort(422, 'BOX_QUANTITY_REQUIRED', 'Mã hộp linh kiện phải kèm quantity (số lượng đếm tay trong hộp).', [
                        'row_no' => $rowNo,
                        'raw_code' => $rawCode,
                        'box_code' => $box['box_code'],
                    ]);
                }

                $identityKey = 'BOX:'.$box['box_code'];
            } else {
                // record() thực sự ghi dữ liệu: cho phép tự tạo SKU tối thiểu khi
                // mã QR composite hợp lệ nhưng sku_code chưa từng đăng ký (BA
                // 2026-08-25, mirror BaM1StockService::scanStockDocument — trước
                // đây requireActiveSkuByCode() chặn cứng ngay bước này nên
                // logic tự tạo SKU ở tầng scan không bao giờ được chạm tới).
                $resolved = $this->resolveScannedCode($rawCode, true, true, $this->declaredNewSkuType($item));
                $this->assertItemCodeCountsOneByOne($resolved, $rowNo);
                $this->assertEchoSkuCodeMatches($item, (string) $resolved['sku_code'], $rowNo, $rawCode);

                // Một mã item = một hiện vật; quantity chỉ có nghĩa với mã hộp.
                $quantity = (int) ($item['quantity'] ?? 1);
                if ($quantity !== 1) {
                    ApiError::abort(422, 'ITEM_QUANTITY_MUST_BE_ONE', 'Mỗi mã item là một hiện vật nên quantity chỉ được là 1 (hoặc bỏ trống); đếm theo số lượng chỉ áp dụng cho mã hộp.', [
                        'row_no' => $rowNo,
                        'raw_code' => $rawCode,
                        'quantity' => $quantity,
                    ]);
                }

                $identityKey = $resolved['item_id'] !== null
                    ? 'ITEM:'.strtolower((string) $resolved['item_id'])
                    : 'CANDIDATE:'.strtoupper((string) $resolved['item_unique']);
            }

            if (isset($seen[$identityKey])) {
                ApiError::abort(409, 'DUPLICATE_ITEM_IN_BATCH', 'Một item/hộp xuất hiện nhiều hơn một lần trong batch local.', [
                    'first_row_no' => $seen[$identityKey],
                    'duplicate_row_no' => $rowNo,
                    'raw_code' => $rawCode,
                ]);
            }
            $seen[$identityKey] = $rowNo;

            $prepared[] = [
                ...$resolved,
                'raw_code' => $rawCode,
                'scan_source' => $source,
                'quantity' => $quantity,
            ];
            $skuCounts[$resolved['sku_id']] = ($skuCounts[$resolved['sku_id']] ?? 0) + $quantity;
        }

        return ['items' => $prepared, 'sku_counts' => $skuCounts];
    }

    /**
     * Trả lời "Sản phẩm / Linh kiện" của người quét cho một dòng, đã chuẩn hoá.
     *
     * @param  array<string,mixed>  $item
     */
    private function declaredNewSkuType(array $item): ?string
    {
        $declared = strtoupper(trim((string) ($item['new_sku_type'] ?? '')));

        return $declared === '' ? null : $declared;
    }

    /**
     * Mã ITEM chỉ ghi nhận được +1 khi hiện vật ĐƯỢC ĐỊNH DANH TỪNG CÁI.
     *
     * PRODUCT luôn thoả. COMPONENT thì tuỳ: linh kiện dán mã từng cái
     * (is_serial_tracked) quét +1 y hệt sản phẩm — đây chính là lựa chọn "Linh
     * kiện" khi người quét gặp SKU mới ở Mini App; còn linh kiện đếm theo hộp
     * (is_serial_tracked=false) thì bắt buộc phải quét mã hộp BOX-<SKU>-<số hộp>
     * vì một mã không nói được trong hộp có bao nhiêu cái.
     *
     * @param  array<string,mixed>  $resolved
     */
    private function assertItemCodeCountsOneByOne(array $resolved, int $rowNo): void
    {
        $type = strtoupper((string) ($resolved['sku_type'] ?? ''));
        if ($type === 'PRODUCT') {
            return;
        }

        if ($type === 'COMPONENT' && ($resolved['is_serial_tracked'] ?? false)) {
            return;
        }

        ApiError::abort(422, 'MINI_APP_COMPONENT_FLOW_REQUIRES_BOX', 'Mã item này thuộc SKU linh kiện; linh kiện không dán mã từng cái phải quét mã hộp BOX-<SKU>-<số hộp>.', [
            'row_no' => $rowNo,
            'sku_code' => $resolved['sku_code'] ?? null,
            'sku_type' => $resolved['sku_type'] ?? null,
        ]);
    }

    /**
     * Loại dòng là kết luận của BE từ chính mã quét. `item_type` client gửi lên
     * chỉ để đối chiếu — lệch nghĩa là row local đã cũ hoặc FE tự đoán sai, phải
     * báo lỗi thay vì âm thầm ghi theo một trong hai.
     *
     * @param  array<string,mixed>  $item
     */
    private function assertDeclaredItemTypeMatches(array $item, string $resolvedType, int $rowNo, string $rawCode): void
    {
        $declared = strtoupper(trim((string) ($item['item_type'] ?? '')));
        if ($declared === '' || $declared === $resolvedType) {
            return;
        }

        ApiError::abort(422, 'ITEM_TYPE_MISMATCH', 'item_type gửi lên không khớp loại mã đã quét (mã hộp BOX-<SKU>-<số hộp> là BOX, còn lại là ITEM).', [
            'row_no' => $rowNo,
            'raw_code' => $rawCode,
            'declared_item_type' => $declared,
            'resolved_item_type' => $resolvedType,
        ]);
    }

    /** @param array<string,mixed> $item */
    private function assertEchoSkuCodeMatches(array $item, string $resolvedSkuCode, int $rowNo, string $rawCode): void
    {
        $echo = strtoupper(trim((string) ($item['sku_code'] ?? '')));
        if ($echo === '' || $echo === strtoupper($resolvedSkuCode)) {
            return;
        }

        ApiError::abort(422, 'SKU_CODE_MISMATCH', 'sku_code gửi kèm không khớp SKU resolve được từ raw_code.', [
            'row_no' => $rowNo,
            'raw_code' => $rawCode,
            'sent_sku_code' => $echo,
            'resolved_sku_code' => strtoupper($resolvedSkuCode),
        ]);
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array{box_code: string, sku_code: string, box_seq: string}  $box
     */
    private function assertBoxNumberMatches(array $item, array $box, int $rowNo): void
    {
        $sent = trim((string) ($item['box_number'] ?? ''));
        // '1' và '0001' là cùng một hộp — so theo giá trị số, không so chuỗi.
        if ($sent === '' || (int) $sent === (int) $box['box_seq']) {
            return;
        }

        ApiError::abort(422, 'BOX_NUMBER_MISMATCH', 'box_number gửi kèm không khớp số hộp nằm trong mã hộp.', [
            'row_no' => $rowNo,
            'box_code' => $box['box_code'],
            'sent_box_number' => $sent,
            'resolved_box_number' => $box['box_seq'],
        ]);
    }

    /** @return list<string> */
    private function recordItemFields(): array
    {
        return array_merge(self::RECORD_ITEM_FIELDS, self::RECORD_ITEM_ECHO_FIELDS);
    }

    private function assertRecordPayloadShape(Request $request): void
    {
        $payload = $request->all();
        $itemFields = $this->recordItemFields();
        $unexpected = array_values(array_diff(array_keys($payload), self::RECORD_FIELDS));

        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (array_diff(array_keys($item), $itemFields) as $field) {
                    $unexpected[] = 'items.'.($index + 1).'.'.$field;
                }
            }
        }

        if ($unexpected === []) {
            return;
        }

        ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu gửi lên chứa trường không được khai báo trong hợp đồng inbound/record.', [
            'unexpected_fields' => array_values(array_unique($unexpected)),
            'allowed_fields' => self::RECORD_FIELDS,
            'allowed_item_fields' => $itemFields,
        ]);
    }

    /**
     * Bước resolve-code nhận `warehouse_id`, còn record canonical dùng
     * `dst_warehouse_id`. Mini App gửi lại đúng field của bước resolve nên nhận
     * cả hai tên; gửi cả hai mà trỏ hai kho khác nhau là lỗi thật, không đoán hộ.
     */
    private function normalizeWarehouseAlias(Request $request): void
    {
        $alias = $request->input('warehouse_id');
        $alias = is_string($alias) ? trim($alias) : '';
        if ($alias === '') {
            return;
        }

        $canonical = $request->input('dst_warehouse_id');
        $canonical = is_string($canonical) ? trim($canonical) : '';

        if ($canonical !== '' && strcasecmp($canonical, $alias) !== 0) {
            ApiError::abort(422, 'WAREHOUSE_ID_CONFLICT', 'warehouse_id và dst_warehouse_id trỏ tới hai kho khác nhau.', [
                'warehouse_id' => $alias,
                'dst_warehouse_id' => $canonical,
            ]);
        }

        $request->merge(['dst_warehouse_id' => $alias]);
    }

    private function resolveRawCode(
        string $rawCode,
        bool $checkOpenReceiving,
        bool $allowAutoCreateSku = false,
        ?string $newSkuType = null
    ): array {
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
            // Người quét đã trả lời "Sản phẩm / Linh kiện" ở Mini App: linh kiện
            // chọn từ MÃ ITEM nghĩa là loại dán mã từng cái -> is_serial_tracked
            // = true (+1 mỗi lần quét, không cần hộp), giống hệt kênh web.
            $sku = $this->resolveOrCreateSku(
                (string) $parsed->skuCode,
                $allowAutoCreateSku,
                $newSkuType === 'COMPONENT' ? 'COMPONENT' : 'PRODUCT',
                $newSkuType === 'COMPONENT' ? true : null,
            );
            if ($sku === null) {
                // Preview: mã hợp lệ nhưng sku_code chưa tồn tại và ta không
                // được phép tạo gì ở bước đọc — báo trước cho FE, không abort.
                return $this->newSkuCandidatePayload($rawCode, $parsed);
            }
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

    /**
     * BA 2026-08-25: mã hợp lệ nhưng sku_code chưa từng đăng ký thì tự tạo SKU
     * tối thiểu (mirror BaM1StockService::scanStockDocument) thay vì abort
     * SKU_NOT_FOUND — tên/thuộc tính đầy đủ được bổ sung sau qua màn quản trị
     * SKU. Preview (resolve-code) không được phép ghi gì nên trả null để caller
     * báo NEW_SKU_CANDIDATE thay vì tạo tại đây.
     *
     * $skuType/$isSerialTracked do LOẠI MÃ quyết định: QR item composite ->
     * PRODUCT (giữ mặc định is_serial_tracked của PRODUCT), mã hộp
     * BOX-<SKU>-<seq> -> COMPONENT + is_serial_tracked=false (đếm theo hộp).
     */
    private function resolveOrCreateSku(
        string $skuCode,
        bool $allowAutoCreateSku,
        string $skuType = 'PRODUCT',
        ?bool $isSerialTracked = null
    ): ?object {
        $normalized = strtoupper(trim($skuCode));
        $sku = DB::table('sku')->where('sku_code', $skuCode)->first()
            ?? DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [$normalized])->first();

        if ($sku !== null) {
            if (strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_INACTIVE', 'SKU trong QR đang không ACTIVE.', [
                    'sku_code' => $skuCode,
                    'status' => (string) $sku->status,
                ]);
            }

            return $sku;
        }

        if (! $allowAutoCreateSku) {
            return null;
        }

        $attributes = [
            'sku_code' => $normalized,
            'sku_name' => $normalized,
            // Cùng lý do với BaM1StockService::materializeSkuIfMissing(): ĐVT
            // để rỗng để SkuInboundInfoRule còn đòi được người nhận hàng xác
            // nhận trước khi Post Receipt (cột NOT NULL DEFAULT 'PCS').
            'unit' => '',
            'sku_type' => $skuType,
            'status' => 'ACTIVE',
        ];
        if ($isSerialTracked !== null) {
            $attributes['is_serial_tracked'] = $isSerialTracked;
        }

        try {
            $this->skuAggregate->create($attributes, $this->actorId(), request()?->attributes->get('request_id'));
        } catch (Throwable $exception) {
            // Hai lần quét đầu tiên đồng thời của cùng 1 SKU mới có thể đụng
            // unique constraint sku_code; nếu transaction khác đã thắng thì
            // tiếp tục với bản ghi đó thay vì để lỗi che mất kết quả thật.
            $sku = DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [$normalized])->first();
            if ($sku === null) {
                throw $exception;
            }

            return $sku;
        }

        return DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [$normalized])->first();
    }

    private function newSkuCandidatePayload(string $rawCode, ProductQrPayload $parsed): array
    {
        return [
            'raw_code' => $rawCode,
            'payload_format' => $parsed->format,
            'payload_version' => $parsed->payloadVersion,
            'sku_id' => null,
            'sku_code' => strtoupper(trim((string) $parsed->skuCode)),
            'sku_name' => null,
            'sku_type' => null,
            'is_serial_tracked' => null,
            'item_id' => null,
            'item_unique' => (string) $parsed->itemUnique,
            'serial_number' => $parsed->serialNumber,
            'normalized_code_value' => $parsed->normalizedCodeValue,
            'item_status' => null,
            'resolution_status' => 'NEW_SKU_CANDIDATE',
        ];
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
            // Linh kiện DÁN MÃ TỪNG CÁI (is_serial_tracked) quét +1 y như sản
            // phẩm; linh kiện đếm theo hộp thì không. Trả cờ ra ngoài để chỗ gác
            // "mã item mà là linh kiện" phân biệt được hai loại này.
            'is_serial_tracked' => (bool) ($sku->is_serial_tracked ?? false),
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
            // WWHN-267: một mã một HTTP status — WAREHOUSE_INACTIVE là xung đột trạng thái
            // (409), cùng status với ScanController và middleware tạm dừng kho.
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho đang không ACTIVE.');
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
        if (! SchemaCache::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
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

    private function attachScanEventToEvidence(string $documentId, string $lineId, mixed $itemId, mixed $itemUnique, string $rawCode, string $scanSource, ?string $boxCode = null): ?string
    {
        $scanEventId = null;
        if (SchemaCache::hasColumn('inbound_scan_entries', 'scan_event_id')
            && (SchemaCache::hasTable('scan_event') || SchemaCache::hasView('scan_event'))) {
            $eventQuery = DB::table('scan_event as se')
                ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
                ->where('sb.source_id', $documentId);
            if (SchemaCache::hasRelationColumn('scan_event', 'raw_code')) {
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
        } elseif ($boxCode !== null) {
            // Entry của hộp không có item identity; định danh nó bằng chính
            // container vừa đăng ký, nếu không hai hộp cùng dòng (cùng SKU
            // COMPONENT, created_at trùng giây) có thể nhận nhầm entry của nhau.
            $containerId = DB::table('containers')
                ->where('container_code', strtoupper(trim($boxCode)))
                ->value('container_id');
            if ($containerId === null) {
                ApiError::abort(500, 'SCAN_EVIDENCE_NOT_FOUND', 'Không tìm thấy hộp vừa đăng ký trong atomic record.');
            }
            $entryQuery->whereNull('item_id')->where('container_id', $containerId);
        } elseif ($itemUnique !== null && SchemaCache::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            $entryQuery->where('candidate_item_unique', strtoupper(trim((string) $itemUnique)));
        }

        $entry = $entryQuery->orderByDesc('created_at')->first();
        if ($entry === null) {
            ApiError::abort(500, 'SCAN_EVIDENCE_NOT_FOUND', 'Không tìm thấy scan entry vừa tạo trong atomic record.');
        }

        $updates = ['scan_source' => $scanSource, 'updated_at' => now()];
        if ($scanEventId !== null && SchemaCache::hasColumn('inbound_scan_entries', 'scan_event_id')) {
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
