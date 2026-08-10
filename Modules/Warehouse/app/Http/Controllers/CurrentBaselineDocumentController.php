<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineDocumentController extends Controller
{
    use ApiResponse;

    public function storeInbound(Request $request)
    {
        $validated = $request->validate([
            'doc_no' => ['nullable', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            'doc_date' => ['nullable', 'date'],
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'lines.*.qty_planned' => ['required', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->createIdempotent($request, 'receipt', $validated);
    }

    public function storeOutbound(Request $request)
    {
        $validated = $request->validate([
            'doc_no' => ['nullable', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            'doc_date' => ['nullable', 'date'],
            'src_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'outbound_request_id' => ['nullable', 'uuid', 'exists:outbound_requests,id'],
            'agency_id' => ['nullable', 'uuid', 'exists:agencies,agency_id'],
            'recipient_name' => ['nullable', 'string', 'max:200'],
            'recipient_address' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'required_without:outbound_request_id', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->createIdempotent($request, 'issue', $validated);
    }

    public function updateInbound(Request $request, string $id)
    {
        $validated = $request->validate([
            'doc_no' => ['sometimes', 'string', 'max:80'],
            'doc_date' => ['sometimes', 'date'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->updateDocument($request, $id, 'receipt', $validated);
    }

    public function updateOutbound(Request $request, string $id)
    {
        $validated = $request->validate([
            'doc_no' => ['sometimes', 'string', 'max:80'],
            'doc_date' => ['sometimes', 'date'],
            'agency_id' => ['nullable', 'uuid', 'exists:agencies,agency_id'],
            'recipient_name' => ['nullable', 'string', 'max:200'],
            'recipient_address' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->updateDocument($request, $id, 'issue', $validated);
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
            return $this->respondCreated($document, $type === 'receipt' ? 'Tạo phiếu nhập kho thành công.' : 'Tạo phiếu xuất kho thành công.');
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

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function createDocument(string $type, array $validated, string $actorId): array
    {
        return DB::transaction(function () use ($type, $validated, $actorId): array {
            $warehouseId = (string) ($type === 'receipt' ? $validated['dst_warehouse_id'] : $validated['src_warehouse_id']);
            $this->assertActiveWarehouse($warehouseId);

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

            if (isset($validated['lines'])) {
                if ($document->outbound_request_id !== null) {
                    ApiError::abort(409, 'SOURCE_REQUEST_IMMUTABLE', 'Outbound sinh từ toa Excel không được thay line nguồn; hãy Cancel và tạo request mới.');
                }
                if ($document->status === 'SCANNING' && $this->hasScanEvidence($id, $type)) {
                    ApiError::abort(409, 'SCANNED_DOCUMENT_IMMUTABLE', 'Chứng từ đã có scan evidence; hãy Cancel và tạo lại để không xóa lịch sử scan.');
                }
                $this->assertLineSkus($type, $validated['lines']);
                DB::table('stock_document_lines')->where('stock_document_id', $id)->delete();
                foreach (array_values($validated['lines']) as $index => $line) {
                    DB::table('stock_document_lines')->insert([
                        'id' => (string) Str::uuid(),
                        'stock_document_id' => $id,
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

            unset($validated['lines']);
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
                afterData: ['version' => $expectedVersion + 1, 'status' => $document->status],
            );

            return $this->documentPayload($id);
        }, 3);

        return $this->respondSuccess($payload, 'Cập nhật chứng từ thành công.');
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

        return $this->respondSuccess($payload, 'Đã hủy chứng từ.');
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
            ApiError::abort(412, 'PRECONDITION_FAILED', 'Document version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) $document->version,
            ]);
        }
    }

    private function documentPayload(string $id): array
    {
        $document = DB::table('stock_documents')->where('id', $id)->first();
        return [
            ...(array) $document,
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
