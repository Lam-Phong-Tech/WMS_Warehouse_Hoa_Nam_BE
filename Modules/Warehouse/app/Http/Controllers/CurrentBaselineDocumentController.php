<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineDocumentController extends Controller
{
    use ApiResponse;

    /**
     * Annex D SCR-IN-01: bảng mã đã quét của phiếu nhập (mã / SKU / kết quả /
     * nguồn CAMERA hay nhập tay) phải đọc lại được sau khi tải lại trang —
     * inbound_scan_entries đã lưu scan_source nhưng trước đó không có endpoint đọc.
     */
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
        ];
        // item_code chỉ tồn tại trên nhánh ERD PostgreSQL (migration bổ sung cột
        // này dùng cú pháp PG nên bị bỏ qua ở sqlite) — chọn có điều kiện để
        // endpoint chạy được trên cả hai driver mà CI đang dùng.
        if (Schema::hasColumn('item_instances', 'item_code')) {
            $columns[] = 'i.item_code';
        }

        // status=ACTIVE (mặc định hiển thị của IN-03) lọc bỏ entry đã gỡ; status=ALL
        // trả cả lịch sử REMOVED để đối soát/audit.
        $statusFilter = strtoupper(trim((string) $request->query('status', 'ACTIVE')));

        $page = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'e.item_id')
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
            'doc_date' => ['nullable', 'date'],
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            // Annex A / Annex D SCR-IN-01 đặt tên field là `source_reference` max 200;
            // ERD bảng 26 lưu cột `source_name` varchar(255). Nhận cả hai tên và siết
            // xuống 200 để thoả contract SRS mà vẫn nằm trong giới hạn cột của ERD.
            'source_name' => ['nullable', 'string', 'max:200'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'lines.*.qty_planned' => ['required', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->createIdempotent($request, 'receipt', $validated);
    }

    // §8.1 (Outbound request / Excel intake): "Confirm creates exactly one outbound
    // DRAFT; no direct POST /outbound-documents create operation." Annex C cũng không
    // khai createOutboundDocument/updateOutboundDocument, nên storeOutbound/updateOutbound
    // đã được gỡ khỏi surface — phiếu xuất chỉ sinh từ POST
    // /outbound-request-imports/{id}/confirm và sửa nội dung toa phải làm ở bước Confirm.

    public function updateInbound(Request $request, string $id)
    {
        $validated = $request->validate([
            // DB có UNIQUE(doc_no); thiếu rule ở update sẽ nổ QueryException 500
            // thay vì 422 VALIDATION_ERROR theo §8.2.
            'doc_no' => ['sometimes', 'string', 'max:80', Rule::unique('stock_documents', 'doc_no')->ignore($id, 'id')],
            'doc_date' => ['sometimes', 'date'],
            'source_name' => ['nullable', 'string', 'max:200'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->updateDocument($request, $id, 'receipt', $validated);
    }

    public function cancelInbound(Request $request, string $id)
    {
        return $this->cancelDocument($request, $id, 'receipt');
    }

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

                return [201, $this->envelope('Document created.', $document)];
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

            $lines = array_values($validated['lines'] ?? []);
            if ($lines === []) {
                ApiError::abort(422, 'DOCUMENT_HAS_NO_LINES', 'Chứng từ phải có ít nhất một dòng.');
            }
            $this->assertLineSkus($type, $lines);

            $documentId = (string) Str::uuid();
            DB::table('stock_documents')->insert([
                'id' => $documentId,
                'doc_type' => $type,
                'doc_no' => $validated['doc_no'] ?? (($type === 'receipt' ? 'PN-' : 'PX-').now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'doc_date' => $validated['doc_date'] ?? now()->toDateString(),
                'status' => 'DRAFT',
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
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
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
        if ($warehouse === null || strtoupper((string) $warehouse->status) !== 'ACTIVE') {
            ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Chỉ kho ACTIVE được dùng cho stock document.');
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

        return [
            ...(array) $document,
            // Alias đọc theo tên field của Annex A / Annex D SCR-IN-01.
            'source_reference' => $document->source_name ?? null,
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

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
