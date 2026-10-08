<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P09WarrantyPage;
use Modules\Warehouse\Support\P09WarrantyScope as Scope;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Symfony\Component\HttpFoundation\Response;

/** Guard only Mini App warranty attachments; do not change Web/P19 routes. */
final class P09WarrantyAttachmentGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $read = in_array($request->method(), ['GET', 'HEAD'], true);
        $scope = Scope::resolve($request, ! $read);
        if ($read && ! $scope['documents']) {
            ApiError::abort(403, 'DOCUMENTS_READ_FORBIDDEN', 'Cần quyền documents.read để đọc tệp đính kèm.');
        }
        $attachmentId = $request->route('attachmentId');
        $caseId = $request->route('id');
        if ($attachmentId !== null) {
            if ($read && ! $scope['pii']) {
                ApiError::abort(403, 'WARRANTY_PII_READ_FORBIDDEN', 'Cần quyền warranty.pii.view để tải nội dung tệp có thể chứa PII.');
            }
            if (! Str::isUuid($attachmentId)) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp.');
            }
            $q = DB::table('warranty_attachments as a')->where('a.id', $attachmentId);
            Scope::isolate($q, 'warranty_attachments', 'a', $scope);
            $row = $q->first();
            if ($row === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp.');
            }
            $caseId = $row->warranty_case_id;
            if ($read && (! $scope['pii'] || $row->status !== 'ACTIVE')) {
                ApiError::abort(! $scope['pii'] ? 403 : 404, ! $scope['pii'] ? 'WARRANTY_PII_READ_FORBIDDEN' : 'RESOURCE_NOT_FOUND', 'Tệp không khả dụng trong quyền truy cập hiện tại.');
            }
        }
        Scope::requireCase($scope, (string) $caseId);
        if (! $read) {
            WarrantyWarehouseScope::assertCase($request, $caseId, true);
        }
        if ($read && $attachmentId === null) {
            if (array_diff(array_keys($request->query()), ['warehouse_id', 'page', 'per_page', 'cursor']) !== []) {
                ApiError::abort(422, 'WARRANTY_UNKNOWN_FILTER', 'Bộ lọc tệp đính kèm ngoài contract.');
            }
            return P06ReadSnapshot::run(function (array $snapshot) use ($request, $scope, $caseId): JsonResponse {
                Scope::requireCase($scope, $caseId);
                $q = DB::table('warranty_attachments as a')->where('a.warranty_case_id', $caseId)->where(fn ($status) => $status->whereNull('a.status')->orWhere('a.status', '!=', 'DELETED'));
                Scope::isolate($q, 'warranty_attachments', 'a', $scope);
                [$ids, $page] = P09WarrantyPage::read($request, clone $q, $scope, 'a.id', 'a.created_at');
                $rows = $q->whereIn('a.id', $ids)->get()->keyBy('id');
                $data = array_map(function (string $id) use ($rows, $scope): array {
                    $r = $rows[$id];
                    $download = $scope['pii'] && $r->status === 'ACTIVE';
                    return ['id' => $id, 'attachment_id' => $id, 'warranty_case_id' => $r->warranty_case_id,
                        'file_name' => $scope['pii'] ? $r->file_name : null, 'file_name_redacted' => ! $scope['pii'],
                        'content_type' => $r->content_type ?? $r->mime_type ?? null,
                        'file_size_bytes' => isset($r->file_size_bytes) ? (int) $r->file_size_bytes : (isset($r->file_size) ? (int) $r->file_size : null),
                        'checksum' => $r->checksum ?? null, 'status' => $r->status,
                        'attachment_type' => $r->attachment_type ?? null, 'media_kind' => $r->media_kind ?? null,
                        'uploaded_at' => $r->uploaded_at ?? $r->created_at, 'uploaded_by' => $r->uploaded_by ?? $r->created_by ?? null,
                        'can_download' => $download, 'download_url' => $download ? '/api/v1/mini-app/warranty-attachments/'.$id.'/download' : null];
                }, $ids);
                return response()->json(['success' => true, 'data' => $data,
                    'meta' => Scope::metadata($scope) + $page + ['snapshot' => $snapshot]])->header('Cache-Control', 'private, no-store');
            });
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
