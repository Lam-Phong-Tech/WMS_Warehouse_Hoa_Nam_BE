<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Database\SchemaCache;
use App\Support\IdempotencyStore;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Events\StockDocumentCancelled;
use Modules\Warehouse\Events\StockDocumentCreated;
use Modules\Warehouse\Services\InboundDocumentNumber;
use Modules\Warehouse\Services\OutboundDocumentNumber;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use Modules\Warehouse\Support\MasterSelectionGuard;
use OpenApi\Attributes as OA;

class CurrentBaselineDocumentController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    /**
     * Annex D SCR-IN-01: bảng mã đã quét của phiếu nhập (mã / SKU / kết quả /
     * nguồn CAMERA hay nhập tay) phải đọc lại được sau khi tải lại trang —
     * inbound_scan_entries đã lưu scan_source nhưng trước đó không có endpoint đọc.
     */
    #[OA\Get(
        path: '/api/v1/inbound-documents/{id}/scan-entries',
        operationId: 'inbound_scan_entries_list',
        summary: 'Danh sách mã đã quét của phiếu nhập (Annex D SCR-IN-01)',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', description: 'ACTIVE (mặc định) hoặc ALL/REMOVED', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'REMOVED', 'ALL'], default: 'ACTIVE')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách mã đã quét'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu nhập'),
        ]
    )]
    public function inboundScanEntries(Request $request, string $id)
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', 'receipt')->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
        }

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
            'c.container_code',
        ];
        // item_code chỉ tồn tại trên nhánh ERD PostgreSQL (migration bổ sung cột
        // này dùng cú pháp PG nên bị bỏ qua ở sqlite) — chọn có điều kiện để
        // endpoint chạy được trên cả hai driver mà CI đang dùng.
        if (SchemaCache::hasColumn('item_instances', 'item_code')) {
            $columns[] = 'i.item_code';
        }
        // Change Pack QR/SKU (DEC-QR-03): entry candidate chưa có item_id cho tới
        // Post Receipt — FE hiển thị identity qua candidate_item_unique.
        if (SchemaCache::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            $columns[] = 'e.candidate_item_unique';
            $columns[] = 'e.candidate_serial_number';
        }

        // status=ACTIVE (mặc định hiển thị của IN-03) lọc bỏ entry đã gỡ; status=ALL
        // trả cả lịch sử REMOVED để đối soát/audit.
        $statusFilter = strtoupper(trim((string) $request->query('status', 'ACTIVE')));

        $page = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'e.item_id')
            ->leftJoin('containers as c', 'c.container_id', '=', 'e.container_id')
            ->where('l.stock_document_id', $id)
            ->when($statusFilter === 'ACTIVE', function ($query): void {
                $query->where(function ($nested): void {
                    $nested->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
                });
            })
            ->when(in_array($statusFilter, ['REMOVED'], true), fn ($query) => $query->where('e.status', 'REMOVED'))
            ->orderByDesc('e.created_at')
            ->select($columns)
            ->paginate($this->perPage($request));

        return $this->respondPaginated($page);
    }

    public function storeInbound(Request $request)
    {
        $validated = $request->validate([
            'doc_no' => ['nullable', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            // Không nhận phiếu nhập ghi ngày tương lai — chứng từ chỉ ghi nhận việc đã xảy ra.
            'doc_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            // Annex A / Annex D SCR-IN-01 đặt tên field là `source_reference` max 200;
            // ERD bảng 26 lưu cột `source_name` varchar(255). Nhận cả hai tên và siết
            // xuống 200 để thoả contract SRS mà vẫn nằm trong giới hạn cột của ERD.
            'source_name' => ['nullable', 'string', 'max:200'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            // note ghi thẳng vào stock_documents.note varchar(1000) (migration
            // 2026_01_01_000032_create_stock_documents_table.php); max cũ (2000) rộng hơn cột
            // DB nên note 1001-2000 ký tự sẽ vỡ ở QueryException 500 thay vì 422.
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'lines.*.qty_planned' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->createIdempotent($request, 'receipt', $validated);
    }

    // §8.1 (Outbound request / Excel intake): "Confirm creates exactly one outbound
    // DRAFT; no direct POST /outbound-documents create operation." Phiếu xuất chỉ
    // sinh từ POST /outbound-request-imports/{id}/confirm. Riêng phiếu DRAFT được
    // sửa header qua updateOutbound bên dưới; dòng SKU vẫn bất biến.

    public function updateInbound(Request $request, string $id)
    {
        $validated = $request->validate([
            // DB có UNIQUE(doc_no); thiếu rule ở update sẽ nổ QueryException 500
            // thay vì 422 VALIDATION_ERROR theo §8.2.
            'doc_no' => ['sometimes', 'string', 'max:80', Rule::unique('stock_documents', 'doc_no')->ignore($id, 'id')],
            'doc_date' => array_merge(['sometimes'], InputLimits::pastDate()),
            'source_name' => ['nullable', 'string', 'max:200'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            // note ghi thẳng vào stock_documents.note varchar(1000); xem lý do ở storeInbound().
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:500'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->updateDocument($request, $id, 'receipt', $validated);
    }

    #[OA\Patch(
        path: '/api/v1/outbound-documents/{id}',
        operationId: 'stock_out_update',
        summary: 'Sửa thông tin đầu phiếu xuất nháp (chỉ DRAFT)',
        description: 'Chỉ sửa header người nhận/địa chỉ/SĐT/đại lý/ngày yêu cầu/ghi chú của phiếu xuất DRAFT. Dòng SKU vẫn chốt từ toa nguồn; gửi lines → 422 SOURCE_REQUEST_IMMUTABLE. Bắt buộc If-Match.',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'recipient_name', type: 'string', maxLength: 200),
                new OA\Property(property: 'recipient_address', type: 'string', maxLength: 1000, nullable: true),
                new OA\Property(property: 'recipient_contact_phone', type: 'string', maxLength: 50, nullable: true, example: '0901234567'),
                new OA\Property(property: 'agency_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'request_date', type: 'string', format: 'date', example: '2026-11-01'),
                new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã cập nhật thông tin phiếu xuất nháp; ETag trả version mới.'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền outbound.request.manual_create/outbound.import hoặc ngoài warehouse scope'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu xuất'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT — chỉ DRAFT; DRAFT_EXPIRED; SCANNED_DOCUMENT_IMMUTABLE'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc có lines'),
            new OA\Response(response: 428, description: 'Thiếu If-Match'),
        ]
    )]
    public function updateOutbound(Request $request, string $id)
    {
        $validated = $request->validate([
            'recipient_name' => ['sometimes', 'required', 'string', 'max:200'],
            'recipient_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'recipient_contact_phone' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^0\d{9}$/'],
            'agency_id' => ['sometimes', 'nullable', 'uuid', 'exists:agencies,agency_id'],
            'request_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.InputLimits::DATE_MIN, 'before_or_equal:'.InputLimits::pastDateMax()],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if ($request->has('lines')) {
            ApiError::abort(
                422,
                'SOURCE_REQUEST_IMMUTABLE',
                'Dòng SKU của phiếu xuất sinh từ toa không được sửa; hãy huỷ phiếu và tạo lại nếu cần đổi hàng.'
            );
        }

        $expectedVersion = $this->expectedVersion($request);
        $payload = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
            $document = $this->lockDocument($id, 'issue');
            $this->assertVersion($document, $expectedVersion);
            if ((string) $document->status !== 'DRAFT') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu xuất ở trạng thái DRAFT mới được sửa thông tin.');
            }
            $this->assertDraftNotExpired($document);
            if ($this->hasScanEvidence($id, 'issue')) {
                ApiError::abort(409, 'SCANNED_DOCUMENT_IMMUTABLE', 'Phiếu đã có scan evidence; hãy Cancel và tạo lại để không xóa lịch sử scan.');
            }
            if (array_key_exists('agency_id', $validated)) {
                // Giữ được đại lý đã gán dù đại lý đó đã ngừng hoạt động.
                MasterSelectionGuard::assertAgencySelectable($validated['agency_id'], $document->agency_id);
            }

            $updates = collect($validated)->only([
                'recipient_name',
                'recipient_address',
                'recipient_contact_phone',
                'agency_id',
                'note',
            ])->all();
            if (array_key_exists('recipient_contact_phone', $updates) && $updates['recipient_contact_phone'] === '') {
                $updates['recipient_contact_phone'] = null;
            }
            $updates['version'] = $expectedVersion + 1;
            $updates['updated_by'] = $this->actorId();
            $updates['updated_at'] = now();
            DB::table('stock_documents')->where('id', $id)->update($updates);

            if ($document->outbound_request_id !== null) {
                $requestUpdates = [];
                if (array_key_exists('recipient_name', $updates)) {
                    $requestUpdates['recipient_name'] = $updates['recipient_name'];
                    $requestUpdates['customer_name'] = $updates['recipient_name'];
                }
                if (array_key_exists('recipient_address', $updates)) {
                    $address = (string) ($updates['recipient_address'] ?? '');
                    $requestUpdates['recipient_address'] = $address;
                    $requestUpdates['customer_address'] = $address;
                }
                if (array_key_exists('recipient_contact_phone', $updates)) {
                    $requestUpdates['customer_phone'] = $updates['recipient_contact_phone'];
                }
                if (array_key_exists('agency_id', $updates)) {
                    $requestUpdates['agency_id'] = $updates['agency_id'];
                }
                if (array_key_exists('request_date', $validated)) {
                    $requestUpdates['request_date'] = $validated['request_date'];
                }
                if ($requestUpdates !== []) {
                    $requestUpdates['version'] = DB::raw('version + 1');
                    $requestUpdates['updated_by'] = $this->actorId();
                    $requestUpdates['updated_at'] = now();
                    DB::table('outbound_requests')
                        ->where('id', $document->outbound_request_id)
                        ->update($requestUpdates);
                }
            }

            AuditLogger::record(
                action: 'UPDATE_STOCK_DOCUMENT',
                objectType: 'stock_document',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                beforeData: ['version' => $expectedVersion, 'status' => $document->status],
                afterData: [
                    'version' => $expectedVersion + 1,
                    'status' => $document->status,
                    'line_update_mode' => 'NONE',
                ],
            );

            return $this->documentPayload($id);
        }, 3);

        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

        return $this->respondSuccess($payload, 'Cập nhật thông tin phiếu xuất kho thành công.')
            ->header('ETag', '"'.((int) ($payload['version'] ?? 0)).'"');
    }

    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/cancel',
        operationId: 'stock_in_cancel',
        summary: 'Huỷ phiếu nhập DRAFT/SCANNING (terminal, stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã huỷ phiếu; status=CANCELLED'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có permission inbound.cancel hoặc inbound.create (route nhận cả hai — Annex C khai inbound.create, baseline seed cấp inbound.cancel riêng cho WAREHOUSE_APPROVER/KEEPER; xem docs/DR_HN_20260817_FLOW02_OPEN_DECISIONS.md)'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu nhập'),
            new OA\Response(response: 409, description: 'Chỉ DRAFT/SCANNING mới được huỷ'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp'),
            new OA\Response(response: 428, description: 'Thiếu If-Match hợp lệ'),
            new OA\Response(response: 400, description: 'Yêu cầu không đúng định dạng'),
            new OA\Response(response: 422, description: 'Thiếu reason hoặc reason quá dài'),
        ]
    )]
    public function cancelInbound(Request $request, string $id)
    {
        return $this->cancelDocument($request, $id, 'receipt');
    }

    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/cancel',
        operationId: 'stock_out_cancel',
        summary: 'Huỷ phiếu xuất DRAFT/SCANNING (terminal, stock-neutral; toa nguồn được trả về OPEN nếu không còn phiếu active)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, example: 'Khách hàng hủy đơn hàng trước khi xuất kho'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã hủy chứng từ; status=CANCELLED'),
        ]
    )]
    public function cancelOutbound(Request $request, string $id)
    {
        return $this->cancelDocument($request, $id, 'issue');
    }

    private function createIdempotent(Request $request, string $type, array $validated)
    {
        $actorId = $this->actorId();
        $key = trim((string) $request->header('Idempotency-Key'));
        $hash = IdempotencyStore::requestHash($request);
        $operation = $type === 'receipt' ? 'create_inbound_document' : 'create_outbound_document';

        if ($key === '') {
            $document = $this->createDocument($type, $validated, $actorId);

            return $this->respondCreated($document, $type === 'receipt' ? 'Tạo phiếu nhập kho thành công.' : 'Tạo phiếu xuất kho thành công.')
                ->header('ETag', '"'.((int) ($document['version'] ?? 1)).'"');
        }

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $actorId,
            $hash,
            function () use ($type, $validated, $actorId): array {
                $document = $this->createDocument($type, $validated, $actorId);

                return [201, $this->envelope($type === 'receipt' ? 'Tạo phiếu nhập kho thành công.' : 'Tạo phiếu xuất kho thành công.', $document)];
            }
        );

        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    private function createDocument(string $type, array $validated, string $actorId): array
    {
        return DB::transaction(function () use ($type, $validated, $actorId): array {
            $warehouseId = (string) ($type === 'receipt' ? $validated['dst_warehouse_id'] : $validated['src_warehouse_id']);
            $this->assertActiveWarehouse($warehouseId);
            $validated = $this->normalizeSourceReference($validated);

            if ($type === 'issue' && ! empty($validated['outbound_request_id'])) {
                $validated = $this->hydrateOutboundRequest($validated);
            }
            if ($type === 'issue') {
                MasterSelectionGuard::assertAgencySelectable($validated['agency_id'] ?? null);
            }

            $lines = array_values($validated['lines'] ?? []);
            // GĐ1-REQ-004: phiếu nhập scan-driven được tạo header-only — dòng SKU sinh
            // từ quét thực tế. Chỉ phiếu xuất (đi từ toa/request) mới bắt buộc có dòng.
            // Route receipt runtime đã trỏ BaM1InboundDocumentController; guard tại đây
            // phải khớp contract đó để không sống lại nếu override bị gỡ.
            if ($lines === [] && $type !== 'receipt') {
                ApiError::abort(422, 'DOCUMENT_HAS_NO_LINES', 'Chứng từ phải có ít nhất một dòng.');
            }
            $this->assertLineSkus($type, $lines);

            $documentId = (string) Str::uuid();
            $docNo = $validated['doc_no'] ?? ($type === 'receipt'
                ? app(InboundDocumentNumber::class)->next()
                : 'PX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)));
            if ($type === 'receipt' && isset($validated['doc_no'])) {
                app(InboundDocumentNumber::class)->guardExplicit($docNo);
            }
            if (isset($validated['doc_no'])) {
                app(OutboundDocumentNumber::class)->guardExplicit($docNo);
            }
            DB::table('stock_documents')->insert([
                'id' => $documentId,
                'doc_type' => $type,
                'doc_no' => $docNo,
                'doc_date' => $validated['doc_date'] ?? now()->toDateString(),
                'status' => 'DRAFT',
                // Mini App draft expiry is derived from created_at + 07 days.
                // Do not persist a second deadline column: existing and new documents
                // therefore follow one rule without changing the stock_documents schema.
                'src_warehouse_id' => $type === 'issue' ? $warehouseId : null,
                'dst_warehouse_id' => $type === 'receipt' ? $warehouseId : null,
                'source_name' => $validated['source_name'] ?? null,
                'outbound_request_id' => $validated['outbound_request_id'] ?? null,
                'agency_id' => $validated['agency_id'] ?? null,
                'recipient_name' => $validated['recipient_name'] ?? null,
                'recipient_address' => $validated['recipient_address'] ?? null,
                'note' => $validated['note'] ?? null,
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $index => $line) {
                DB::table('stock_document_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'stock_document_id' => $documentId,
                    'line_no' => $index + 1,
                    'product_id' => $line['product_id'],
                    'outbound_request_line_id' => $line['outbound_request_line_id'] ?? null,
                    'import_batch_id' => $line['import_batch_id'] ?? null,
                    'qty_planned' => (int) $line['qty_planned'],
                    'qty_actual' => (int) $line['qty_planned'],
                    'scanned_qty' => 0,
                    'note' => $line['note'] ?? null,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($type === 'issue' && ! empty($validated['outbound_request_id'])) {
                // ERD bảng 37: toa OPEN -> CONSUMED khi có phiếu xuất active gắn toa.
                DB::table('outbound_requests')->where('id', $validated['outbound_request_id'])->update([
                    'status' => 'CONSUMED',
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                    'updated_by' => $actorId,
                ]);
            }

            AuditLogger::record(
                action: $type === 'receipt' ? 'CREATE_INBOUND' : 'CREATE_OUTBOUND',
                objectType: 'stock_document',
                objectId: $documentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                afterData: ['status' => 'DRAFT', 'version' => 1, 'outbound_request_id' => $validated['outbound_request_id'] ?? null],
            );

            event(new StockDocumentCreated($documentId, (string) $docNo, $type, $warehouseId, $actorId));

            return $this->documentPayload($documentId);
        }, 3);
    }

    private function updateDocument(Request $request, string $id, string $type, array $validated)
    {
        $expectedVersion = $this->expectedVersion($request);
        $payload = DB::transaction(function () use ($id, $type, $validated, $expectedVersion): array {
            $document = $this->lockDocument($id, $type);
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được cập nhật.');
            }
            $this->assertDraftNotExpired($document);

            if ($type === 'receipt' && isset($validated['doc_no']) && $validated['doc_no'] !== $document->doc_no) {
                app(InboundDocumentNumber::class)->guardExplicit($validated['doc_no']);
            }
            if (isset($validated['doc_no']) && $validated['doc_no'] !== $document->doc_no) {
                app(OutboundDocumentNumber::class)->guardExplicit($validated['doc_no']);
            }

            $lineUpdateMode = 'NONE';
            if (isset($validated['lines'])) {
                if ($document->outbound_request_id !== null) {
                    ApiError::abort(409, 'SOURCE_REQUEST_IMMUTABLE', 'Outbound sinh từ toa Excel không được thay line nguồn; hãy Cancel và tạo request mới.');
                }

                $this->assertLineSkus($type, $validated['lines']);
                $hasScanEvidence = $document->status === 'SCANNING' && $this->hasScanEvidence($id, $type);

                if ($hasScanEvidence && $type === 'receipt') {
                    $this->updateScannedInboundLines($id, $validated['lines']);
                    $lineUpdateMode = 'PRESERVE_SCAN_EVIDENCE';
                } else {
                    if ($hasScanEvidence) {
                        ApiError::abort(409, 'SCANNED_DOCUMENT_IMMUTABLE', 'Chứng từ đã có scan evidence; hãy Cancel và tạo lại để không xóa lịch sử scan.');
                    }
                    $this->replaceDocumentLines($id, $validated['lines']);
                    $lineUpdateMode = 'REPLACE_UNSCANNED_LINES';
                }
            }

            unset($validated['lines']);
            $validated = $this->normalizeSourceReference($validated);
            $allowed = $type === 'receipt'
                ? ['doc_no', 'doc_date', 'source_name', 'note']
                : ['doc_no', 'doc_date', 'agency_id', 'recipient_name', 'recipient_address', 'note'];
            $updates = collect($validated)->only($allowed)->all();
            $updates['version'] = $expectedVersion + 1;
            $updates['updated_by'] = $this->actorId();
            $updates['updated_at'] = now();
            DB::table('stock_documents')->where('id', $id)->update($updates);

            AuditLogger::record(
                action: 'UPDATE_STOCK_DOCUMENT',
                objectType: 'stock_document',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                beforeData: ['version' => $expectedVersion, 'status' => $document->status],
                afterData: [
                    'version' => $expectedVersion + 1,
                    'status' => $document->status,
                    'line_update_mode' => $lineUpdateMode,
                ],
            );

            return $this->documentPayload($id);
        }, 3);

        return $this->respondSuccess($payload, 'Cập nhật chứng từ thành công.')
            ->header('ETag', '"'.((int) ($payload['version'] ?? 0)).'"');
    }

    private function replaceDocumentLines(string $documentId, array $lines): void
    {
        DB::table('stock_document_lines')->where('stock_document_id', $documentId)->delete();
        foreach (array_values($lines) as $index => $line) {
            DB::table('stock_document_lines')->insert([
                'id' => (string) Str::uuid(),
                'stock_document_id' => $documentId,
                'line_no' => $index + 1,
                'product_id' => $line['product_id'],
                'import_batch_id' => $line['import_batch_id'] ?? null,
                'qty_planned' => (int) $line['qty_planned'],
                'qty_actual' => (int) $line['qty_planned'],
                'scanned_qty' => 0,
                'note' => $line['note'] ?? null,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function updateScannedInboundLines(string $documentId, array $lines): void
    {
        $existing = DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->orderBy('line_no')
            ->lockForUpdate()
            ->get();
        $submitted = array_values($lines);

        if ($existing->count() !== count($submitted)) {
            ApiError::abort(
                409,
                'SCANNED_LINE_STRUCTURE_IMMUTABLE',
                'Phiếu đã có scan evidence; không được thêm hoặc xóa dòng. Chỉ được sửa Expected qty/ghi chú trên đúng dòng hiện hữu.'
            );
        }

        foreach ($submitted as $index => $line) {
            $current = $existing[$index] ?? null;
            $lineId = isset($line['id']) ? (string) $line['id'] : '';
            if ($current === null || $lineId === '' || $lineId !== (string) $current->id) {
                ApiError::abort(
                    409,
                    'SCANNED_LINE_STRUCTURE_IMMUTABLE',
                    'Phiếu đã có scan evidence; thứ tự và định danh dòng phải được giữ nguyên.'
                );
            }
            if ((string) $line['product_id'] !== (string) $current->product_id) {
                ApiError::abort(
                    409,
                    'SCANNED_LINE_STRUCTURE_IMMUTABLE',
                    'Phiếu đã có scan evidence; SKU của dòng đã quét không được thay đổi.'
                );
            }
            if (array_key_exists('import_batch_id', $line)
                && (string) ($line['import_batch_id'] ?? '') !== (string) ($current->import_batch_id ?? '')) {
                ApiError::abort(
                    409,
                    'SCANNED_LINE_STRUCTURE_IMMUTABLE',
                    'Phiếu đã có scan evidence; import batch của dòng đã quét không được thay đổi.'
                );
            }

            $expectedQty = (int) $line['qty_planned'];
            $scannedQty = (int) $current->scanned_qty;
            if ($expectedQty < $scannedQty) {
                ApiError::abort(422, 'EXPECTED_QTY_BELOW_SCANNED', 'Expected qty không được nhỏ hơn số lượng đã scan.', [
                    'line_id' => (string) $current->id,
                    'expected_qty' => $expectedQty,
                    'scanned_qty' => $scannedQty,
                ]);
            }

            DB::table('stock_document_lines')->where('id', $current->id)->update([
                'qty_planned' => $expectedQty,
                'qty_actual' => $expectedQty,
                'note' => $line['note'] ?? null,
                'version' => ((int) ($current->version ?? 0)) + 1,
                'updated_at' => now(),
            ]);
        }
    }

    private function cancelDocument(Request $request, string $id, string $type)
    {
        // reason ghi vào stock_documents.cancel_reason varchar(1000); max cũ (2000) rộng
        // hơn cột DB nên lý do 1001-2000 ký tự sẽ vỡ ở QueryException 500 thay vì 422.
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $expectedVersion = $this->expectedVersion($request);

        $payload = DB::transaction(function () use ($id, $type, $validated, $expectedVersion): array {
            $document = $this->lockDocument($id, $type);
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được Cancel.');
            }

            DB::table('stock_documents')->where('id', $id)->update([
                'status' => 'CANCELLED',
                'cancel_reason' => $validated['reason'],
                'cancelled_at' => now(),
                'cancelled_by' => $this->actorId(),
                'version' => $expectedVersion + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);

            if ($type === 'issue' && $document->outbound_request_id !== null) {
                // State model: CANCELLED là terminal, muốn xuất lại thì tạo phiếu mới
                // từ cùng toa — trả toa CONSUMED -> OPEN nếu không còn phiếu active/POSTED.
                $stillUsed = DB::table('stock_documents')
                    ->where('outbound_request_id', $document->outbound_request_id)
                    ->where('id', '!=', $id)
                    ->whereIn('status', ['DRAFT', 'SCANNING', 'POSTED'])
                    ->exists();
                if (! $stillUsed) {
                    DB::table('outbound_requests')
                        ->where('id', $document->outbound_request_id)
                        ->where('status', 'CONSUMED')
                        ->update([
                            'status' => 'OPEN',
                            'version' => DB::raw('version + 1'),
                            'updated_at' => now(),
                            'updated_by' => $this->actorId(),
                        ]);
                }
            }

            AuditLogger::record(
                action: 'CANCEL_STOCK_DOCUMENT',
                objectType: 'stock_document',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                reason: $validated['reason'],
                beforeData: ['status' => $document->status, 'version' => $expectedVersion],
                afterData: ['status' => 'CANCELLED', 'version' => $expectedVersion + 1],
            );

            $warehouseId = $type === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id;
            event(new StockDocumentCancelled(
                $id,
                (string) $document->doc_no,
                $type,
                $warehouseId !== null ? (string) $warehouseId : null,
                $this->actorId(),
            ));
            // Huỷ chứng từ là stock-neutral (không qua mutateBalance) nhưng đổi status
            // phiếu và trạng thái toa nguồn — dữ liệu mà read cache phiếu nhập/xuất và
            // danh sách toa đang phục vụ.
            DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

            return $this->documentPayload($id);
        }, 3);

        return $this->respondSuccess($payload, 'Đã hủy chứng từ.')
            ->header('ETag', '"'.((int) ($payload['version'] ?? 0)).'"');
    }

    private function hydrateOutboundRequest(array $validated): array
    {
        $request = DB::table('outbound_requests')->where('id', $validated['outbound_request_id'])->lockForUpdate()->first();
        if ($request === null || strtoupper((string) $request->status) === 'CANCELLED') {
            ApiError::abort(422, 'OUTBOUND_REQUEST_NOT_ACTIVE', 'Toa xuất không tồn tại hoặc không còn active.');
        }
        if (DB::table('stock_documents')->where('outbound_request_id', $request->id)->whereIn('status', ['DRAFT', 'SCANNING', 'POSTED'])->exists()) {
            ApiError::abort(409, 'OUTBOUND_REQUEST_ALREADY_USED', 'Toa xuất đã có outbound document.');
        }

        $requestLines = DB::table('outbound_request_lines')->where('outbound_request_id', $request->id)->orderBy('line_no')->get();
        if ($requestLines->isEmpty()) {
            ApiError::abort(422, 'OUTBOUND_REQUEST_EMPTY', 'Toa xuất không có line hợp lệ.');
        }

        $validated['agency_id'] = $request->agency_id ?? $validated['agency_id'] ?? null;
        $validated['recipient_name'] = (string) $request->recipient_name;
        $validated['recipient_address'] = (string) $request->recipient_address;
        $validated['lines'] = $requestLines->map(fn (object $line): array => [
            'product_id' => (string) $line->sku_id,
            'qty_planned' => (int) $line->required_qty,
            'outbound_request_line_id' => (string) $line->id,
        ])->all();

        return $validated;
    }

    private function assertLineSkus(string $type, array $lines): void
    {
        foreach ($lines as $line) {
            $sku = DB::table('sku')->where('sku_id', $line['product_id'])->first();
            if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'Mọi line phải dùng SKU ACTIVE.');
            }
            if ($type === 'issue' && strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Outbound sản phẩm chỉ chấp nhận SKU PRODUCT; COMPONENT chỉ xuất qua Component Issue WARRANTY.');
            }
        }
    }

    private function hasScanEvidence(string $documentId, string $type): bool
    {
        $table = $type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches';
        $lineColumn = $type === 'receipt' ? 'inbound_line_id' : 'outbound_line_id';

        return DB::table("{$table} as e")
            ->join('stock_document_lines as l', 'l.id', '=', "e.{$lineColumn}")
            ->where('l.stock_document_id', $documentId)
            // Entry đã REMOVED không còn là scan evidence sống: nếu người dùng gỡ
            // hết mã đã quét thì được phép sửa lại cấu trúc dòng như phiếu chưa quét.
            ->where(function ($query): void {
                $query->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
            })
            ->exists();
    }

    private function lockDocument(string $id, string $type): object
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', $type)->lockForUpdate()->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
        }

        return $document;
    }

    private function assertActiveWarehouse(string $warehouseId): void
    {
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
        if ($warehouse === null) {
            ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
        }
        if (strtoupper((string) $warehouse->status) !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho đang tạm ngừng, không thể tạo hoặc tiếp tục chứng từ.', [
                'warehouse_id' => (string) $warehouse->id,
                'warehouse_status' => (string) $warehouse->status,
                'is_suspended' => true,
                'suspension_reason' => $warehouse->suspension_reason ?? null,
            ]);
        }
    }

    /**
     * Hết hạn không xoá và không tự đổi trạng thái để bảo toàn audit. Client phải
     * hiển thị nháp hết hạn, khóa "Tiếp tục" và cho phép người dùng Cancel để
     * chuyển chứng từ sang CANCELLED có lý do rõ ràng.
     */
    private function assertDraftNotExpired(object $document): void
    {
        if ((string) $document->status !== 'DRAFT') {
            return;
        }
        // Một nguồn sự thật: hạn nháp = created_at + 07 ngày. Không có cột deadline
        // riêng nên phiếu cũ và phiếu mới luôn dùng cùng một quy tắc.
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

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho mutation trên chứng từ hiện hữu.');
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
        if ((int) $document->version !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Document version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) $document->version,
            ]);
        }
    }

    /**
     * Annex A / Annex D dùng tên `source_reference`; ERD bảng 26 dùng cột
     * `source_name`. Chuẩn hoá về đúng một cột vật lý, ưu tiên giá trị client gửi
     * theo tên chuẩn của SRS khi cả hai cùng có mặt.
     */
    private function normalizeSourceReference(array $validated): array
    {
        if (array_key_exists('source_reference', $validated)) {
            $validated['source_name'] = $validated['source_reference'];
            unset($validated['source_reference']);
        }

        return $validated;
    }

    private function documentPayload(string $id): array
    {
        $document = DB::table('stock_documents')->where('id', $id)->first();
        $warehouseId = $document->doc_type === 'receipt'
            ? $document->dst_warehouse_id
            : $document->src_warehouse_id;
        $warehouse = $warehouseId === null ? null : DB::table('warehouses')->where('id', $warehouseId)->first();
        $draftExpiresAt = empty($document->created_at)
            ? null
            : Carbon::parse($document->created_at)->addWeek();
        $draftExpired = (string) $document->status === 'DRAFT'
            && $draftExpiresAt !== null
            && now()->greaterThanOrEqualTo($draftExpiresAt);
        $warehouseSuspended = $warehouse !== null
            && strtoupper((string) $warehouse->status) !== 'ACTIVE';
        $canContinue = in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)
            && ! $draftExpired
            && ! $warehouseSuspended;

        return [
            ...(array) $document,
            // Alias đọc theo tên field của Annex A / Annex D SCR-IN-01.
            'source_reference' => $document->source_name ?? null,
            'draft' => [
                'expires_at' => $draftExpiresAt,
                'is_expired' => $draftExpired,
                'retention_days' => 7,
            ],
            'warehouse' => $warehouse === null ? null : [
                'id' => (string) $warehouse->id,
                'code' => (string) $warehouse->code,
                'name' => (string) $warehouse->name,
                'status' => (string) $warehouse->status,
                'is_suspended' => $warehouseSuspended,
                'suspension_reason' => $warehouse->suspension_reason ?? null,
            ],
            'can_continue' => $canContinue,
            'lines' => DB::table('stock_document_lines')->where('stock_document_id', $id)->orderBy('line_no')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
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
}
