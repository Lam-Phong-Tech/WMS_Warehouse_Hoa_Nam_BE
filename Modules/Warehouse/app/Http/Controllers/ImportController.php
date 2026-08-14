<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ImportService $importService) {}

    #[OA\Get(
        path: '/api/v1/skus/import-template',
        operationId: 'sku_import_template',
        summary: 'Tải template Excel SKU chuẩn WWHN-172',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        responses: [
            new OA\Response(response: 200, description: 'XLSX template'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
        ]
    )]
    public function downloadTemplate(): BinaryFileResponse
    {
        $path = sys_get_temp_dir().'/WWHN-172-SKU-import-template-'.Str::uuid().'.xlsx';
        $this->importService->createTemplate($path);

        return response()
            ->download($path, 'WWHN-172-SKU-import-template-v1.1.0.xlsx')
            ->deleteFileAfterSend(true);
    }

    #[OA\Post(
        path: '/api/v1/skus/import-excel',
        operationId: 'sku_import_excel',
        summary: 'Import SKUs từ Excel theo cơ chế all-or-nothing, hỗ trợ dry-run',
        description: 'Validate toàn bộ file trước khi ghi. Có bất kỳ lỗi nào thì committed=false và không tạo/cập nhật SKU. '
            .'Khi dry_run=true (WWHN-224), toàn bộ rule nghiệp vụ vẫn được áp dụng như import thật nhưng tuyệt đối không ghi SKU/audit nghiệp vụ; '
            .'kết quả kiểm tra thử luôn được lưu vào Import báo cáo (import_reports) và trả về import_report_id để tra cứu lại.',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'update_existing', type: 'boolean', default: false),
                        new OA\Property(property: 'dry_run', type: 'boolean', default: false, description: 'true = chỉ kiểm tra thử, không ghi dữ liệu (WWHN-224)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Import committed atomically, hoặc dry-run hợp lệ (committed=false, dry_run=true)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
            new OA\Response(response: 409, description: 'Concurrent unique conflict; no partial write'),
            new OA\Response(response: 422, description: 'File/row validation failed; no business side effect'),
        ]
    )]
    public function importExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'update_existing' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $path = $file->store('temp/imports', 'local');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Không lưu được file import tạm thời.');
        }
        $localDisk = Storage::disk('local');
        $fullPath = $localDisk->path($path);
        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $actorId = ($id = auth('api')->id()) === null ? null : (string) $id;
        $requestId = is_string($request->attributes->get('request_id'))
            ? $request->attributes->get('request_id')
            : null;

        try {
            $result = $this->importService->import(
                $fullPath,
                (bool) ($validated['update_existing'] ?? false),
                $actorId,
                $requestId,
                $file->getClientOriginalName(),
                $dryRun,
            );

            $result['import_report_id'] = $this->persistReport($result, $actorId, $requestId, $file->getClientOriginalName())?->import_report_id;
            $hasRowErrors = $result['row_errors'] !== [];

            if ($hasRowErrors) {
                return $this->respondError(
                    $dryRun
                        ? 'Kiểm tra thử import hoàn tất; phát hiện lỗi, không có SKU nào được tạo hoặc cập nhật.'
                        : 'Import bị từ chối; không có SKU nào được tạo hoặc cập nhật.',
                    $result['row_errors'],
                    422,
                    $dryRun ? 'SKU_IMPORT_DRY_RUN_REJECTED' : 'SKU_IMPORT_VALIDATION_FAILED',
                    ['data' => $result],
                );
            }

            return $this->respondSuccess(
                $result,
                $dryRun
                    ? 'Kiểm tra thử import hoàn tất, không có dữ liệu nào được ghi.'
                    : 'Import SKU đã commit nguyên tử.',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respondError(
                $e->getMessage(),
                [],
                422,
                'SKU_IMPORT_FILE_INVALID',
                ['data' => ['committed' => false, 'partial_write' => false]],
            );
        } finally {
            $localDisk->delete($path);
        }
    }

    /**
     * Import báo cáo (WWHN-224) là metadata truy vết, không phải nguồn sự thật nghiệp vụ.
     * Nếu import thật đã commit SKU thành công nhưng riêng bước ghi report lỗi, request
     * vẫn phải trả kết quả thành công — nếu không client có thể tưởng import thất bại và
     * gửi lại, gây DUPLICATE_CODE giả trên dữ liệu đã thực sự được tạo đúng.
     *
     * @param  array<string, mixed>  $result
     */
    private function persistReport(array $result, ?string $actorId, ?string $requestId, ?string $fileName): ?ImportReport
    {
        $dryRun = (bool) ($result['dry_run'] ?? false);
        $hasErrors = ($result['row_errors'] ?? []) !== [];
        $status = match (true) {
            $hasErrors => 'REJECTED',
            $dryRun => 'VALID',
            default => 'COMMITTED',
        };

        try {
            return ImportReport::query()->create([
                'import_type' => 'SKU',
                'mode' => $dryRun ? 'DRY_RUN' : 'COMMIT',
                'status' => $status,
                'file_name' => $fileName,
                'file_sha256' => $result['file_sha256'],
                'template_version' => $result['template_version'] ?? null,
                'total_rows' => $result['total_rows'] ?? 0,
                'valid_rows' => $result['valid_rows'] ?? 0,
                'error_rows' => $result['error_rows'] ?? 0,
                'inserted_rows' => $dryRun ? ($result['would_insert_rows'] ?? 0) : ($result['inserted_rows'] ?? 0),
                'updated_rows' => $dryRun ? ($result['would_update_rows'] ?? 0) : ($result['updated_rows'] ?? 0),
                'row_errors' => $result['row_errors'] ?? [],
                'actor_id' => $actorId,
                'request_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            Log::error('IMPORT_REPORT_WRITE_FAILED', [
                'request_id' => $requestId,
                'file_sha256' => $result['file_sha256'] ?? null,
                'mode' => $dryRun ? 'DRY_RUN' : 'COMMIT',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
