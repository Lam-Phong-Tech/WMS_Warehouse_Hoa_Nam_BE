<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\IdempotentEnvelope;
use App\Support\ScanRequestFingerprint;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\CurrentBaselineReversalService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use OpenApi\Attributes as OA;

class CurrentBaselineStockFlowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CurrentBaselineStockService $stock,
        private readonly CurrentBaselineReversalService $reversal,
    ) {}

    /**
     * WWHN-188 (DEV188-P1-03R2) — DEC-SCAN-IDEMP-001: exactly-once cho một *hành
     * động quét*, không phải chỉ chống ghi đè bằng version.
     *
     * `If-Match` chỉ phát hiện được replay **cũ** (client gửi lại version đã lỗi
     * thời → 412 STALE_VERSION). Nó không phát hiện được replay **mới**: client mất
     * response, tải lại phiếu, thấy version đã nhảy 1 → 2, tưởng lần quét trước
     * trượt nên gửi lại đúng hành động đó với version 2 hợp lệ. Nhánh có `item_id`
     * vẫn an toàn nhờ `assertNoDuplicateItemInDocument()`, nhưng nhánh COMPONENT
     * quantity-mode (không có `item_id`) sẽ cộng `scanned_qty` lần thứ hai — đúng
     * defect reviewer xác nhận ở DEV188-P1-03.
     *
     * Cách đóng: `Idempotency-Key` **optional** (scan là stock-neutral, Annex C
     * không khai bắt buộc nên không được 428 khi thiếu — cùng quy ước
     * {@see IdempotencyKeyGuard::optional()} đang dùng cho Warranty), cộng với
     * fingerprint tất định {@see ScanRequestFingerprint} làm request hash:
     *
     * - cùng key + cùng nội dung scan  → replay đúng response cũ, `replayed=true`,
     *   `scanned_qty` KHÔNG tăng lần hai (bất kể `If-Match` là version cũ hay mới);
     * - cùng key + khác nội dung scan  → 409 IDEMPOTENCY_CONFLICT (SRS §8.2);
     * - khác key + cùng nội dung scan  → chạy bình thường, cộng dồn đúng nghiệp vụ
     *   (hai lần quét thật liên tiếp của cùng một SKU/lô vẫn hợp lệ);
     * - không có key                    → giữ nguyên hành vi cũ, không phá client cũ.
     *
     * Fingerprint không chứa version và không chứa mốc thời gian — không dùng
     * time-window heuristic, đúng khuyến nghị của reviewer.
     */
    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/scans',
        operationId: 'post_api_v1_inbound__documents_id_scans',
        summary: 'Quét hàng vào phiếu nhập DRAFT/SCANNING (stock-neutral, chưa đổi tồn)',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'line_id', type: 'string', format: 'uuid', nullable: true, description: 'Tùy chọn khi gửi code_value: server tự match dòng theo SKU parse từ QR composite (Change Pack QR/SKU). Bắt buộc cho scan quantity-mode không kèm code_value.'),
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 1, description: 'Bắt buộc khi không gửi qty (hai tên là alias của cùng một trường).', example: 1),
                    new OA\Property(property: 'qty', type: 'integer', minimum: 1, nullable: true, description: 'Alias của quantity; bắt buộc khi không gửi quantity.'),
                    new OA\Property(property: 'code_value', type: 'string', minLength: 1, maxLength: 512, nullable: true, description: 'RAW scan payload. PRODUCT QR composite: HN1|SKU=<sku_code>|ITEM=<item_unique>; server parse authoritative, FE không tự tách. Plain code legacy vẫn nhận theo transition flag.'),
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Scan hợp lệ; stock_effect=NONE, data.replayed=true khi replay theo Idempotency-Key. Composite QR trả kèm payload_format/payload_version/sku_code/item_unique/normalized_code_value/resolution_status (NEW_ITEM_CANDIDATE khi item chưa tồn tại — item_id=null cho tới Post Receipt).'),
        ]
    )]
    public function inboundScan(Request $request, string $id)
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
                $result = $this->versionedScan($id, 'receipt', $payload, $expectedVersion);

                return [200, $this->envelope('Inbound scan validated; stock_effect=NONE.', $result)];
            }
        );

        // Giống post-receipt: QA phải phân biệt được replay ở tầng body chứ không
        // chỉ ở header, vì proxy có thể nuốt header tuỳ biến.
        if (is_array($body) && array_key_exists('data', $body) && is_array($body['data'])) {
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

    // WWHN-195 / DEV195-P1-05R2 — đã gỡ `outboundScan()` cùng đường in nhãn per-item
    // (`createAndDispatchLabel()` + `recordPrintResult()`) khỏi controller này.
    //
    // Chúng hiện thực model nhãn cũ: mỗi item match sinh một Label_Snapshot
    // PENDING_PRINT rồi bắn lệnh in ngay trong lúc scan. SRS v1.12 §5.2/§6 chốt
    // ngược lại — scan không tạo nhãn/lệnh in; chỉ khi full match mới tổng hợp đúng
    // 01 Packing Label DRAFT cho cả phiếu rồi mới PREVIEWED → READY_TO_PRINT →
    // PRINTED. Annex C: "legacy per-Item-label operations = 0".
    //
    // Từ 2026-08-15 không route nào còn trỏ tới các method này (bị
    // Flow06AlignmentServiceProvider override) nhưng chúng vẫn nằm trong source, nên
    // WWHN-195-outbound-openapi.* và annotation Swagger đã bị viết theo chúng. Xoá
    // tại gốc để không ai dựng lại contract sai. Handler thực thi hiện hành:
    // AlignedOutboundFlowController::outboundScan().

    /**
     * Gỡ một dòng đã quét khỏi phiếu nhập trước khi Post.
     *
     * ERD bảng 28 inbound_scan_entries đã thiết kế sẵn status ACTIVE/REMOVED
     * ("Cho sửa trước Post") cùng removed_at/removed_by và index
     * idx_inbound_scan_line_status, nhưng trước đây không có endpoint nào dùng nên
     * quét sai một item chỉ còn cách Cancel cả phiếu. Entry chuyển ACTIVE ->
     * REMOVED, line scanned_qty giảm tương ứng, hoàn toàn stock-neutral; item sau
     * đó quét lại được vào chính phiếu này hoặc phiếu khác.
     */
    public function inboundUnscan(Request $request, string $id)
    {
        $payload = $request->validate([
            'inbound_scan_entry_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $payload, $expectedVersion): array {
            $document = $this->lockDocument($id, 'receipt');
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được gỡ mã đã quét; sau Post phải dùng Reversal.');
            }

            $entry = DB::table('inbound_scan_entries')
                ->where('id', $payload['inbound_scan_entry_id'])
                ->lockForUpdate()
                ->first();
            if ($entry === null) {
                ApiError::abort(404, 'INBOUND_SCAN_ENTRY_NOT_FOUND', 'Không tìm thấy dòng đã quét.');
            }
            $line = DB::table('stock_document_lines')
                ->where('id', $entry->inbound_line_id)
                ->where('stock_document_id', $id)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'INBOUND_SCAN_ENTRY_NOT_FOUND', 'Dòng đã quét không thuộc phiếu nhập này.');
            }
            if ((string) ($entry->status ?? 'ACTIVE') !== 'ACTIVE') {
                ApiError::abort(409, 'SCAN_ENTRY_NOT_ACTIVE', 'Chỉ dòng quét ACTIVE mới được gỡ trước Post.');
            }

            $previousScanned = (int) $line->scanned_qty;
            $newScanned = max(0, $previousScanned - (int) $entry->quantity);
            DB::table('inbound_scan_entries')->where('id', $entry->id)->update([
                'status' => 'REMOVED',
                'removed_at' => now(),
                'removed_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            DB::table('stock_document_lines')->where('id', $line->id)->update([
                'scanned_qty' => $newScanned,
                'updated_at' => now(),
            ]);
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $id)->update([
                'version' => $newVersion,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'INBOUND_UNSCAN',
                objectType: 'inbound_scan_entry',
                objectId: (string) $entry->id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                reason: $payload['reason'] ?? null,
                beforeData: ['status' => 'ACTIVE', 'scanned_qty' => $previousScanned],
                afterData: ['status' => 'REMOVED', 'scanned_qty' => $newScanned, 'item_id' => $entry->item_id],
            );

            return [
                'document_id' => $id,
                'line_id' => (string) $line->id,
                'inbound_scan_entry_id' => (string) $entry->id,
                'item_id' => $entry->item_id !== null ? (string) $entry->item_id : null,
                'status' => 'REMOVED',
                'required_quantity' => (int) $line->qty_planned,
                'scanned_quantity' => $newScanned,
                'stock_effect' => 'NONE',
                'movement_created' => false,
                'version' => $newVersion,
            ];
        }, 3);

        return $this->respondSuccess($result, 'Inbound scan entry removed; stock_effect=NONE.')
            ->header('ETag', '"'.((int) $result['version']).'"');
    }

    /**
     * Inbound Carton Scan v1.0 §3.3 — mốc "Đã quét xong thùng".
     *
     * Audit-only: xác nhận người dùng đã quét hết hàng vật lý của carton hiện tại
     * trong phiếu nhập. KHÔNG tạo state mới — không đổi container.status, không
     * bump document version, stock_effect=NONE, movement_created=false. Bằng chứng
     * duy nhất là AuditLogger action=INBOUND_CARTON_SCAN_COMPLETED nên sau F5
     * server vẫn biết mốc này (§3.3: phương án audit endpoint được ưu tiên).
     *
     * Không có REOPEN: user scan lại carton là FE set lại active context; audit
     * lần trước vẫn là lịch sử (CTN-07). Retry/duplicate call là harmless theo
     * contract đã chọn (CTN-T11) vì không có side effect ngoài một dòng audit.
     */
    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/cartons/{containerId}/complete-scan',
        operationId: 'inbound_carton_complete_scan',
        x: ['permission' => 'inbound.scan'],
        summary: 'Ghi nhận "Đã quét xong thùng" cho carton trong phiếu nhập (audit-only, stock-neutral)',
        description: 'Inbound Carton Scan v1.0: đóng active carton context. Guard: document DRAFT/SCANNING, container loại CARTON, có ít nhất 1 ACTIVE inbound_scan_entry của carton trong phiếu. Không đổi container.status, không tạo movement/balance, không bump version. actual_item_qty = SUM quantity các ACTIVE scan entries của carton trong phiếu.',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'containerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit INBOUND_CARTON_SCAN_COMPLETED đã ghi; data gồm document_id, container_id, carton_code, actual_item_qty, completed_at, stock_effect=NONE, movement_created=false.'),
        ]
    )]
    public function completeInboundCartonScan(Request $request, string $id, string $containerId)
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', 'receipt')->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
        }
        if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới ghi nhận được mốc xong thùng.');
        }

        $carton = DB::table('containers')->where('container_id', $containerId)->first();
        if ($carton === null) {
            ApiError::abort(404, 'CARTON_NOT_FOUND', 'Không tìm thấy carton.', [
                'container_id' => $containerId,
            ]);
        }
        if (strtoupper((string) $carton->container_type) !== 'CARTON') {
            ApiError::abort(422, 'CARTON_TYPE_INVALID', 'Chỉ container loại CARTON mới có mốc "Đã quét xong thùng".', [
                'container_id' => $containerId,
                'container_type' => (string) $carton->container_type,
            ]);
        }

        // actual_item_qty đếm theo bằng chứng đang ACTIVE của chính phiếu này —
        // entry REMOVED (gỡ trước Post) không còn tính (ERD bảng 28).
        $actualItemQty = (int) DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $id)
            ->where('e.container_id', $containerId)
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
            })
            ->sum('e.quantity');
        if ($actualItemQty <= 0) {
            ApiError::abort(422, 'CARTON_NO_ACTIVE_SCANS', 'Carton chưa có Item nào được quét (ACTIVE) trong phiếu này nên không có gì để xác nhận.', [
                'container_id' => $containerId,
            ]);
        }

        $completedAt = now();
        AuditLogger::record(
            action: 'INBOUND_CARTON_SCAN_COMPLETED',
            objectType: 'container',
            objectId: $containerId,
            actorId: $this->actorId(),
            requestId: request()?->attributes->get('request_id'),
            afterData: [
                'document_id' => $id,
                'carton_code' => (string) $carton->container_code,
                'actual_item_qty' => $actualItemQty,
            ],
        );

        return $this->respondSuccess([
            'document_id' => $id,
            'container_id' => $containerId,
            'carton_code' => (string) $carton->container_code,
            'actual_item_qty' => $actualItemQty,
            'completed_at' => $completedAt->toIso8601String(),
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ], 'Carton scan completed; stock_effect=NONE.');
    }

    /**
     * Gỡ một item đã match khỏi phiếu xuất trước khi Post (BR "Gỡ trước Post").
     * Match chuyển ACTIVE -> REMOVED, line scanned_qty giảm tương ứng; hoàn toàn
     * stock-neutral. Item sau đó có thể scan lại vào phiếu này hoặc phiếu khác.
     */
    public function outboundUnmatch(Request $request, string $id)
    {
        $payload = $request->validate([
            'outbound_item_match_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $payload, $expectedVersion): array {
            $document = $this->lockDocument($id, 'issue');
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được gỡ item đã match; sau Post phải dùng Reversal.');
            }

            $match = DB::table('outbound_item_matches')
                ->where('id', $payload['outbound_item_match_id'])
                ->lockForUpdate()
                ->first();
            if ($match === null) {
                ApiError::abort(404, 'OUTBOUND_MATCH_NOT_FOUND', 'Không tìm thấy item match.');
            }
            $line = DB::table('stock_document_lines')
                ->where('id', $match->outbound_line_id)
                ->where('stock_document_id', $id)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'OUTBOUND_MATCH_NOT_FOUND', 'Match không thuộc phiếu xuất này.');
            }
            if ((string) ($match->status ?? 'ACTIVE') !== 'ACTIVE') {
                ApiError::abort(409, 'MATCH_NOT_ACTIVE', 'Chỉ match ACTIVE mới được gỡ trước Post.');
            }

            $previousScanned = (int) $line->scanned_qty;
            $newScanned = max(0, $previousScanned - (int) $match->quantity);
            DB::table('outbound_item_matches')->where('id', $match->id)->update([
                'status' => 'REMOVED',
                'removed_at' => now(),
                'removed_by' => $this->actorId(),
                'version' => ((int) ($match->version ?? 0)) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            DB::table('stock_document_lines')->where('id', $line->id)->update([
                'scanned_qty' => $newScanned,
                'updated_at' => now(),
            ]);
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $id)->update([
                'version' => $newVersion,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'OUTBOUND_UNMATCH',
                objectType: 'outbound_item_match',
                objectId: (string) $match->id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                reason: $payload['reason'] ?? null,
                beforeData: ['status' => 'ACTIVE', 'scanned_qty' => $previousScanned],
                afterData: ['status' => 'REMOVED', 'scanned_qty' => $newScanned, 'item_id' => $match->item_id],
            );

            return [
                'document_id' => $id,
                'line_id' => (string) $line->id,
                'outbound_item_match_id' => (string) $match->id,
                'item_id' => $match->item_id !== null ? (string) $match->item_id : null,
                'status' => 'REMOVED',
                'required_quantity' => (int) $line->qty_planned,
                'scanned_quantity' => $newScanned,
                'stock_effect' => 'NONE',
                'movement_created' => false,
                'version' => $newVersion,
            ];
        }, 3);

        return $this->respondSuccess($result, 'Outbound item match removed; stock_effect=NONE.')
            ->header('ETag', '"'.((int) $result['version']).'"');
    }

    public function postReceipt(Request $request, string $id)
    {
        return $this->postDocument($request, $id, 'receipt');
    }

    // `postIssue()` cũng đã gỡ cùng lý do (DEV195-P1-05R2): route
    // POST /outbound-documents/{id}/post-issue trỏ thẳng
    // AlignedOutboundFlowController::postIssue — nơi có đủ gate label PRINTED +
    // snapshot current + label-applied confirmation theo SRS §5.2. Nhánh
    // `$type === 'issue'` trong postDocument() được giữ lại vì reverse('issue')
    // vẫn dùng chung hạ tầng idempotency/version của method này.

    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/reversals',
        operationId: 'post_api_v1_inbound__documents_id_reversals',
        summary: 'Tạo reversal cho phiếu nhập POSTED (chứng từ gốc giữ nguyên POSTED)',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reversal_document_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'reason_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, description: 'Bắt buộc khi không gửi reversal_document_id.'),
                    new OA\Property(property: 'condition_status', type: 'string', enum: ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], nullable: true),
                    new OA\Property(property: 'physical_return_confirmed', type: 'boolean', nullable: true),
                    new OA\Property(property: 'lines', type: 'array', minItems: 1, description: 'Bắt buộc khi không gửi reversal_document_id.', items: new OA\Items(
                        required: ['movement_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'movement_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                            new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reversal command đã xử lý; chứng từ gốc vẫn POSTED, data.replayed=true khi replay theo Idempotency-Key'),
        ]
    )]
    public function reverseInbound(Request $request, string $id)
    {
        return $this->reverse($request, $id, 'receipt');
    }

    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/reversals',
        operationId: 'stock_out_reversals',
        summary: 'Tạo reversal cho phiếu xuất POSTED (chứng từ gốc giữ nguyên POSTED)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reversal_document_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'reason_code', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, description: 'Bắt buộc khi không gửi reversal_document_id.'),
                    new OA\Property(property: 'condition_status', type: 'string', enum: ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], description: 'Bắt buộc khi không gửi reversal_document_id (hàng trả về phải khai điều kiện).'),
                    new OA\Property(property: 'physical_return_confirmed', type: 'boolean', nullable: true),
                    new OA\Property(property: 'lines', type: 'array', minItems: 1, description: 'Bắt buộc khi không gửi reversal_document_id.', items: new OA\Items(
                        required: ['movement_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'movement_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                            new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reversal command đã xử lý; chứng từ gốc vẫn POSTED, data.replayed=true khi replay theo Idempotency-Key'),
        ]
    )]
    public function reverseOutbound(Request $request, string $id)
    {
        return $this->reverse($request, $id, 'issue');
    }

    private function versionedScan(string $documentId, string $type, array $payload, int $expectedVersion): array
    {
        return DB::transaction(function () use ($documentId, $type, $payload, $expectedVersion): array {
            $document = $this->lockDocument($documentId, $type);
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan.');
            }

            // line_id là tùy chọn khi có code_value (Change Pack QR/SKU: server tự
            // match dòng theo SKU embedded); các guard theo dòng chạy trong service.
            if (! empty($payload['line_id'])) {
                $line = DB::table('stock_document_lines')->where('id', $payload['line_id'])->where('stock_document_id', $documentId)->first();
                if ($line === null) {
                    ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy line trong document.');
                }
                $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
                if ($type === 'issue' && ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT')) {
                    ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Outbound sản phẩm không được scan COMPONENT; linh kiện chỉ xuất qua Component Issue WARRANTY.');
                }
            }
            if ($type === 'issue' && $document->outbound_request_id === null) {
                ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải được sinh từ toa/request đã consume theo BP-WMS-04.');
            }

            $resolvedItemId = $this->stock->resolveExistingItemIdForPayload($payload);
            if ($resolvedItemId !== null) {
                $this->assertNoDuplicateItemInDocument($documentId, $type, $resolvedItemId);
            }

            $result = $this->stock->scanStockDocument($documentId, $type, $payload, $this->actorId());
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $documentId)->update([
                'version' => $newVersion,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $this->attachScanEventToEvidence($documentId, $type, (string) $result['line_id'], $result['item_id'] ?? null);
            $result['version'] = $newVersion;

            return $result;
        }, 3);
    }

    private function postDocument(Request $request, string $id, string $type)
    {
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        $operation = ($type === 'receipt' ? 'post_receipt_' : 'post_issue_').$id;

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            $hash,
            function () use ($id, $type, $expectedVersion, $key, $hash): array {
                $result = DB::transaction(function () use ($id, $type, $expectedVersion, $key, $hash): array {
                    $document = $this->lockDocument($id, $type);
                    $this->assertVersion($document, $expectedVersion);
                    if ($document->status !== 'SCANNING') {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ SCANNING mới được Post.');
                    }
                    if ($type === 'issue') {
                        if ($document->outbound_request_id === null) {
                            ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải liên kết toa/request nguồn.');
                        }
                        $this->assertLabelsPrinted($id);
                    }

                    // Increment while document is still mutable. PostgreSQL protects a
                    // POSTED row from every later UPDATE, so the version bump must be
                    // part of the same outer transaction before the status becomes POSTED.
                    DB::table('stock_documents')->where('id', $id)->update([
                        'version' => $expectedVersion + 1,
                        'updated_by' => $this->actorId(),
                        'updated_at' => now(),
                    ]);

                    $posted = $this->stock->postStockDocument($id, $type, $this->actorId(), $key, $hash);
                    $posted['version'] = $expectedVersion + 1;

                    return $posted;
                }, 3);

                return [200, $this->envelope($type === 'receipt' ? 'Post Receipt thành công.' : 'Post Issue thành công.', $result)];
            }
        );

        // AC-GWT-IN-001 / TC-P1-005: retry cùng Idempotency-Key trả business result
        // cũ kèm replayed=true — QA phải phân biệt được replay ở tầng body, không
        // chỉ ở header.
        if (is_array($body) && array_key_exists('data', $body) && is_array($body['data'])) {
            $body['data']['replayed'] = $replay;
        }

        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    private function reverse(Request $request, string $id, string $type)
    {
        $payload = $request->validate([
            'reversal_document_id' => ['nullable', 'uuid'],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'reason' => ['required_without:reversal_document_id', 'string', 'max:2000'],
            'condition_status' => [$type === 'issue' ? 'required_without:reversal_document_id' : 'nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
            'physical_return_confirmed' => ['nullable', 'boolean'],
            'lines' => ['required_without:reversal_document_id', 'array', 'min:1'],
            'lines.*.movement_id' => ['required_with:lines', 'uuid'],
            'lines.*.quantity' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
        ]);
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        $operation = 'reverse_stock_document_'.$id.'_'.($payload['reversal_document_id'] ?? 'new');

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            $hash,
            function () use ($id, $type, $payload, $key, $hash, $expectedVersion): array {
                $result = $this->reversal->execute($id, $type, $payload, $this->actorId(), $key, $hash, $expectedVersion);

                return [200, $this->envelope('Reversal command processed; original document remains POSTED.', $result)];
            }
        );

        // WWHN-234 F-09 / TC-P1-029: reversal replay cũng phải lộ ở body.
        $body = IdempotentEnvelope::withReplayFlag($body, $replay);

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function assertLabelsPrinted(string $documentId): void
    {
        $matches = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $documentId)
            ->where('s.sku_type', 'PRODUCT')
            ->whereNotNull('m.item_id')
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->get(['m.id', 'm.item_id']);

        foreach ($matches as $match) {
            $status = DB::table('label_snapshots')->where('outbound_item_match_id', $match->id)->value('status');
            if ($status !== 'PRINTED') {
                ApiError::abort(422, 'LABEL_NOT_PRINTED', 'Mọi PRODUCT item phải có label PRINTED trước Post Issue.', [
                    'item_id' => (string) $match->item_id,
                    'label_status' => $status,
                ]);
            }
        }
    }

    private function assertNoDuplicateItemInDocument(string $documentId, string $type, string $itemId): void
    {
        $table = $type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches';
        $lineColumn = $type === 'receipt' ? 'inbound_line_id' : 'outbound_line_id';
        $query = DB::table("{$table} as e")
            ->join('stock_document_lines as l', 'l.id', '=', "e.{$lineColumn}")
            ->where('l.stock_document_id', $documentId)
            ->where('e.item_id', $itemId)
            // Entry/match đã REMOVED (gỡ trước Post) được phép quét lại vào cùng
            // phiếu — áp dụng cho cả receipt (ERD bảng 28) và issue (ERD bảng 34).
            ->where(function ($sub): void {
                $sub->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
            });
        if ($query->exists()) {
            ApiError::abort(409, 'DUPLICATE_ITEM_IN_DOCUMENT', 'Một item chỉ được scan/match một lần trong cùng document.');
        }
    }

    private function attachScanEventToEvidence(string $documentId, string $type, string $lineId, mixed $itemId): void
    {
        if (! DB::getSchemaBuilder()->hasColumn($type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches', 'scan_event_id')) {
            return;
        }
        $scanEventId = DB::table('scan_event as se')
            ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
            ->where('sb.source_id', $documentId)
            ->when($itemId, fn ($query) => $query->where('se.resolved_object_id', $itemId))
            ->orderByDesc('se.created_at')
            ->value('se.scan_event_id');
        if ($scanEventId === null) {
            return;
        }

        $table = $type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches';
        $lineColumn = $type === 'receipt' ? 'inbound_line_id' : 'outbound_line_id';
        $row = DB::table($table)->where($lineColumn, $lineId)->when($itemId, fn ($query) => $query->where('item_id', $itemId))->orderByDesc('created_at')->first();
        if ($row !== null) {
            DB::table($table)->where('id', $row->id)->update(['scan_event_id' => $scanEventId]);
        }
    }

    private function scanPayload(Request $request): array
    {
        // Trim trước khi validate để chuỗi chỉ gồm khoảng trắng bị bắt là rỗng
        // thay vì lọt xuống resolver.
        if ($request->has('code_value') && $request->input('code_value') !== null) {
            $request->merge(['code_value' => trim((string) $request->input('code_value'))]);
        }

        // Change Pack QR/SKU 20/08/2026: line_id tùy chọn khi có code_value (server
        // tự match dòng theo SKU embedded); trần raw payload nâng 150 -> 512 cho
        // composite QR (nguồn số: config warehouse.product_qr.max_payload_length).
        $validated = $request->validate([
            'line_id' => ['nullable', 'required_without:code_value', 'uuid'],
            'quantity' => ['required_without:qty', 'integer', 'min:1'],
            'qty' => ['required_without:quantity', 'integer', 'min:1'],
            'code_value' => ['nullable', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'item_id' => ['nullable', 'uuid'],
            'container_id' => ['nullable', 'uuid'],
            'printer_config_id' => ['nullable', 'uuid', 'exists:printer_configs,id'],
        ]);
        $validated['quantity'] = (int) ($request->input('quantity') ?? $request->input('qty'));

        return $validated;
    }

    private function lockDocument(string $id, string $type): object
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', $type)->lockForUpdate()->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy stock document.');
        }

        return $document;
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho scan/post/reversal mutation.');
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

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => request()?->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()],
        ];
    }

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
