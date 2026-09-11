<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\DocumentAttachmentScanner;
use App\Support\DocumentEvidenceGuard;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\AuditLog\Services\DenyAuditRecorder;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use OpenApi\Attributes as OA;

/**
 * "Chứng từ nhập/xuất/hoàn trả": ảnh chụp/scan/PDF đính kèm phiếu nhập, phiếu xuất,
 * hoàn trả/điều chỉnh — lưu private, đủ dấu vết (checksum + audit log) để đối soát
 * sau này thay vì chỉ dựa vào dữ liệu nhập tay trên hệ thống.
 *
 * Cùng khuôn mẫu CurrentBaselineWarrantyAttachmentController (ERD §47
 * warranty_attachments): upload/list/download/delete đều stock-neutral, file lưu
 * private disk, chỉ trả metadata. Khác ở chỗ đây là bảng dùng chung cho 3 loại
 * phiếu (document_type INBOUND/OUTBOUND/REVERSAL, document_id polymorphic — xem
 * migration create_document_attachments_table).
 *
 * Quyền/warehouse-scope của 6 route lồng theo {id}/attachments được middleware
 * Enforce{Inbound,Outbound,Reversal}WarehouseScope xử lý sẵn (đọc route `id`, cùng
 * cách các route mutate khác của 3 luồng này đã dùng); hai route phẳng theo
 * attachmentId (download/destroy) tự kiểm scope thủ công trong controller vì không
 * có {id} phiếu trong route.
 */
class DocumentAttachmentController extends Controller
{
    use ApiResponse;
    use ResolvesActorOrNull { actorIdOrNull as actorId; }

    private const DISK = 'local';

    private const ATTACHMENT_TYPES = ['SOURCE_DOCUMENT', 'INVOICE', 'DELIVERY_NOTE', 'PHOTO', 'OTHER'];

    /** Cùng ceiling ảnh warranty (Annex D SCR-WAR-01) — chứng từ là ảnh/scan/PDF, không phải video. */
    private const MAX_ACTIVE_ATTACHMENTS = 20;

    private const MAX_FILE_BYTES = 15 * 1024 * 1024;

    private const MAX_UPLOAD_KB = 15360;

    /**
     * WWHN-SEC-09 (BFLA): hai route phẳng download/destroy gom quyền của cả 3
     * luồng trong middleware (`inbound.*|outbound.*|inventory.*`) nên một actor
     * chỉ có quyền outbound vẫn lọt qua middleware rồi thao tác lên attachment
     * của phiếu NHẬP hay HOÀN TRẢ — nguy hiểm nhất là xoá attachment REVERSAL
     * (reversal post bắt buộc còn >=1 evidence ACTIVE). Bảng này khớp lại quyền
     * theo đúng document_type của attachment, dùng đúng bộ quyền các route lồng
     * theo loại phiếu đã khai.
     */
    private const TYPE_OPERATION_PERMISSIONS = [
        DocumentEvidenceGuard::TYPE_INBOUND => [
            'download' => ['inbound.view', 'inbound.create', 'inbound.scan'],
            'delete' => ['inbound.create', 'inbound.scan'],
        ],
        DocumentEvidenceGuard::TYPE_OUTBOUND => [
            'download' => ['outbound.view', 'outbound.scan', 'outbound.post'],
            'delete' => ['outbound.scan', 'outbound.post'],
        ],
        DocumentEvidenceGuard::TYPE_REVERSAL => [
            'download' => ['inventory.view', 'inventory.reversal.post'],
            'delete' => ['inventory.reversal.post'],
        ],
    ];

    // ------------------------------------------------------------------
    // INBOUND
    // ------------------------------------------------------------------

