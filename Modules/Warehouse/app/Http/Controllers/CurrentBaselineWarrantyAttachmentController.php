<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * ERD §47 warranty_attachments: ảnh/tệp private của warranty case.
 * Upload/list/download/delete đều stock-neutral; file lưu private disk,
 * chỉ trả metadata (không expose storage path nội bộ).
 */
class CurrentBaselineWarrantyAttachmentController extends Controller
{
    use ApiResponse;

    private const DISK = 'local';

    private const ATTACHMENT_TYPES = ['INTAKE', 'DIAGNOSTIC', 'RETURN', 'OTHER'];

    /** Annex D SCR-WAR-01: images <= 10 files, <= 10MB/file. */
    private const MAX_ACTIVE_ATTACHMENTS = 10;

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

    public function store(Request $request, string $id)
    {
        $case = $this->requireCase($id);
        if ((string) $case->status === 'CANCELLED') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Không thể đính kèm tệp vào case đã hủy.');
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
            'attachment_type' => ['nullable', 'in:'.implode(',', self::ATTACHMENT_TYPES)],
        ]);

        // Annex D SCR-WAR-01: tối đa 10 file đính kèm mỗi case.
        $activeCount = (int) DB::table('warranty_attachments')
            ->where('warranty_case_id', $id)
            ->where('status', 'ACTIVE')
            ->count();
        if ($activeCount >= self::MAX_ACTIVE_ATTACHMENTS) {
            ApiError::abort(422, 'ATTACHMENT_LIMIT_EXCEEDED', 'Mỗi case bảo hành chỉ được giữ tối đa '.self::MAX_ACTIVE_ATTACHMENTS.' tệp đính kèm.');
        }

        $file = $validated['file'];
        $attachmentId = (string) Str::uuid();
        $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'bin';
        $storageKey = "warranty-attachments/{$id}/{$attachmentId}.{$extension}";
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        Storage::disk(self::DISK)->putFileAs("warranty-attachments/{$id}", $file, "{$attachmentId}.{$extension}");

        $now = now();
        $actorId = $this->actorId();
        DB::table('warranty_attachments')->insert([
            'id' => $attachmentId,
            'warranty_case_id' => $id,
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'storage_path' => $storageKey,
            'storage_key' => $storageKey,
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 120),
            'content_type' => mb_substr((string) $file->getMimeType(), 0, 100),
            'file_size' => $file->getSize(),
            'file_size_bytes' => $file->getSize(),
            'checksum' => $checksum,
            'attachment_type' => $validated['attachment_type'] ?? 'INTAKE',
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
                'file_size_bytes' => $file->getSize(),
                'checksum' => $checksum,
                'stock_effect' => 'NONE',
            ],
        );

        $row = DB::table('warranty_attachments')->where('id', $attachmentId)->first();

        return $this->respondCreated($this->payload($row), 'Warranty attachment uploaded; stock_effect=NONE.');
    }

    public function download(Request $request, string $attachmentId)
    {
        $row = $this->requireAttachment($attachmentId);
        $path = (string) ($row->storage_key ?? $row->storage_path);
        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm trên storage.');
        }

        AuditLogger::record(
            action: 'WARRANTY_ATTACHMENT_DOWNLOADED',
            objectType: 'warranty_attachment',
            objectId: $attachmentId,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
        );

        return Storage::disk(self::DISK)->download($path, (string) $row->file_name);
    }

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
        ], 'Warranty attachment deleted (logical).');
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
        if ($row === null || ($row->status ?? 'ACTIVE') === 'DELETED') {
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
            'status' => (string) ($row->status ?? 'ACTIVE'),
            'uploaded_at' => $row->uploaded_at ?? $row->created_at,
            'uploaded_by' => $row->uploaded_by ?? $row->created_by,
            'stock_effect' => 'NONE',
        ];
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }
}
