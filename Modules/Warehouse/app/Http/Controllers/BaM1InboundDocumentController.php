<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\IdempotencyStore;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Events\StockDocumentCreated;
use Modules\Warehouse\Services\InboundDocumentNumber;
use Modules\Warehouse\Services\OutboundDocumentNumber;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use OpenApi\Attributes as OA;

/**
 * BA GĐ1 2026-08-25 — Inbound canonical header-only document contract.
 *
 * PRODUCT inbound is scan-driven. The operator creates only the receipt header;
 * SKU lines and quantities are materialized from actual scans by
 * DiagnosticCurrentBaselineStockService. Manual expected SKU/qty lines are no
 * longer part of the canonical flow.
 */
class BaM1InboundDocumentController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActorOrNull;

    private function actorId(): string
    {
        $id = $this->actorIdOrNull();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Phiên đăng nhập không hợp lệ.');
        }

        return $id;
    }

    #[OA\Post(
        path: '/api/v1/inbound-documents',
        operationId: 'inbound_document_create_scan_driven',
        summary: 'Tạo đầu phiếu nhập kho (header-only, scan-driven)',
        description: 'GĐ1: luồng nhập PRODUCT là scan-driven — chỉ tạo đầu phiếu ở đây, KHÔNG khai SKU/số lượng dự '
            .'kiến. `lines` chỉ được chấp nhận nếu là mảng RỖNG (gửi lines không rỗng → 422 INBOUND_SCAN_DRIVEN). '
            .'SKU và số lượng thực nhận được hệ thống tự hình thành từ các lượt quét thực tế sau khi tạo đầu phiếu.',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['dst_warehouse_id'],
                properties: [
                    new OA\Property(property: 'doc_no', type: 'string', maxLength: 80, nullable: true, description: 'Tự sinh nếu bỏ trống'),
                    new OA\Property(property: 'doc_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'source_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'source_reference', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object'), nullable: true, maxItems: 0, description: 'Chỉ chấp nhận mảng rỗng — luồng hiện hành là scan-driven'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo đầu phiếu nhập thành công (status=DRAFT, chưa có dòng SKU)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inbound.create'),
            new OA\Response(response: 409, description: 'Xung đột Idempotency-Key'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ, kho không ACTIVE, hoặc gửi lines không rỗng (INBOUND_SCAN_DRIVEN)'),
        ]
    )]
    public function store(Request $request)
    {
        $this->rejectManualLines($request);
        $validated = $request->validate([
            'doc_no' => ['nullable', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            'doc_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'source_name' => ['nullable', 'string', 'max:200'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
            // Accepted only so old clients receive a business error instead of
            // an unknown-field ambiguity. Non-empty values are rejected above.
            'lines' => ['sometimes', 'array', 'max:0'],
        ]);

        $this->assertActiveWarehouse((string) $validated['dst_warehouse_id']);
        $actorId = $this->actorId();
        $key = trim((string) $request->header('Idempotency-Key'));
        $execute = function () use ($validated, $actorId): array {
            $document = $this->createHeader($validated, $actorId);

            return [201, $this->envelope($document, 'Đã tạo phiếu nhập. Quét hàng để hệ thống tự hình thành SKU và số lượng thực nhận.')];
        };

        if ($key === '') {
            [$code, $body] = $execute();

            return response()->json($body, $code)
                ->header('ETag', '"1"');
        }

        [$code, $body, $replayed] = IdempotencyStore::handle(
            $key,
            'create_inbound_document_scan_driven',
            $actorId,
            IdempotencyStore::requestHash($request),
            $execute
        );

        return response()->json($body, $code)
            ->header('Idempotent-Replay', $replayed ? 'true' : 'false')
            ->header('ETag', '"'.(int) data_get($body, 'data.version', 1).'"');
    }

    #[OA\Patch(
        path: '/api/v1/inbound-documents/{id}',
        operationId: 'inbound_document_update_scan_driven',
        summary: 'Cập nhật thông tin đầu phiếu nhập (DRAFT/SCANNING; scan-driven, không sửa SKU/số lượng qua đây)',
        description: 'Cùng ràng buộc `lines` với store(): chỉ chấp nhận mảng rỗng. Yêu cầu header `If-Match` = '
            .'version hiện tại của phiếu (thiếu/sai → 428 PRECONDITION_REQUIRED / 412 STALE_VERSION).',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'doc_no', type: 'string', maxLength: 80),
                    new OA\Property(property: 'doc_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'source_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'source_reference', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object'), nullable: true, maxItems: 0, description: 'Chỉ chấp nhận mảng rỗng — luồng hiện hành là scan-driven'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thông tin đầu phiếu nhập thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inbound.update'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu nhập'),
            new OA\Response(response: 409, description: 'Phiếu không ở trạng thái DRAFT/SCANNING'),
            new OA\Response(response: 412, description: 'STALE_VERSION — phiếu đã bị thay đổi, cần tải lại'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ, hoặc gửi lines không rỗng (INBOUND_SCAN_DRIVEN)'),
            new OA\Response(response: 428, description: 'Thiếu header If-Match hợp lệ'),
        ]
    )]
    public function update(Request $request, string $id)
    {
        $this->rejectManualLines($request);
        $validated = $request->validate([
            'doc_no' => ['sometimes', 'string', 'max:80', Rule::unique('stock_documents', 'doc_no')->ignore($id, 'id')],
            'doc_date' => array_merge(['sometimes'], InputLimits::pastDate()),
            'source_name' => ['nullable', 'string', 'max:200'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array', 'max:0'],
        ]);
        $expectedVersion = $this->expectedVersion($request);
        $actorId = $this->actorId();

        $row = DB::transaction(function () use ($id, $validated, $expectedVersion, $actorId): object {
            $document = DB::table('stock_documents')
                ->where('id', $id)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu DRAFT/SCANNING mới được cập nhật thông tin đầu phiếu.');
            }
            if ((int) $document->version !== $expectedVersion) {
                ApiError::abort(412, 'STALE_VERSION', 'Phiếu nhập đã thay đổi. Hãy tải lại dữ liệu trước khi lưu.', [
                    'expected_version' => $expectedVersion,
                    'actual_version' => (int) $document->version,
                ]);
            }

            if (isset($validated['doc_no']) && $validated['doc_no'] !== $document->doc_no) {
                app(InboundDocumentNumber::class)->guardExplicit($validated['doc_no']);
                app(OutboundDocumentNumber::class)->guardExplicit($validated['doc_no']);
            }

            $updates = [];
            foreach (['doc_no', 'doc_date', 'note'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }
            if (array_key_exists('source_reference', $validated) || array_key_exists('source_name', $validated)) {
                $updates['source_name'] = $validated['source_reference'] ?? $validated['source_name'] ?? null;
            }
            $updates['version'] = $expectedVersion + 1;
            $updates['updated_by'] = $actorId;
            $updates['updated_at'] = now();
            DB::table('stock_documents')->where('id', $id)->update($updates);

            $after = DB::table('stock_documents')->where('id', $id)->first();
            AuditLogger::record(
                action: 'INBOUND_HEADER_UPDATE_SCAN_DRIVEN',
                objectType: 'stock_document',
                objectId: $id,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                beforeData: (array) $document,
                afterData: (array) $after,
            );
            // Sửa header phiếu là stock-neutral nhưng đổi đúng các cột mà read cache
            // danh sách/chi tiết phiếu nhập đang phục vụ.
            DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

            return $after;
        }, 3);

        return response()->json($this->envelope($this->normalizeDocument($row), 'Đã cập nhật thông tin phiếu nhập.'))
            ->header('ETag', '"'.(int) $row->version.'"');
    }

    private function createHeader(array $validated, string $actorId): array
    {
        return DB::transaction(function () use ($validated, $actorId): array {
            $id = (string) Str::uuid();
            $source = $validated['source_reference'] ?? $validated['source_name'] ?? null;
            $now = now();
            if (isset($validated['doc_no'])) {
                app(InboundDocumentNumber::class)->guardExplicit($validated['doc_no']);
                app(OutboundDocumentNumber::class)->guardExplicit($validated['doc_no']);
            }
            DB::table('stock_documents')->insert([
                'id' => $id,
                'doc_type' => 'receipt',
                'doc_no' => $validated['doc_no'] ?? ('PN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'doc_date' => $validated['doc_date'] ?? now()->toDateString(),
                'status' => 'DRAFT',
                'src_warehouse_id' => null,
                'dst_warehouse_id' => $validated['dst_warehouse_id'],
                'source_name' => $source,
                'note' => $validated['note'] ?? null,
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = DB::table('stock_documents')->where('id', $id)->first();
            AuditLogger::record(
                action: 'INBOUND_CREATE_SCAN_DRIVEN',
                objectType: 'stock_document',
                objectId: $id,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                afterData: [
                    'status' => 'DRAFT',
                    'dst_warehouse_id' => $validated['dst_warehouse_id'],
                    'inbound_quantity_source' => 'ACTUAL_SCAN',
                    'manual_expected_lines' => false,
                ],
            );

            event(new StockDocumentCreated(
                $id,
                (string) $row->doc_no,
                'receipt',
                $validated['dst_warehouse_id'] !== null ? (string) $validated['dst_warehouse_id'] : null,
                $actorId,
            ));
            // Tạo phiếu nhập là stock-neutral nhưng phiếu mới phải hiện ngay ở danh
            // sách phiếu nhập đang đọc qua cache.
            DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

            return $this->normalizeDocument($row);
        }, 3);
    }

    private function normalizeDocument(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'stock_document_id' => (string) $row->id,
            'doc_no' => (string) $row->doc_no,
            'doc_type' => 'receipt',
            'doc_date' => $row->doc_date,
            'status' => (string) $row->status,
            'dst_warehouse_id' => (string) $row->dst_warehouse_id,
            'source_name' => $row->source_name,
            'source_reference' => $row->source_name,
            'note' => $row->note,
            'version' => (int) $row->version,
            'lines' => [],
            'qty_planned' => 0,
            'scanned_qty' => 0,
            'inbound_quantity_source' => 'ACTUAL_SCAN',
        ];
    }

    private function rejectManualLines(Request $request): void
    {
        $lines = $request->input('lines');
        if (is_array($lines) && $lines !== []) {
            ApiError::abort(
                422,
                'INBOUND_SCAN_DRIVEN',
                'Luồng nhập hiện hành không nhập SKU/số lượng dự kiến. Hãy tạo đầu phiếu rồi quét hàng thực tế.'
            );
        }
    }

    private function assertActiveWarehouse(string $warehouseId): void
    {
        $active = DB::table('warehouses')
            ->where('id', $warehouseId)
            ->where('status', 'ACTIVE')
            ->exists();
        if (! $active) {
            ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Kho nhận không tồn tại hoặc không hoạt động.');
        }
    }

    private function expectedVersion(Request $request): int
    {
        $raw = trim((string) $request->header('If-Match'));
        $raw = trim($raw, '" ');
        if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Thiếu If-Match hợp lệ khi cập nhật phiếu nhập.');
        }

        return (int) $raw;
    }

    private function envelope(array $data, string $message): array
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