    #[OA\Get(
        path: '/api/v1/inbound-documents/{id}/attachments',
        operationId: 'inbound_attachment_list',
        summary: 'Danh sách chứng từ đính kèm của phiếu nhập',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Danh sách chứng từ đính kèm')]
    )]
    public function indexInbound(Request $request, string $id)
    {
        $document = $this->requireStockDocument($id, 'receipt', 'Không tìm thấy phiếu nhập.');

        return $this->indexPayload($request, DocumentEvidenceGuard::TYPE_INBOUND, $id, [
            'requires_evidence' => DocumentEvidenceGuard::inboundRequiresEvidence($document->source_name ?? null),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/attachments',
        operationId: 'uploadInboundAttachment',
        summary: 'Tải lên chứng từ đính kèm cho phiếu nhập (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Ảnh jpg/jpeg/png/webp hoặc PDF, <=15MB.'),
                        new OA\Property(property: 'attachment_type', type: 'string', enum: self::ATTACHMENT_TYPES, nullable: true, example: 'SOURCE_DOCUMENT'),
                        new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Chứng từ đã lưu; tồn kho chưa thay đổi (stock_effect=NONE).')]
    )]
    public function storeInbound(Request $request, string $id)
    {
        $document = $this->requireStockDocument($id, 'receipt', 'Không tìm thấy phiếu nhập.');
        if (in_array((string) $document->status, ['POSTED', 'CANCELLED'], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Phiếu nhập đã đóng (POSTED/CANCELLED); không thể đính kèm thêm chứng từ.');
        }

        return $this->storeAttachment($request, DocumentEvidenceGuard::TYPE_INBOUND, $id);
    }

    // ------------------------------------------------------------------
    // OUTBOUND
    // ------------------------------------------------------------------

    #[OA\Get(
        path: '/api/v1/outbound-documents/{id}/attachments',
        operationId: 'outbound_attachment_list',
        summary: 'Danh sách chứng từ đính kèm của phiếu xuất',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Danh sách chứng từ đính kèm')]
    )]
    public function indexOutbound(Request $request, string $id)
    {
        $this->requireStockDocument($id, 'issue', 'Không tìm thấy phiếu xuất.');

        return $this->indexPayload($request, DocumentEvidenceGuard::TYPE_OUTBOUND, $id, [
            // outbound luôn có outbound_request_imports (Excel) làm chứng từ nguồn bắt
            // buộc (CHECK chk_issue_requires_outbound_request) nên không chặn Post ở đây.
            'requires_evidence' => false,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/attachments',
        operationId: 'uploadOutboundAttachment',
        summary: 'Tải lên chứng từ đính kèm cho phiếu xuất (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Ảnh jpg/jpeg/png/webp hoặc PDF, <=15MB.'),
                        new OA\Property(property: 'attachment_type', type: 'string', enum: self::ATTACHMENT_TYPES, nullable: true, example: 'DELIVERY_NOTE'),
                        new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Chứng từ đã lưu; tồn kho chưa thay đổi (stock_effect=NONE).')]
    )]
    public function storeOutbound(Request $request, string $id)
    {
        $document = $this->requireStockDocument($id, 'issue', 'Không tìm thấy phiếu xuất.');
        if (in_array((string) $document->status, ['POSTED', 'CANCELLED'], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Phiếu xuất đã đóng (POSTED/CANCELLED); không thể đính kèm thêm chứng từ.');
        }

        return $this->storeAttachment($request, DocumentEvidenceGuard::TYPE_OUTBOUND, $id);
    }

    // ------------------------------------------------------------------
    // REVERSAL (hoàn trả / điều chỉnh)
    // ------------------------------------------------------------------

    #[OA\Get(
        path: '/api/v1/reversal-documents/{id}/attachments',
        operationId: 'reversal_attachment_list',
        summary: 'Danh sách chứng từ đính kèm của phiếu hoàn trả/điều chỉnh',
        security: [['bearerAuth' => []]],
        tags: ['11. Reversal'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Danh sách chứng từ đính kèm')]
    )]
    public function indexReversal(Request $request, string $id)
    {
        $this->requireReversal($id);

        return $this->indexPayload($request, DocumentEvidenceGuard::TYPE_REVERSAL, $id, [
            // Hoàn trả/điều chỉnh luôn bắt buộc chứng từ (DocumentEvidenceGuard).
            'requires_evidence' => true,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/reversal-documents/{id}/attachments',
        operationId: 'uploadReversalAttachment',
        summary: 'Tải lên chứng từ đính kèm cho phiếu hoàn trả/điều chỉnh (stock-neutral, bắt buộc trước khi Post)',
        security: [['bearerAuth' => []]],
        tags: ['11. Reversal'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Ảnh jpg/jpeg/png/webp hoặc PDF, <=15MB.'),
                        new OA\Property(property: 'attachment_type', type: 'string', enum: self::ATTACHMENT_TYPES, nullable: true, example: 'SOURCE_DOCUMENT'),
                        new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
                    ]
                )
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Chứng từ đã lưu; tồn kho chưa thay đổi (stock_effect=NONE).')]
    )]
    public function storeReversal(Request $request, string $id)
    {
        $reversal = $this->requireReversal($id);
        if ((string) $reversal->status === 'CANCELLED') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Reversal đã huỷ; không thể đính kèm thêm chứng từ.');
        }

        return $this->storeAttachment($request, DocumentEvidenceGuard::TYPE_REVERSAL, $id);
    }

    // ------------------------------------------------------------------
    // Shared: list "cần bổ sung chứng từ"
    // ------------------------------------------------------------------

    #[OA\Get(
        path: '/api/v1/document-attachments/pending-evidence',
        operationId: 'document_attachment_pending_evidence',
        summary: 'Danh sách phiếu (nhập/hoàn trả) đang thiếu chứng từ bắt buộc',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))],
        responses: [new OA\Response(response: 200, description: 'Danh sách phiếu cần bổ sung chứng từ, mới nhất trước')]
    )]
    public function pendingEvidence(Request $request)
    {
        $limit = min(max((int) $request->query('per_page', 20), 1), 100);
        $items = [];

        // INBOUND: SCANNING/DRAFT, source_name rỗng, chưa có attachment ACTIVE nào.
        $inboundCandidates = DB::table('stock_documents')
            ->where('doc_type', 'receipt')
            ->whereIn('status', ['draft', 'DRAFT', 'scanning', 'SCANNING'])
            ->where(function ($q) {
                $q->whereNull('source_name')->orWhere('source_name', '');
            })
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get(['id', 'doc_no', 'dst_warehouse_id', 'status', 'created_at']);
        foreach ($inboundCandidates as $doc) {
            if (DocumentEvidenceGuard::hasActiveEvidence(DocumentEvidenceGuard::TYPE_INBOUND, (string) $doc->id)) {
                continue;
            }
            $items[] = [
                'document_type' => DocumentEvidenceGuard::TYPE_INBOUND,
                'document_id' => (string) $doc->id,
                'document_no' => (string) $doc->doc_no,
                'warehouse_id' => $doc->dst_warehouse_id !== null ? (string) $doc->dst_warehouse_id : null,
                'status' => (string) $doc->status,
                'missing_reason' => 'Không có source_name (chứng từ nguồn) và chưa đính kèm chứng từ.',
                'created_at' => $doc->created_at,
            ];
        }

        // REVERSAL: DRAFT, chưa có attachment ACTIVE nào — luôn bắt buộc.
        $reversalTable = DB::table('reversal_document')
            ->where('status', 'DRAFT')
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get(['reversal_document_id', 'document_no', 'status', 'created_at']);
        foreach ($reversalTable as $doc) {
            $id = (string) $doc->reversal_document_id;
            if (DocumentEvidenceGuard::hasActiveEvidence(DocumentEvidenceGuard::TYPE_REVERSAL, $id)) {
                continue;
            }
            $items[] = [
                'document_type' => DocumentEvidenceGuard::TYPE_REVERSAL,
                'document_id' => $id,
                'document_no' => (string) $doc->document_no,
                'warehouse_id' => null,
                'status' => (string) $doc->status,
                'missing_reason' => 'Hoàn trả/điều chỉnh chưa đính kèm chứng từ (bắt buộc trước khi Post).',
                'created_at' => $doc->created_at,
            ];
        }

        usort($items, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $this->respondSuccess(array_slice($items, 0, $limit), 'Danh sách phiếu cần bổ sung chứng từ.');
    }

    // ------------------------------------------------------------------
    // Shared: download / delete (flat theo attachmentId)
    // ------------------------------------------------------------------

    #[OA\Get(
        path: '/api/v1/document-attachments/{attachmentId}/download',
        operationId: 'document_attachment_download',
        summary: 'Tải chứng từ đính kèm',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Nội dung tệp (binary)')]
    )]
    public function download(Request $request, string $attachmentId)
    {
        $row = $this->requireAttachment($attachmentId);
        $this->assertTypePermission($request, $row, 'download');
        $this->assertWarehouseScope($request, $row);

        $path = (string) $row->storage_key;
        $disk = (string) ($row->storage_disk ?? self::DISK);
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp chứng từ trên storage.');
        }

        AuditLogger::record(
            action: 'DOCUMENT_ATTACHMENT_DOWNLOADED',
            objectType: 'document_attachment',
            objectId: $attachmentId,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
            afterData: ['document_type' => (string) $row->document_type, 'document_id' => (string) $row->document_id],
        );

        return Storage::disk($disk)->download($path, (string) $row->file_name);
    }

    #[OA\Delete(
        path: '/api/v1/document-attachments/{attachmentId}',
        operationId: 'document_attachment_delete',
        summary: 'Xoá chứng từ đính kèm (xoá logic, không xoá file vật lý ngay)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Đã xoá chứng từ đính kèm (xoá logic)')]
    )]
    public function destroy(Request $request, string $attachmentId)
    {
        $row = $this->requireAttachment($attachmentId);
        $this->assertTypePermission($request, $row, 'delete');
        $this->assertWarehouseScope($request, $row);

        DB::table('document_attachments')->where('id', $attachmentId)->update([
            'status' => 'DELETED',
            'deleted_at' => now(),
            'deleted_by' => $this->actorId(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
        ]);

        AuditLogger::record(
            action: 'DOCUMENT_ATTACHMENT_DELETED',
            objectType: 'document_attachment',
            objectId: $attachmentId,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
            beforeData: ['status' => (string) $row->status],
            afterData: [
                'status' => 'DELETED',
                'document_type' => (string) $row->document_type,
                'document_id' => (string) $row->document_id,
            ],
        );

        return $this->respondSuccess([
            'id' => $attachmentId,
            'status' => 'DELETED',
            'stock_effect' => 'NONE',
        ], 'Đã xoá chứng từ đính kèm (xoá logic).');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function indexPayload(Request $request, string $documentType, string $documentId, array $extra)
    {
        $page = DB::table('document_attachments')
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', '!=', 'DELETED')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));
        $page->getCollection()->transform(fn (object $row): array => $this->payload($row));

        $hasEvidence = DocumentEvidenceGuard::hasActiveEvidence($documentType, $documentId);
        $requiresEvidence = (bool) ($extra['requires_evidence'] ?? false);

        $response = $this->respondPaginated($page);
        $body = $response->getData(true);
        $body['meta']['evidence_status'] = $hasEvidence ? 'PROVIDED' : ($requiresEvidence ? 'PENDING' : 'NOT_REQUIRED');
        $body['meta']['requires_evidence'] = $requiresEvidence;

        return $response->setData($body);
    }

    private function storeAttachment(Request $request, string $documentType, string $documentId)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB],
            'attachment_type' => ['nullable', 'in:'.implode(',', self::ATTACHMENT_TYPES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $validated['file'];
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            ApiError::abort(422, 'ATTACHMENT_TOO_LARGE', 'Chứng từ đính kèm vượt quá 15MB.');
        }

        $activeCount = (int) DB::table('document_attachments')
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'ACTIVE')
            ->count();
        if ($activeCount >= self::MAX_ACTIVE_ATTACHMENTS) {
            ApiError::abort(422, 'ATTACHMENT_LIMIT_EXCEEDED', 'Mỗi phiếu chỉ được giữ tối đa '.self::MAX_ACTIVE_ATTACHMENTS.' chứng từ đính kèm.');
        }

        ['extension' => $extension] = DocumentAttachmentScanner::assertContentType($file);
        $scan = DocumentAttachmentScanner::scanForMalware($file);

        $attachmentId = (string) Str::uuid();
        $storageDisk = (string) config('documents.disk', self::DISK);
        $storageKey = 'document-attachments/'.strtolower($documentType)."/{$documentId}/{$attachmentId}.{$extension}";
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        if ($scan['result'] === DocumentAttachmentScanner::RESULT_INFECTED) {
            $this->recordQuarantine($request, $documentType, $documentId, $attachmentId, $file, $checksum, $scan);

            ApiError::abort(422, 'ATTACHMENT_MALWARE_DETECTED', 'Chứng từ đính kèm không vượt qua kiểm tra mã độc.', [
                'attachment_id' => $attachmentId,
                'scan_result' => $scan['result'],
            ]);
        }

        // Disk để `throw => false`: ghi lỗi thì trả false chứ không ném — phải chặn trước
        // khi tạo dòng ACTIVE (xem CurrentBaselineWarrantyAttachmentController).
        $stored = Storage::disk($storageDisk)->putFileAs(
            'document-attachments/'.strtolower($documentType)."/{$documentId}",
            $file,
            "{$attachmentId}.{$extension}"
        );
        if ($stored === false) {
            ApiError::abort(503, 'STORAGE_UNAVAILABLE', 'Không ghi được chứng từ đính kèm lên kho lưu trữ. Thử lại sau ít phút.');
        }

        $now = now();
        $actorId = $this->actorId();
        DB::table('document_attachments')->insert([
            'id' => $attachmentId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'attachment_type' => $validated['attachment_type'] ?? 'SOURCE_DOCUMENT',
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'content_type' => mb_substr((string) $file->getMimeType(), 0, 100),
            'file_size_bytes' => $file->getSize(),
            'storage_disk' => $storageDisk,
            'storage_key' => $storageKey,
            'checksum' => $checksum,
            'status' => 'ACTIVE',
            'note' => $validated['note'] ?? null,
            'uploaded_at' => $now,
            'uploaded_by' => $actorId,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
            'version' => 1,
        ]);

        AuditLogger::record(
            action: 'DOCUMENT_ATTACHMENT_UPLOADED',
            objectType: 'document_attachment',
            objectId: $attachmentId,
            actorId: $actorId,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'document_type' => $documentType,
                'document_id' => $documentId,
                'attachment_type' => $validated['attachment_type'] ?? 'SOURCE_DOCUMENT',
                'file_size_bytes' => $file->getSize(),
                'checksum' => $checksum,
                'scan_result' => $scan['result'],
                'stock_effect' => 'NONE',
            ],
        );

        $row = DB::table('document_attachments')->where('id', $attachmentId)->first();

        return $this->respondCreated(
            $this->payload($row) + ['scan_result' => $scan['result']],
            'Đã tải lên chứng từ đính kèm; tồn kho chưa thay đổi (stock_effect=NONE).'
        );
    }

    private function recordQuarantine(Request $request, string $documentType, string $documentId, string $attachmentId, $file, ?string $checksum, array $scan): void
    {
        $now = now();
        $actorId = $this->actorId();

        DB::table('document_attachments')->insert([
            'id' => $attachmentId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'attachment_type' => 'OTHER',
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            // Không có nội dung trên disk; giữ khoá placeholder để cột NOT NULL/UNIQUE
            // vẫn hợp lệ (cùng khuôn mẫu CurrentBaselineWarrantyAttachmentController).
            'storage_key' => 'quarantined/'.$attachmentId,
            'content_type' => mb_substr((string) $file->getMimeType(), 0, 100),
            'file_size_bytes' => $file->getSize(),
            'checksum' => $checksum,
            'status' => 'QUARANTINED',
            'uploaded_at' => $now,
            'uploaded_by' => $actorId,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
            'version' => 1,
        ]);

        AuditLogger::record(
            action: 'DOCUMENT_ATTACHMENT_QUARANTINED',
            objectType: 'document_attachment',
            objectId: $attachmentId,
            actorId: $actorId,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'document_type' => $documentType,
                'document_id' => $documentId,
                'status' => 'QUARANTINED',
                'scan_result' => $scan['result'],
                'scan_detail' => $scan['detail'],
                'checksum' => $checksum,
                'stock_effect' => 'NONE',
            ],
        );
    }

    private function requireStockDocument(string $id, string $docType, string $message): object
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', $docType)->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', $message);
        }

        return $document;
    }

    private function requireReversal(string $id): object
    {
        $row = DocumentEvidenceGuard::reversalRow($id);
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ hoàn trả/điều chỉnh.');
        }

        return $row;
    }

    private function requireAttachment(string $attachmentId): object
    {
        $row = DB::table('document_attachments')->where('id', $attachmentId)->first();
        if ($row === null || in_array((string) $row->status, ['DELETED', 'QUARANTINED'], true)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ đính kèm.');
        }

        return $row;
    }

    /**
     * WWHN-SEC-09: khớp quyền của actor với đúng document_type của attachment.
     * Middleware route chỉ bảo đảm "có MỘT quyền bất kỳ trong hợp của 3 luồng";
     * tại đây mới biết attachment thuộc luồng nào để đòi đúng quyền luồng đó.
     * document_type lạ → fail-closed.
     */
    private function assertTypePermission(Request $request, object $attachment, string $operation): void
    {
        $user = $request->user('api');
        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.');
        }

        $allowed = self::TYPE_OPERATION_PERMISSIONS[(string) $attachment->document_type][$operation] ?? [];

        foreach ($allowed as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }

        DenyAuditRecorder::permissionDenied($request, implode('|', $allowed), $this->actorId());
        ApiError::abort(403, 'ACCESS_DENIED', 'Bạn không có quyền thao tác trên chứng từ đính kèm của loại phiếu này.');
    }

    /**
     * Route download/destroy không có {id} phiếu để middleware
     * Enforce{Inbound,Outbound,Reversal}WarehouseScope tự bắt — tự giải warehouse_id
     * theo document_type của attachment rồi so với scope actor, cùng logic các
     * middleware đó dùng.
     */
    private function assertWarehouseScope(Request $request, object $attachment): void
    {
        $user = $request->user('api');
        if (! is_object($user) || ! method_exists($user, 'warehouseScopeIds')) {
            return;
        }
        $warehouseIds = $user->warehouseScopeIds();
        if ($warehouseIds === null) {
            return;
        }

        $documentType = (string) $attachment->document_type;
        $documentId = (string) $attachment->document_id;
        $warehouseId = match ($documentType) {
            DocumentEvidenceGuard::TYPE_INBOUND => DB::table('stock_documents')->where('id', $documentId)->where('doc_type', 'receipt')->value('dst_warehouse_id'),
            DocumentEvidenceGuard::TYPE_OUTBOUND => DB::table('stock_documents')->where('id', $documentId)->where('doc_type', 'issue')->value('src_warehouse_id'),
            DocumentEvidenceGuard::TYPE_REVERSAL => (DocumentEvidenceGuard::reversalRow($documentId)->warehouse_id ?? null),
            default => null,
        };

        if ($warehouseId !== null && ! in_array((string) $warehouseId, $warehouseIds, true)) {
            ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Chứng từ đính kèm nằm ngoài warehouse data scope hiện hành.');
        }
    }

    /** Metadata an toàn: không expose storage path nội bộ ra API. */
    private function payload(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'document_type' => (string) $row->document_type,
            'document_id' => (string) $row->document_id,
            'attachment_type' => (string) ($row->attachment_type ?? 'OTHER'),
            'file_name' => (string) $row->file_name,
            'content_type' => (string) ($row->content_type ?? ''),
            'file_size_bytes' => (int) ($row->file_size_bytes ?? 0),
            'checksum' => $row->checksum ?? null,
            'note' => $row->note ?? null,
            'status' => (string) ($row->status ?? 'ACTIVE'),
            'uploaded_at' => $row->uploaded_at ?? $row->created_at,
            'uploaded_by' => $row->uploaded_by ?? $row->created_by,
            'stock_effect' => 'NONE',
        ];
    }
}
