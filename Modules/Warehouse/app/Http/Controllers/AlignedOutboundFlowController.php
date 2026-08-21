<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\IdempotentEnvelope;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use OpenApi\Attributes as OA;

/**
 * Alignment 2026-08-15:
 * Scan -> aggregate -> preview/print/apply ONE Packing Label per outbound document -> Post Issue.
 * RFID is optional for PRODUCT outbound and never blocks Post Issue.
 */
class AlignedOutboundFlowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CurrentBaselineStockService $stock,
        private readonly CurrentBaselineStockFlowController $legacyFlow,
    ) {}

    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/scans',
        operationId: 'stock_out_scans',
        summary: 'Quét & match item vào dòng phiếu xuất (stock-neutral; full match tự tạo 01 Packing Label DRAFT)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'line_id', type: 'string', format: 'uuid', nullable: true, description: 'Tùy chọn khi gửi code_value: server tự match dòng theo SKU parse từ QR composite (Change Pack QR/SKU).', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 1, description: 'Bắt buộc khi không gửi qty (hai tên là alias của cùng một trường).', example: 1),
                    new OA\Property(property: 'qty', type: 'integer', minimum: 1, nullable: true, description: 'Alias của quantity; bắt buộc khi không gửi quantity.', example: 1),
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 512, nullable: true, description: 'RAW scan payload. PRODUCT QR composite: HN1|SKU=<sku_code>|ITEM=<item_unique>; server parse + cross-check SKU embedded với item/dòng (WRONG_SKU khi lệch, UNKNOWN_CODE khi item chưa đăng ký).', example: 'HN1|SKU-000123|ITEM-000456'),
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'MANUAL', 'HARDWARE'], nullable: true, description: 'Nguồn quét khai tường minh (ERD bảng 25); không gửi thì server suy luận và ghi scan_source_origin=INFERRED.', example: 'CAMERA'),
                    new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', nullable: true, description: 'Chỉ nhận để tương thích client cũ; scan không in nhãn.', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Item matched; kèm scan_source/scan_source_origin, packing_label_ready và label_status; stock_effect=NONE'),
        ]
    )]
    public function outboundScan(Request $request, string $id)
    {
        $payload = $this->scanPayload($request);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $payload, $expectedVersion): array {
            $document = $this->lockDocument($id);
            $this->assertVersion($document, $expectedVersion);
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan.');
            }
            if ($document->outbound_request_id === null) {
                ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải được sinh từ toa/request nguồn.');
            }

            // line_id là tùy chọn khi có code_value (Change Pack QR/SKU: server tự
            // match dòng theo SKU embedded); guard PRODUCT_SKU_REQUIRED cho dòng
            // auto-match nằm trong CurrentBaselineStockService::scanStockDocument.
            if (! empty($payload['line_id'])) {
                $line = DB::table('stock_document_lines')
                    ->where('id', $payload['line_id'])
                    ->where('stock_document_id', $id)
                    ->first();
                if ($line === null) {
                    ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy line trong phiếu xuất.');
                }
                $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
                if ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                    ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Outbound sản phẩm chỉ nhận SKU loại PRODUCT.');
                }
            }

            $resolvedItemId = $this->stock->resolveExistingItemIdForPayload($payload);
            if ($resolvedItemId !== null) {
                $this->assertNoDuplicateItemInDocument($id, $resolvedItemId);
            }

            $result = $this->stock->scanStockDocument($id, 'issue', $payload, $this->actorId());
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $id)->update([
                'version' => $newVersion,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $this->attachScanEventToEvidence($id, (string) $result['line_id'], $result['item_id'] ?? null);

            $fullScan = $this->isFullScan($id);
            // §5.2/§15/AC-GWT-OUTBOUND-REQUEST-001: "Khi full match, hệ thống tổng hợp
            // và tạo/đảm bảo một Packing_Label DRAFT" — không chờ thao tác tay.
            $labelId = $fullScan ? $this->ensurePackingLabel($id) : null;
            $label = $labelId !== null ? DB::table('label_snapshots')->where('id', $labelId)->first() : null;

            $result['version'] = $newVersion;
            $result['label_snapshot_id'] = $labelId;
            $result['label_status'] = $label !== null ? (string) $label->status : 'NOT_CREATED';
            $result['packing_label_ready'] = $fullScan;
            $result['rfid_required'] = false;
            // DEV195-P1-01R2: trả lại đúng mode đã ghi vào audit để FE/QA đối chiếu
            // được, thay vì phải suy luận ngược từ payload đã gửi.
            $result['scan_source'] = (string) $payload['scan_source'];
            $result['scan_source_origin'] = (string) $payload['scan_source_origin'];

            return $result;
        }, 3);

        return $this->respondSuccess(
            $result,
            $result['packing_label_ready']
                ? 'Scan đủ toàn bộ phiếu. Đã tổng hợp lô và tạo/đảm bảo 01 Packing Label DRAFT; stock_effect=NONE.'
                : 'Item đã match. Tiếp tục scan; chưa tạo nhãn và chưa trừ tồn.'
        )->header('ETag', '"'.((int) $result['version']).'"');
    }

    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/unmatch',
        operationId: 'stock_out_unmatch',
        summary: 'Gỡ item đã match khỏi phiếu xuất trước Post (match ACTIVE → REMOVED, stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['outbound_item_match_id'],
                properties: [
                    new OA\Property(property: 'outbound_item_match_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true, example: 'Item scan sai SKU, gỡ để scan lại'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Match REMOVED; scanned_qty giảm tương ứng, nhãn chưa đóng băng bị huỷ; stock_effect=NONE'),
        ]
    )]
    public function outboundUnmatch(Request $request, string $id)
    {
        // Nhãn DRAFT/PREVIEWED được sinh tự động khi full match nên phải cho phép
        // sửa match: huỷ nhãn chưa đóng băng rồi tạo lại khi full match lần sau.
        // Từ READY_TO_PRINT trở đi snapshot đã đóng băng (§5.2) → chặn.
        $this->discardUnfrozenLabel($id);

        return $this->legacyFlow->outboundUnmatch($request, $id);
    }

    /**
     * §6: snapshot đóng băng tại Ready. Trước đó nhãn chỉ là bản tổng hợp tạm nên
     * được huỷ khi lô thay đổi; sau đó mọi thay đổi match đều bị chặn.
     */
    private function discardUnfrozenLabel(string $documentId): void
    {
        $label = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->first();
        if ($label === null) {
            return;
        }
        $unfrozen = in_array((string) $label->status, ['DRAFT', 'PENDING_PRINT', 'PREVIEWED'], true)
            && $label->applied_at === null;
        if (! $unfrozen) {
            ApiError::abort(
                409,
                'PACKING_LABEL_ALREADY_CREATED',
                'Packing Label đã đóng băng snapshot (READY_TO_PRINT trở đi). Không được thay đổi match; hãy Cancel phiếu và tạo lại nếu dữ liệu lô sai.'
            );
        }

        DB::table('label_snapshots')->where('id', $label->id)->delete();
        AuditLogger::record(
            action: 'PACKING_LABEL_DISCARDED',
            objectType: 'label_snapshot',
            objectId: (string) $label->id,
            actorId: $this->actorId(),
            requestId: request()?->attributes->get('request_id'),
            beforeData: ['status' => (string) $label->status],
            afterData: ['outbound_document_id' => $documentId, 'reason' => 'MATCH_CHANGED_BEFORE_FREEZE', 'stock_effect' => 'NONE'],
        );
    }

    /**
     * SCR-LBL-01: SĐT hiển thị trên nhãn lấy từ toa; người dùng vẫn được bổ sung ở
     * bước nhãn khi toa nguồn để trống. Payload bất biến nên phải tạo lại nhãn
     * chưa đóng băng thay vì UPDATE (guard hn_erd_lock_label_snapshot_payload).
     */
    #[OA\Post(
        path: '/api/v1/label-snapshots',
        operationId: 'label_snapshot_create',
        summary: 'Tạo/đảm bảo đúng 01 Packing Label DRAFT cho phiếu xuất đã full match (bổ sung SĐT nếu toa thiếu)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['outbound_document_id'],
                properties: [
                    new OA\Property(property: 'outbound_document_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'customer_phone', type: 'string', maxLength: 50, nullable: true, description: 'SĐT hiển thị trên nhãn; thiếu thì nhãn render blank, không chặn tạo nhãn.', example: '0912345678'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã đảm bảo đúng 01 Packing Label bất biến cho phiếu/lô xuất; chưa trừ tồn'),
        ]
    )]
    public function storePackingLabel(Request $request)
    {
        // SRS §5.2 + §15 + AC-GWT-OUTBOUND-REQUEST-001: address/phone nullable,
        // "Missing address/phone render blank" — không được chặn tạo nhãn khi thiếu SĐT.
        $validated = $request->validate([
            'outbound_document_id' => ['required', 'uuid', 'exists:stock_documents,id'],
            // DEC-PHONE-VN-001: khi có SĐT bổ sung ở bước nhãn, phải đúng định dạng VN.
            'customer_phone' => ['nullable', 'string', 'max:50', 'regex:/^0\d{9}$/'],
        ], [
            'customer_phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.',
        ]);
        $documentId = (string) $validated['outbound_document_id'];
        $phone = array_key_exists('customer_phone', $validated)
            ? trim((string) ($validated['customer_phone'] ?? ''))
            : null;

        $snapshotId = DB::transaction(fn (): string => $this->ensurePackingLabel($documentId, $phone), 3);

        return $this->respondCreated($this->labelPayload($snapshotId), 'Đã đảm bảo đúng 01 Packing Label bất biến cho phiếu/lô xuất; chưa trừ tồn.');
    }

    /**
     * Tạo/đảm bảo đúng 01 Packing Label DRAFT cho phiếu xuất sau full match.
     * Gọi từ scan (tự động) và từ endpoint tạo nhãn (khi cần bổ sung SĐT).
     */
    private function ensurePackingLabel(string $documentId, ?string $phoneOverride = null): string
    {
        $document = $this->lockDocument($documentId);
        if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu chưa hoàn tất mới được tạo Packing Label.');
        }
        if (! $this->isFullScan($documentId)) {
            ApiError::abort(422, 'FULL_SCAN_REQUIRED_FOR_LABEL', 'Chỉ tạo Packing Label sau khi mọi line đã scan đủ chính xác.');
        }

        if ($phoneOverride !== null && $phoneOverride !== '' && (string) ($document->recipient_contact_phone ?? '') !== $phoneOverride) {
            DB::table('stock_documents')->where('id', $documentId)->update([
                'recipient_contact_phone' => $phoneOverride,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            if ($document->outbound_request_id !== null) {
                DB::table('outbound_requests')->where('id', $document->outbound_request_id)->update([
                    'customer_phone' => $phoneOverride,
                    'updated_at' => now(),
                    'updated_by' => $this->actorId(),
                ]);
            }
            $document = $this->lockDocument($documentId);
        }

        $existing = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->lockForUpdate()->first();
        if ($existing !== null) {
            $payload = json_decode((string) $existing->label_payload, true) ?: [];
            $stale = (string) ($payload['content_hash'] ?? '') !== $this->labelContentHash($documentId);
            $unfrozen = in_array((string) $existing->status, ['DRAFT', 'PENDING_PRINT', 'PREVIEWED'], true)
                && $existing->applied_at === null;
            if (! $stale || ! $unfrozen) {
                return (string) $existing->id;
            }
            // Nội dung lô/người nhận đổi khi nhãn chưa đóng băng → tạo lại nhãn khớp nội dung.
            DB::table('label_snapshots')->where('id', $existing->id)->delete();
        }

        return $this->createPackingLabelSnapshot($document);
    }

    private function createPackingLabelSnapshot(object $document): string
    {
        $documentId = (string) $document->id;

        $lines = DB::table('stock_document_lines as l')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $documentId)
            ->orderBy('l.line_no')
            ->get(['l.product_id', 'l.qty_planned', 'l.scanned_qty', 's.sku_code', 's.sku_name']);
        $activeMatches = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->get(['m.quantity', 'm.item_id']);

        $snapshotId = (string) Str::uuid();
        $labelCode = 'PL-'.(string) $document->doc_no;
        $sourceRequestNo = $document->outbound_request_id !== null
            ? (string) (DB::table('outbound_requests')->where('id', $document->outbound_request_id)->value('request_no') ?? '')
            : '';
        // §5.2: recipient lấy từ toa; thiếu SĐT/địa chỉ thì nhãn render blank.
        $customerPhone = trim((string) ($document->recipient_contact_phone ?? ''));
        $payload = [
            'label_code' => $labelCode,
            'outbound_no' => (string) $document->doc_no,
            'outbound_document_id' => $documentId,
            'source_request_no' => $sourceRequestNo,
            'recipient_name' => (string) ($document->recipient_name ?? ''),
            'recipient_address' => (string) ($document->recipient_address ?? ''),
            'customer_phone' => $customerPhone,
            'item_count' => (int) $activeMatches->sum('quantity'),
            'sku_count' => $lines->pluck('product_id')->unique()->count(),
            'sku_summary' => $lines->map(fn (object $line): array => [
                'sku_id' => (string) $line->product_id,
                'sku_code' => (string) ($line->sku_code ?? ''),
                'sku_name' => (string) ($line->sku_name ?? ''),
                'quantity' => (int) $line->qty_planned,
            ])->values()->all(),
            'created_at' => now()->toIso8601String(),
        ];
        // Annex A Packing_Label_Snapshot có content_hash; §5.2 yêu cầu Post Issue
        // kiểm tra "snapshot còn khớp outbound content".
        $payload['content_hash'] = $this->labelContentHash($documentId);

        DB::table('label_snapshots')->insert([
            'id' => $snapshotId,
            'outbound_document_id' => $documentId,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            // §6 State Model: nhãn khởi tạo ở DRAFT rồi mới PREVIEWED/READY_TO_PRINT.
            'status' => 'DRAFT',
            'applied_at' => null,
            'applied_by' => null,
            'version' => 1,
            'created_at' => now(),
            'created_by' => $this->actorId(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
        ]);

        AuditLogger::record(
            action: 'PACKING_LABEL_CREATED',
            objectType: 'label_snapshot',
            objectId: $snapshotId,
            actorId: $this->actorId(),
            requestId: request()?->attributes->get('request_id'),
            afterData: [
                'outbound_document_id' => $documentId,
                'label_code' => $labelCode,
                'label_cardinality' => 'ONE_PER_OUTBOUND_DOCUMENT',
                'status' => 'DRAFT',
                'item_count' => (int) $activeMatches->sum('quantity'),
                'sku_count' => $lines->pluck('product_id')->unique()->count(),
                'stock_effect' => 'NONE',
            ],
        );

        return $snapshotId;
    }

    /**
     * Annex C previewPackingLabel (If-Match). SRS §6: DRAFT/PENDING_PRINT → PREVIEWED.
     * Stock-neutral; chỉ được thao tác khi outbound còn pre-completion.
     */
    public function previewPackingLabel(Request $request, string $documentId, string $labelId)
    {
        return $this->transitionLabel($request, $documentId, $labelId, 'PREVIEWED', ['DRAFT', 'PENDING_PRINT', 'PREVIEWED'], 'PACKING_LABEL_PREVIEWED', 'Đã preview Packing Label; chưa in, chưa trừ tồn.');
    }

    /**
     * Annex C readyPackingLabel (Idempotency-Key + If-Match). SRS §6: Ready đóng băng
     * immutable Packing_Label_Snapshot và mở cổng cho lệnh in.
     */
    public function readyPackingLabel(Request $request, string $documentId, string $labelId)
    {
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'ready_packing_label_'.$labelId,
            $this->actorId(),
            $hash,
            function () use ($request, $documentId, $labelId): array {
                $response = $this->transitionLabel($request, $documentId, $labelId, 'READY_TO_PRINT', ['DRAFT', 'PENDING_PRINT', 'PREVIEWED', 'READY_TO_PRINT'], 'PACKING_LABEL_READY', 'Packing Label đã sẵn sàng in; snapshot bất biến.');

                return [200, $response->getData(true)];
            }
        );

        return $this->labelResponse($body, $code, $replay);
    }

    /**
     * @param  list<string>  $allowedFrom
     */
    private function transitionLabel(Request $request, string $documentId, string $labelId, string $target, array $allowedFrom, string $auditAction, string $message)
    {
        $expectedVersion = $this->expectedVersion($request);

        return DB::transaction(function () use ($request, $documentId, $labelId, $target, $allowedFrom, $auditAction, $message, $expectedVersion) {
            $snapshot = DB::table('label_snapshots')->where('id', $labelId)->lockForUpdate()->first();
            if ($snapshot === null || (string) $snapshot->outbound_document_id !== $documentId) {
                ApiError::abort(404, 'LABEL_NOT_FOUND', 'Không tìm thấy Packing Label của phiếu xuất này.');
            }
            $this->assertLabelVersion($snapshot, $expectedVersion);

            $document = $this->lockDocument($documentId);
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Preview/Ready chỉ thực hiện khi phiếu xuất chưa hoàn tất.');
            }

            $current = (string) $snapshot->status;
            if (! in_array($current, $allowedFrom, true)) {
                ApiError::abort(409, 'LABEL_STATE_CONFLICT', "Không thể chuyển Packing Label từ {$current} sang {$target}.");
            }

            $version = (int) ($snapshot->version ?? 0);
            if ($current !== $target) {
                $version++;
                DB::table('label_snapshots')->where('id', $labelId)->update([
                    'status' => $target,
                    'version' => $version,
                    'updated_at' => now(),
                    'updated_by' => $this->actorId(),
                ]);
                AuditLogger::record(
                    action: $auditAction,
                    objectType: 'label_snapshot',
                    objectId: $labelId,
                    actorId: $this->actorId(),
                    requestId: $request->attributes->get('request_id'),
                    afterData: ['from_status' => $current, 'to_status' => $target, 'stock_effect' => 'NONE'],
                );
            }

            return $this->respondSuccess($this->labelPayload($labelId) + [
                'stock_effect' => 'NONE',
                'movement_created' => false,
            ], $message)->header('ETag', '"'.$version.'"');
        }, 3);
    }

    /** Trả lại envelope idempotent kèm ETag version mới của nhãn. */
    private function labelResponse(array $body, int $code, bool $replay)
    {
        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    private function assertLabelVersion(object $snapshot, int $expected): void
    {
        if ((int) ($snapshot->version ?? 0) !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Packing Label version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) ($snapshot->version ?? 0),
            ]);
        }
    }

    public function applyPackingLabel(Request $request, string $id)
    {
        // lockForUpdate chỉ có hiệu lực trong transaction (PG autocommit nhả lock ngay).
        return DB::transaction(function () use ($request, $id) {
            return $this->applyPackingLabelLocked($request, $id);
        }, 3);
    }

    private function applyPackingLabelLocked(Request $request, string $id)
    {
        $snapshot = DB::table('label_snapshots')->where('id', $id)->lockForUpdate()->first();
        if ($snapshot === null) {
            ApiError::abort(404, 'LABEL_NOT_FOUND', 'Không tìm thấy Packing Label.');
        }
        // If-Match tuỳ chọn ở bước xác nhận dán (không nằm trong Annex C) nhưng vẫn
        // phải chặn khi client gửi version cũ.
        if (trim((string) $request->header('If-Match')) !== '') {
            $this->assertLabelVersion($snapshot, $this->expectedVersion($request));
        }
        if (empty($snapshot->outbound_document_id)) {
            ApiError::abort(409, 'LEGACY_ITEM_LABEL_NOT_APPLICABLE', 'Snapshot nhãn item legacy không thể dùng làm Packing Label của lô.');
        }
        if ((string) $snapshot->status !== 'PRINTED') {
            ApiError::abort(422, 'LABEL_NOT_PRINTED', 'Chỉ xác nhận dán nhãn sau khi Packing Label đã PRINTED.');
        }

        $document = $this->lockDocument((string) $snapshot->outbound_document_id);
        if ((string) $document->status !== 'SCANNING') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu SCANNING mới được xác nhận dán Packing Label.');
        }

        if ($snapshot->applied_at === null) {
            DB::table('label_snapshots')->where('id', $id)->update([
                'applied_at' => now(),
                'applied_by' => $this->actorId(),
                'version' => ((int) ($snapshot->version ?? 0)) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            AuditLogger::record(
                action: 'PACKING_LABEL_APPLIED',
                objectType: 'label_snapshot',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['outbound_document_id' => (string) $snapshot->outbound_document_id, 'applied' => true],
            );
        }

        return $this->respondSuccess($this->labelPayload($id), 'Đã xác nhận dán Packing Label; stock_effect=NONE.');
    }

    public function postIssue(Request $request, string $id)
    {
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'post_issue_'.$id,
            $this->actorId(),
            $hash,
            function () use ($id, $expectedVersion, $key, $hash): array {
                $result = DB::transaction(function () use ($id, $expectedVersion, $key, $hash): array {
                    $document = $this->lockDocument($id);
                    $this->assertVersion($document, $expectedVersion);
                    if ((string) $document->status !== 'SCANNING') {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ SCANNING mới được Post Issue.');
                    }
                    if ($document->outbound_request_id === null) {
                        ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải liên kết toa/request nguồn.');
                    }
                    if (! $this->isFullScan($id)) {
                        ApiError::abort(422, 'FULL_POST_REQUIRED', 'Mọi line phải scan đủ chính xác trước Post Issue.');
                    }

                    $label = DB::table('label_snapshots')->where('outbound_document_id', $id)->lockForUpdate()->first();
                    if ($label === null) {
                        ApiError::abort(422, 'PACKING_LABEL_REQUIRED', 'Phải tạo đúng 01 Packing Label cho lô trước Post Issue.');
                    }
                    if ((string) $label->status !== 'PRINTED') {
                        ApiError::abort(422, 'LABEL_NOT_PRINTED', 'Packing Label phải PRINTED trước Post Issue.');
                    }
                    if ($label->applied_at === null) {
                        ApiError::abort(422, 'PACKING_LABEL_NOT_APPLIED', 'Phải xác nhận đã dán Packing Label trước Post Issue.');
                    }
                    // §5.2: snapshot phải còn khớp outbound content tại thời điểm Post.
                    $snapshotHash = (string) (json_decode((string) $label->label_payload, true)['content_hash'] ?? '');
                    if ($snapshotHash !== '' && $snapshotHash !== $this->labelContentHash($id)) {
                        ApiError::abort(409, 'LABEL_SNAPSHOT_STALE', 'Nội dung phiếu đã đổi sau khi in nhãn; phải in lại nhãn khớp nội dung mới.');
                    }

                    DB::table('stock_documents')->where('id', $id)->update([
                        'version' => $expectedVersion + 1,
                        'updated_by' => $this->actorId(),
                        'updated_at' => now(),
                    ]);
                    $posted = $this->stock->postStockDocument($id, 'issue', $this->actorId(), $key, $hash);
                    $posted['version'] = $expectedVersion + 1;
                    $posted['packing_label_id'] = (string) $label->id;
                    $posted['packing_label_applied'] = true;
                    $posted['rfid_required'] = false;

                    return $posted;
                }, 3);

                return [200, $this->envelope('Post Issue thành công; tồn được trừ tại bước hoàn tất này.', $result)];
            }
        );

        // WWHN-234 F-09 / TC-P1-013: Post Issue chạy qua controller này chứ không phải
        // CurrentBaselineStockFlowController, nên nó đã bỏ sót cờ replayed ở body mà
        // đường Post Receipt có. Người dùng bấm "Hoàn tất xuất" lần hai sau timeout mạng
        // nhận response 200 giống hệt lần đầu và tưởng vừa trừ tồn thêm một lần nữa.
        $body = IdempotentEnvelope::withReplayFlag($body, $replay);
        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    private function isFullScan(string $documentId): bool
    {
        $lines = DB::table('stock_document_lines')->where('stock_document_id', $documentId)->get(['qty_planned', 'scanned_qty']);

        return $lines->isNotEmpty() && $lines->every(fn (object $line): bool => (int) $line->qty_planned > 0 && (int) $line->qty_planned === (int) $line->scanned_qty);
    }

    /**
     * Annex A content_hash: chốt nội dung nghiệp vụ của nhãn (người nhận + từng
     * line SKU/số lượng + tập item đã match) để Post Issue phát hiện nhãn cũ.
     */
    private function labelContentHash(string $documentId): string
    {
        $document = DB::table('stock_documents')->where('id', $documentId)->first();
        $lines = DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->orderBy('line_no')
            ->get(['product_id', 'qty_planned'])
            ->map(fn (object $line): string => $line->product_id.':'.(int) $line->qty_planned)
            ->all();
        $items = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->orderBy('m.item_id')
            ->pluck('m.item_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return hash('sha256', json_encode([
            'outbound_no' => (string) ($document->doc_no ?? ''),
            'recipient_name' => (string) ($document->recipient_name ?? ''),
            'recipient_address' => (string) ($document->recipient_address ?? ''),
            'recipient_contact_phone' => (string) ($document->recipient_contact_phone ?? ''),
            'lines' => $lines,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function labelPayload(string $id): array
    {
        $snapshot = DB::table('label_snapshots')->where('id', $id)->first();
        if ($snapshot === null) {
            return [];
        }
        $payload = json_decode((string) $snapshot->label_payload, true) ?: [];

        return [
            ...(array) $snapshot,
            'label_code' => $payload['label_code'] ?? null,
            'outbound_no' => $payload['outbound_no'] ?? null,
            'customer_name' => $payload['recipient_name'] ?? null,
            'customer_address' => $payload['recipient_address'] ?? null,
            'customer_phone' => $payload['customer_phone'] ?? null,
            'item_count' => $payload['item_count'] ?? 0,
            'sku_count' => $payload['sku_count'] ?? 0,
            'sku_summary' => $payload['sku_summary'] ?? [],
            'applied' => $snapshot->applied_at !== null,
        ];
    }

    /**
     * WWHN-195 / DEV195-P1-01R2 — chế độ quét là EXPLICIT, không suy luận ngầm.
     *
     * ERD bảng 25 `scan_events.scan_source` đã có CHECK IN ('CAMERA','MANUAL',
     * 'HARDWARE') nhưng trước đây API không nhận field này, nên giá trị hoàn toàn
     * do trigger DB đoán theo `physical_code_id IS NULL` — cách đoán đó không bao
     * giờ sinh ra được 'CAMERA' và ghi camera scan thành 'HARDWARE', trái
     * REQ-SRS-005/DEC-CAMERA-001. Nay client khai `scan_source` tường minh; nếu
     * không khai thì server vẫn suy luận nhưng đánh dấu scan_source_origin=
     * INFERRED để audit phân biệt được hai nguồn.
     *
     * Không khoá/không switch theo phiếu: AC-SRS-005 + NFR-SRS-021 yêu cầu
     * camera-denied/unsupported rơi về nhập tay ngay giữa luồng và dùng chung
     * server validation, nên mode là thuộc tính của từng scan event chứ không
     * phải state của outbound document. Cùng permission `outbound.scan`, cùng
     * state guard DRAFT/SCANNING cho mọi mode.
     */
    private const SCAN_SOURCES = ['CAMERA', 'MANUAL', 'HARDWARE'];

    private function scanPayload(Request $request): array
    {
        if ($request->has('scan_source') && $request->input('scan_source') !== null) {
            $request->merge(['scan_source' => strtoupper(trim((string) $request->input('scan_source')))]);
        }

        $validated = $request->validate([
            // Change Pack QR/SKU 20/08/2026: line_id tùy chọn khi có code_value —
            // server tự match dòng theo SKU embedded trong composite QR.
            'line_id' => ['nullable', 'required_without:code_value', 'uuid'],
            'quantity' => ['required_without:qty', 'integer', 'min:1'],
            'qty' => ['required_without:quantity', 'integer', 'min:1'],
            // DEV195-P1-05R2: contract và runtime cùng một trần ký tự. Change Pack
            // QR/SKU nâng 150 -> 512 cho raw composite payload; WWHN-195 pin cả
            // hai phía (yaml + literal dưới đây) nên đổi số là đổi đồng bộ.
            'code_value' => ['nullable', 'string', 'max:512'],
            'item_id' => ['nullable', 'uuid'],
            'container_id' => ['nullable', 'uuid'],
            'scan_source' => ['nullable', 'string', 'in:'.implode(',', self::SCAN_SOURCES)],
            // accepted only for backward-compatible clients; printing is deliberately deferred
            // until the whole outbound document has been scanned and aggregated.
            'printer_config_id' => ['nullable', 'uuid', 'exists:printer_configs,id'],
        ]);
        $validated['quantity'] = (int) ($request->input('quantity') ?? $request->input('qty'));
        unset($validated['printer_config_id']);

        $explicit = isset($validated['scan_source']) && $validated['scan_source'] !== null;
        $validated['scan_source_origin'] = $explicit ? 'EXPLICIT' : 'INFERRED';
        $validated['scan_source'] = $explicit
            ? (string) $validated['scan_source']
            // Suy luận chỉ phân biệt được "có gửi mã" hay "chọn thẳng item"; camera
            // và nhập tay mã đều rơi vào HARDWARE nên client nên khai tường minh.
            : (($validated['code_value'] ?? null) !== null && $validated['code_value'] !== '' ? 'HARDWARE' : 'MANUAL');

        return $validated;
    }

    private function lockDocument(string $id): object
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', 'issue')->lockForUpdate()->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất.');
        }

        return $document;
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

    private function assertNoDuplicateItemInDocument(string $documentId, string $itemId): void
    {
        $exists = DB::table('outbound_item_matches as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('e.item_id', $itemId)
            ->where(function ($sub): void {
                $sub->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
            })
            ->exists();
        if ($exists) {
            ApiError::abort(409, 'DUPLICATE_ITEM_IN_DOCUMENT', 'Một item chỉ được scan/match một lần trong cùng document.');
        }
    }

    private function attachScanEventToEvidence(string $documentId, string $lineId, mixed $itemId): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('outbound_item_matches', 'scan_event_id')) {
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
        $row = DB::table('outbound_item_matches')
            ->where('outbound_line_id', $lineId)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->orderByDesc('created_at')
            ->first();
        if ($row !== null) {
            DB::table('outbound_item_matches')->where('id', $row->id)->update(['scan_event_id' => $scanEventId]);
        }
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
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
