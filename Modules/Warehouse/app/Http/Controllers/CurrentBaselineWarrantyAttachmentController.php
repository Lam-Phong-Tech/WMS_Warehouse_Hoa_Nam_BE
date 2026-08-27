<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\WarrantyAttachmentScanner;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use OpenApi\Attributes as OA;

/**
 * ERD §47 warranty_attachments: ảnh/tệp private của warranty case.
 * Upload/list/download/delete đều stock-neutral; file lưu private disk,
 * chỉ trả metadata (không expose storage path nội bộ).
 */
class CurrentBaselineWarrantyAttachmentController extends Controller
{
    use ApiResponse;
    use ResolvesActorOrNull { actorIdOrNull as actorId; }

    private const DISK = 'local';

    private const ATTACHMENT_TYPES = ['INTAKE', 'DIAGNOSTIC', 'RETURN', 'OTHER'];

    /** Annex D SCR-WAR-01: images <= 10 files, <= 10MB/file. */
    private const MAX_ACTIVE_ATTACHMENTS = 10;

    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /** WAR-02: video tách khỏi 10 ảnh, tối đa 1 video ACTIVE mỗi case, <=300MB/file, <=5 phút. */
    private const MAX_ACTIVE_VIDEOS = 1;

    private const MAX_VIDEO_BYTES = 300 * 1024 * 1024;

    /** Ceiling chung cho validator Laravel; giới hạn thật theo loại được áp dụng thủ công bên dưới. */
    private const MAX_UPLOAD_KB = 307200;

