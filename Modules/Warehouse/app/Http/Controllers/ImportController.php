<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Exceptions\ImportRowLimitExceededException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;
use Modules\Warehouse\Services\ImportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ImportService $importService,
        private readonly ImportErrorFileService $errorFileService,
    ) {}

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
            .'kết quả kiểm tra thử luôn được lưu vào Import báo cáo (import_reports) và trả về import_report_id để tra cứu lại. '
            .'WWHN-330: nếu file có sha256 trùng với 1 lần COMMIT gần đây (24h) của cùng actor, response trả duplicate=true '
            .'và import_report_id của lần import gốc, không ghi SKU/tạo báo cáo mới. Khi có row lỗi, dùng '
            .'GET /api/v1/skus/import-reports/{id}/error-file để tải file CSV chi tiết lỗi (row/field/error/value).',
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

            // WWHN-330 P1-03: file trùng sha256 với 1 lần COMMIT gần đây của cùng actor —
            // trả lại Import báo cáo cũ, không tạo báo cáo mới, không ghi SKU lần 2.
            if ($result['duplicate'] ?? false) {
                $result['import_report_id'] = $result['duplicate_of_import_report_id'];

                return $this->respondSuccess(
                    $result,
                    'File này đã được import trước đó (duplicate/already processed); trả lại kết quả của lần import gốc.',
                );
            }

            $report = $this->persistReport($result, $actorId, $requestId, $file->getClientOriginalName());
            $result['import_report_id'] = $report?->import_report_id;
            $hasRowErrors = $result['row_errors'] !== [];

            if ($hasRowErrors) {
                // WWHN-330 P1-01: sinh sẵn + lưu file lỗi CSV (đã sanitize) ngay khi có row lỗi,
                // để GET .../import-reports/{id}/error-file phục vụ file thật thay vì phải tính
                // lại; sinh file lỗi không được làm hỏng response import chính nên chỉ log khi fail.
                if ($report !== null) {
                    $this->attachErrorFile($report, $result['row_errors']);
                    // Dùng đúng accessor server-side (path còn + chưa hết hạn) mà
                    // ImportReport phơi ra cho GET /skus/import-reports/{id}, để response
                    // import và response báo cáo không bao giờ nói khác nhau về việc
                    // "có file lỗi để tải hay không".
                    $result['error_file_available'] = $report->error_file_available;
                    $result['error_file_expires_at'] = $report->error_file_expires_at?->toIso8601String();
                } else {
                    $result['error_file_available'] = false;
                    $result['error_file_expires_at'] = null;
                }

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
        } catch (ImportRowLimitExceededException $e) {
            // WWHN-330 P1-02: error code chuẩn riêng cho vượt hạn mức số dòng, không lẫn
            // với các lỗi file/parse khác, không lộ stack trace.
            return $this->respondError(
                $e->getMessage(),
                [],
                422,
                'SKU_IMPORT_ROW_LIMIT_EXCEEDED',
                ['data' => [
                    'committed' => false,
                    'partial_write' => false,
                    'max_rows' => $e->maxRows,
                    'actual_rows' => $e->actualRows,
                ]],
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

    /**
     * WWHN-330 P1-01: sinh file lỗi CSV (đã sanitize) và gắn path + expiry vào Import
     * báo cáo. Lỗi sinh file không được làm hỏng response import chính — chỉ log, vì
     * file lỗi là tiện ích tra cứu thêm, không phải nguồn sự thật nghiệp vụ.
     *
     * @param  array<int, array<string, mixed>>  $rowErrors
     */
    private function attachErrorFile(ImportReport $report, array $rowErrors): void
    {
        try {
            $generated = $this->errorFileService->generate($report->import_report_id, $rowErrors);
            if ($generated === null) {
                return;
            }

            $report->forceFill([
                'error_file_path' => $generated['path'],
                'error_file_expires_at' => $generated['expires_at'],
            ])->save();
        } catch (Throwable $e) {
            Log::error('IMPORT_ERROR_FILE_GENERATION_FAILED', [
                'import_report_id' => $report->import_report_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
