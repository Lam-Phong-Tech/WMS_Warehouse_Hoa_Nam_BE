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
use Modules\Warehouse\Support\SkuImportContract;
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
            .'WWHN-331 FE331-P1-03R3: mọi response đều mang khối `data.constraints` (max_rows, max_file_size_kb, '
            .'allowed_extensions, error_file_expiry_hours, idempotency_window_hours, idempotency_identity, decision_ref) — '
            .'đây là hạn mức máy chủ ĐANG áp dụng, đọc từ hợp đồng đã duyệt `warehouse.sku_import` (DEC-IMP-001). Client '
            .'hiển thị theo khối này, không hard-code lại các con số đó. '
            .'WWHN-331 FE331-P1-02R3 (idempotent replay): một lần import COMMIT được định danh bằng '
            .'`file_sha256 + actor_id + options_fingerprint`, trong đó options_fingerprint = sha256 của chuỗi chuẩn hoá '
            .'`update_existing=0|1`. Nộp lại CÙNG file, CÙNG actor, CÙNG update_existing trong cửa sổ idempotent thì trả '
            .'duplicate=true + import_report_id của lần gốc và không ghi SKU/tạo báo cáo mới; nộp lại cùng file nhưng ĐỔI '
            .'update_existing là thao tác nghiệp vụ KHÁC (chỉ tạo mới ↔ cho phép ghi đè) nên vẫn chạy thật. Khi có row lỗi, '
            .'dùng GET /api/v1/skus/import-reports/{id}/error-file để tải file CSV chi tiết lỗi (row/field/error/value).',
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
        // WWHN-331 FE331-P1-03R3: rule upload đọc đúng hợp đồng đã duyệt, không còn số
        // cứng trong code. Cùng nguồn với khối `constraints` trả về cho client, nên
        // giới hạn máy chủ chặn thật và giới hạn UI hiển thị luôn là một.
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', SkuImportContract::allowedExtensions()),
                'max:'.SkuImportContract::maxFileSizeKb(),
            ],
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

        $updateExisting = (bool) ($validated['update_existing'] ?? false);

        try {
            $result = $this->importService->import(
                $fullPath,
                $updateExisting,
                $actorId,
                $requestId,
                $file->getClientOriginalName(),
                $dryRun,
            );

            $result = $this->withContract($result, $updateExisting);

            // WWHN-330 P1-03 + WWHN-331 FE331-P1-02R3: file trùng ĐỦ định danh
            // (sha256 + actor + options_fingerprint) với 1 lần COMMIT gần đây — trả lại
            // Import báo cáo cũ, không tạo báo cáo mới, không ghi SKU lần 2.
            if ($result['duplicate'] ?? false) {
                $result['import_report_id'] = $result['duplicate_of_import_report_id'];

                return $this->respondSuccess(
                    $result,
                    'File này đã được import trước đó với cùng tùy chọn (duplicate/already processed); trả lại kết quả của lần import gốc.',
                );
            }

            $report = $this->persistReport($result, $actorId, $requestId, $file->getClientOriginalName(), $updateExisting);
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
                ['data' => $this->withContract([
                    'committed' => false,
                    'partial_write' => false,
                    'max_rows' => $e->maxRows,
                    'actual_rows' => $e->actualRows,
                ], $updateExisting)],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respondError(
                $e->getMessage(),
                [],
                422,
                'SKU_IMPORT_FILE_INVALID',
                ['data' => $this->withContract(['committed' => false, 'partial_write' => false], $updateExisting)],
            );
        } finally {
            $localDisk->delete($path);
        }
    }

    /**
     * WWHN-331 FE331-P1-02R3 / FE331-P1-03R3 — gắn hợp đồng import vào MỌI response
     * (thành công, 422 row lỗi, 422 vượt hạn mức, 422 file hỏng, và cả ca duplicate).
     *
     * `constraints` là hạn mức máy chủ đang thực sự áp dụng, đọc từ đúng nguồn mà
     * validator và ImportService dùng — nhờ vậy UI không cần (và không được) viết lại
     * các con số đó. `update_existing` + `options_fingerprint` phơi ra định danh của
     * chính lần gửi này để client và QA đối chiếu được vì sao một lần nộp lại được coi
     * là replay hay là một thao tác mới.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withContract(array $result, bool $updateExisting): array
    {
        $result['constraints'] = SkuImportContract::toArray();
        $result['update_existing'] = $updateExisting;
        // Ca duplicate đã mang sẵn vân tay của LẦN IMPORT GỐC (có thể null với bản ghi
        // tạo trước migration 2026_08_18_190000) — không được đè bằng vân tay của lần
        // gửi hiện tại, vì như vậy là khẳng định một điều không đọc được từ dữ liệu.
        if (! array_key_exists('options_fingerprint', $result)) {
            $result['options_fingerprint'] = SkuImportContract::optionsFingerprint($updateExisting);
        }

        return $result;
    }

    /**
     * Import báo cáo (WWHN-224) là metadata truy vết, không phải nguồn sự thật nghiệp vụ.
     * Nếu import thật đã commit SKU thành công nhưng riêng bước ghi report lỗi, request
     * vẫn phải trả kết quả thành công — nếu không client có thể tưởng import thất bại và
     * gửi lại, gây DUPLICATE_CODE giả trên dữ liệu đã thực sự được tạo đúng.
     *
     * @param  array<string, mixed>  $result
     */
    private function persistReport(array $result, ?string $actorId, ?string $requestId, ?string $fileName, bool $updateExisting): ?ImportReport
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
                // WWHN-331 FE331-P1-02R3: lưu vân tay tùy chọn để lần nộp sau so được
                // ĐỦ định danh (file + actor + tùy chọn), không chỉ trùng nội dung file.
                'options_fingerprint' => SkuImportContract::optionsFingerprint($updateExisting),
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