    #[OA\Get(
        path: '/api/v1/warranty-cases/{id}/attachments',
        operationId: 'warranty_attachment_list',
        summary: 'Danh sách tệp đính kèm của ca bảo hành',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách tệp đính kèm'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.view hoặc warranty.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy ca bảo hành'),
        ]
    )]
    public function index(Request $request, string $id)
    {
        $this->requireCase($id);

        $page = DB::table('warranty_attachments')
            ->where('warranty_case_id', $id)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'DELETED');
            })
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));
        $page->getCollection()->transform(fn (object $row): array => $this->payload($row));

        return $this->respondPaginated($page);
    }

    #[OA\Post(
        path: '/api/v1/warranty-cases/{id}/attachments',
        operationId: 'uploadWarrantyAttachment',
        summary: 'Tải lên tệp đính kèm cho hồ sơ bảo hành (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Ảnh jpg/jpeg/png/webp <=10MB (Annex D SCR-WAR-01, tối đa 10 tệp ACTIVE mỗi case) HOẶC video mp4/mov/webm <=300MB và <=5 phút (WAR-02, tối đa 1 video ACTIVE mỗi case, đếm riêng khỏi ảnh).'),
                        new OA\Property(property: 'attachment_type', type: 'string', enum: ['INTAKE', 'DIAGNOSTIC', 'RETURN', 'OTHER'], nullable: true, description: 'Mặc định INTAKE.', example: 'INTAKE'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tệp đính kèm đã lưu (metadata + scan_result); stock_effect=NONE.'),
        ]
    )]
    public function store(Request $request, string $id)
    {
        $case = $this->requireCase($id);
        if ((string) $case->status === 'CANCELLED') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Không thể đính kèm tệp vào case đã hủy.');
        }

        $validated = $request->validate([
            // Ceiling chung; giới hạn thật (10MB ảnh / 300MB video) áp dụng thủ công sau
            // khi biết media_kind — 'mimes:' bỏ, vì rule đó không chấp nhận video.
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB],
            'attachment_type' => ['nullable', 'in:'.implode(',', self::ATTACHMENT_TYPES)],
        ]);

        $file = $validated['file'];
        $mediaKind = WarrantyAttachmentScanner::detectMediaKind($file);
        if ($mediaKind === null) {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_INVALID', 'Tệp đính kèm chỉ nhận ảnh JPG/PNG/WEBP hoặc video MP4/MOV/WEBM.', [
                'allowed_image_mime' => array_keys(WarrantyAttachmentScanner::ALLOWED_MIME_EXTENSIONS),
                'allowed_video_mime' => array_keys(WarrantyAttachmentScanner::ALLOWED_VIDEO_MIME_EXTENSIONS),
            ]);
        }

        $durationSeconds = $mediaKind === 'VIDEO'
            ? $this->validateVideo($id, $file)
            : $this->validateImage($id, $file);

        // §13.8 "malware/content-type check": MIME thật phải khớp allowlist + extension.
        ['extension' => $extension] = $mediaKind === 'VIDEO'
            ? WarrantyAttachmentScanner::assertVideoContentType($file)
            : WarrantyAttachmentScanner::assertContentType($file);
        $scan = WarrantyAttachmentScanner::scanForMalware($file);

        $attachmentId = (string) Str::uuid();
        $storageDisk = $mediaKind === 'VIDEO' ? (string) config('warranty.video_disk', 'local') : self::DISK;
        $storageKey = "warranty-attachments/{$id}/{$attachmentId}.{$extension}";
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        // File nhiễm/không quét được vẫn phải để lại vết QUARANTINED (ERD §47 status) và
        // KHÔNG được ghi ra private storage.
        if ($scan['result'] === WarrantyAttachmentScanner::RESULT_INFECTED) {
            $this->recordQuarantine($request, $id, $attachmentId, $file, $checksum, $scan);

            ApiError::abort(422, 'ATTACHMENT_MALWARE_DETECTED', 'Tệp đính kèm không vượt qua kiểm tra mã độc.', [
                'attachment_id' => $attachmentId,
                'scan_result' => $scan['result'],
            ]);
        }

        Storage::disk($storageDisk)->putFileAs("warranty-attachments/{$id}", $file, "{$attachmentId}.{$extension}");

        $now = now();
        $actorId = $this->actorId();
        DB::table('warranty_attachments')->insert([
            'id' => $attachmentId,
            'warranty_case_id' => $id,
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'storage_path' => $storageKey,
            'storage_key' => $storageKey,
            'storage_disk' => $storageDisk,
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 120),
            'content_type' => mb_substr((string) $file->getMimeType(), 0, 100),
            'file_size' => $file->getSize(),
            'file_size_bytes' => $file->getSize(),
            'checksum' => $checksum,
            'attachment_type' => $validated['attachment_type'] ?? 'INTAKE',
            'media_kind' => $mediaKind,
            'duration_seconds' => $durationSeconds,
            'status' => 'ACTIVE',
            'uploaded_at' => $now,
            'uploaded_by' => $actorId,
            'created_at' => $now,
            'created_by' => $actorId,
            'updated_at' => $now,
            'updated_by' => $actorId,
            'version' => 1,
        ]);

        AuditLogger::record(
            action: 'WARRANTY_ATTACHMENT_UPLOADED',
            objectType: 'warranty_attachment',
            objectId: $attachmentId,
            actorId: $actorId,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'warranty_case_id' => $id,
                'attachment_type' => $validated['attachment_type'] ?? 'INTAKE',
                'media_kind' => $mediaKind,
                'file_size_bytes' => $file->getSize(),
                'checksum' => $checksum,
                'scan_result' => $scan['result'],
                'stock_effect' => 'NONE',
            ],
        );

        $row = DB::table('warranty_attachments')->where('id', $attachmentId)->first();

        return $this->respondCreated(
            $this->payload($row) + ['scan_result' => $scan['result']],
            'Đã tải lên tệp đính kèm bảo hành; tồn kho chưa thay đổi (stock_effect=NONE).'
        );
    }

    /** Annex D SCR-WAR-01: <=10 ảnh ACTIVE mỗi case (đếm riêng khỏi video), <=10MB/file. */
    private function validateImage(string $caseId, $file): ?int
    {
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            ApiError::abort(422, 'ATTACHMENT_TOO_LARGE', 'Ảnh đính kèm vượt quá 10MB.');
        }

        $activeCount = (int) DB::table('warranty_attachments')
            ->where('warranty_case_id', $caseId)
            ->where('status', 'ACTIVE')
            ->where(function ($query): void {
                $query->whereNull('media_kind')->orWhere('media_kind', '!=', 'VIDEO');
            })
            ->count();
        if ($activeCount >= self::MAX_ACTIVE_ATTACHMENTS) {
            ApiError::abort(422, 'ATTACHMENT_LIMIT_EXCEEDED', 'Mỗi case bảo hành chỉ được giữ tối đa '.self::MAX_ACTIVE_ATTACHMENTS.' ảnh đính kèm.');
        }

        return null;
    }

    /** WAR-02: <=1 video ACTIVE mỗi case, <=300MB/file, <=5 phút — đo bằng getID3. */
    private function validateVideo(string $caseId, $file): int
    {
        if ($file->getSize() > self::MAX_VIDEO_BYTES) {
            ApiError::abort(422, 'ATTACHMENT_TOO_LARGE', 'Video đính kèm vượt quá 300MB.');
        }

        $activeVideoCount = (int) DB::table('warranty_attachments')
            ->where('warranty_case_id', $caseId)
            ->where('status', 'ACTIVE')
            ->where('media_kind', 'VIDEO')
            ->count();
        if ($activeVideoCount >= self::MAX_ACTIVE_VIDEOS) {
            ApiError::abort(422, 'ATTACHMENT_LIMIT_EXCEEDED', 'Mỗi case bảo hành chỉ được giữ tối đa '.self::MAX_ACTIVE_VIDEOS.' video đính kèm.');
        }

        $durationSeconds = WarrantyAttachmentScanner::probeVideoDurationSeconds($file);
        if ($durationSeconds === null) {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_INVALID', 'Không đọc được thời lượng video; tệp có thể hỏng hoặc không đúng định dạng.');
        }
        if ($durationSeconds > WarrantyAttachmentScanner::MAX_VIDEO_DURATION_SECONDS) {
            ApiError::abort(422, 'ATTACHMENT_VIDEO_TOO_LONG', 'Video đính kèm vượt quá 5 phút.', [
                'duration_seconds' => $durationSeconds,
                'max_duration_seconds' => WarrantyAttachmentScanner::MAX_VIDEO_DURATION_SECONDS,
            ]);
        }

        return $durationSeconds;
    }

    /**
     * ERD §47 status QUARANTINED: giữ metadata của tệp bị chặn để audit/đối soát mà
     * không lưu nội dung ra storage.
     *
     * @param  array{result: string, detail: string|null}  $scan
     */
    private function recordQuarantine(Request $request, string $caseId, string $attachmentId, $file, ?string $checksum, array $scan): void
    {
        $now = now();
        $actorId = $this->actorId();

        DB::table('warranty_attachments')->insert([
            'id' => $attachmentId,
            'warranty_case_id' => $caseId,
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            // Không có nội dung trên disk; giữ khóa placeholder để cột NOT NULL/UNIQUE
            // vẫn hợp lệ và mọi truy vấn theo storage_key không trùng file thật.
            'storage_path' => 'quarantined/'.$attachmentId,
            'storage_key' => 'quarantined/'.$attachmentId,
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 120),
            'content_type' => mb_substr((string) $file->getMimeType(), 0, 100),
            'file_size' => $file->getSize(),
            'file_size_bytes' => $file->getSize(),
            'checksum' => $checksum,
            'attachment_type' => 'OTHER',
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
            action: 'WARRANTY_ATTACHMENT_QUARANTINED',
            objectType: 'warranty_attachment',
            objectId: $attachmentId,
            actorId: $actorId,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'warranty_case_id' => $caseId,
                'status' => 'QUARANTINED',
                'scan_result' => $scan['result'],
                'scan_detail' => $scan['detail'],
                'checksum' => $checksum,
                'stock_effect' => 'NONE',
            ],
        );
    }

    #[OA\Get(
        path: '/api/v1/warranty-attachments/{attachmentId}/download',
        operationId: 'warranty_attachment_download',
        summary: 'Tải tệp đính kèm bảo hành',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Nội dung tệp (binary)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.view hoặc warranty.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy tệp đính kèm'),
        ]
    )]
    public function download(Request $request, string $attachmentId)
    {
        $row = $this->requireAttachment($attachmentId);
        $path = (string) ($row->storage_key ?? $row->storage_path);
        $disk = (string) ($row->storage_disk ?? self::DISK);
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm trên storage.');
        }

        AuditLogger::record(
            action: 'WARRANTY_ATTACHMENT_DOWNLOADED',
            objectType: 'warranty_attachment',
            objectId: $attachmentId,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
        );

        return Storage::disk($disk)->download($path, (string) $row->file_name);
    }

    #[OA\Delete(
        path: '/api/v1/warranty-attachments/{attachmentId}',
        operationId: 'warranty_attachment_delete',
        summary: 'Xoá tệp đính kèm bảo hành (logic, không xoá file vật lý ngay)',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Đã xoá tệp đính kèm (xoá logic)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy tệp đính kèm'),
        ]
    )]
    public function destroy(Request $request, string $attachmentId)
    {
        $row = $this->requireAttachment($attachmentId);

        DB::table('warranty_attachments')->where('id', $attachmentId)->update([
            'status' => 'DELETED',
            'deleted_at' => now(),
            'deleted_by' => $this->actorId(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
        ]);

        AuditLogger::record(
            action: 'WARRANTY_ATTACHMENT_DELETED',
            objectType: 'warranty_attachment',
            objectId: $attachmentId,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
            beforeData: ['status' => (string) ($row->status ?? 'ACTIVE')],
            afterData: ['status' => 'DELETED', 'warranty_case_id' => (string) $row->warranty_case_id],
        );

        return $this->respondSuccess([
            'id' => $attachmentId,
            'status' => 'DELETED',
            'stock_effect' => 'NONE',
        ], 'Đã xóa tệp đính kèm bảo hành (xóa logic).');
    }

    private function requireCase(string $id): object
    {
        $case = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        if ($case === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Warranty case not found.');
        }

        return $case;
    }

    private function requireAttachment(string $attachmentId): object
    {
        $row = DB::table('warranty_attachments')->where('id', $attachmentId)->first();
        // QUARANTINED chỉ tồn tại như bằng chứng audit — không có nội dung để tải về.
        if ($row === null || in_array((string) ($row->status ?? 'ACTIVE'), ['DELETED', 'QUARANTINED'], true)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm.');
        }

        return $row;
    }

    /** Metadata an toàn: không expose storage path nội bộ ra API. */
    private function payload(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'warranty_case_id' => (string) $row->warranty_case_id,
            'file_name' => (string) $row->file_name,
            'content_type' => (string) ($row->content_type ?? $row->mime_type ?? ''),
            'file_size_bytes' => (int) ($row->file_size_bytes ?? $row->file_size ?? 0),
            'checksum' => $row->checksum ?? null,
            'attachment_type' => (string) ($row->attachment_type ?? 'OTHER'),
            'media_kind' => (string) ($row->media_kind ?? 'IMAGE'),
            'duration_seconds' => isset($row->duration_seconds) ? (int) $row->duration_seconds : null,
            'status' => (string) ($row->status ?? 'ACTIVE'),
            'uploaded_at' => $row->uploaded_at ?? $row->created_at,
            'uploaded_by' => $row->uploaded_by ?? $row->created_by,
            'stock_effect' => 'NONE',
        ];
    }

}
