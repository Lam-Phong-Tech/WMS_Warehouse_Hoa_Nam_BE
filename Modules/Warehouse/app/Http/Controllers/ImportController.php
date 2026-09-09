<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\SpreadsheetUploadGuard;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Exceptions\ImportConcurrencyException;
use Modules\Warehouse\Exceptions\ImportReportPersistenceException;
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
    use CachesReadPayload;

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
            // DEV224-P1-04R: quyền chuẩn của cả nhóm import là `sku.manage` — đúng thứ
            // route middleware thực thi. Chuỗi `product.manage` cũ là drift tài liệu:
            // client đọc Swagger sẽ đi xin nhầm quyền, còn FE/QA thì test nhầm ma trận.
            new OA\Response(response: 403, description: 'Missing sku.manage permission'),
        ]
    )]
    public function downloadTemplate(): BinaryFileResponse|JsonResponse
    {
        $path = sys_get_temp_dir().'/WWHN-172-SKU-import-template-'.Str::uuid().'.xlsx';
        try {
            $this->importService->createTemplate($path);
        } catch (Throwable $e) {
            // Cùng lớp sự cố hạ tầng với $file->store() ở importExcel(): ghi file
            // template tạm có thể fail vì đĩa tạm đầy/hết quyền ghi. Trước đây
            // exception của PhpSpreadsheet Writer thoát thẳng ra ngoài, không implement
            // HttpExceptionInterface, nên rơi vào 500 chung thay vì lỗi retryable rõ
            // ràng như phần còn lại của controller này.
            Log::error('SKU import template generation failed', ['exception' => $e]);

            return $this->respondError(
                'Không tạo được file template. Vui lòng thử lại.',
                [],
                503,
                'SKU_IMPORT_TEMPLATE_GENERATION_FAILED',
            );
        }

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
            .'DEV224-P1-05R: Import báo cáo là OUTPUT BẮT BUỘC — nó được ghi TRONG cùng transaction với phần ghi SKU/audit, '
            .'nên không tồn tại trạng thái "đã ghi SKU nhưng không có báo cáo". Ghi/đọc báo cáo hỏng thì transaction rollback '
            .'và API trả 503 SKU_IMPORT_REPORT_PERSISTENCE_FAILED, KHÔNG trả 200 thiếu import_report_id. '
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
                        new OA\Property(property: 'file', type: 'string', format: 'binary', example: 'WWHN-172-SKU-import-template-v1.1.0.xlsx'),
                        new OA\Property(property: 'update_existing', type: 'boolean', default: false, example: false),
                        new OA\Property(property: 'dry_run', type: 'boolean', default: false, description: 'true = chỉ kiểm tra thử, không ghi dữ liệu (WWHN-224)', example: false),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Import committed atomically, hoặc dry-run hợp lệ (committed=false, dry_run=true). '
                .'Response luôn có `data.import_report_id` khác null (trừ ca duplicate, khi đó là id của lần import GỐC).'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing sku.manage permission'),
            new OA\Response(response: 409, description: 'CONCURRENCY_CONFLICT — một lần import COMMIT trùng ĐỦ định danh '
                .'(file_sha256 + actor_id + options_fingerprint) đang chạy và request này không giành được khoá tuần tự '
                .'hoá trong `lock.wait_seconds`. Không có SKU nào được tạo/cập nhật, không có Import báo cáo nào được ghi '
                .'(DEV330-P1-07R3, SRS NFR-SRS-003 / AC-GWT-CONC-001). Client thử lại sau khi lần chạy hiện tại kết thúc.'),
            new OA\Response(response: 422, description: 'File/row validation failed; no business side effect. Với lỗi ở tầng DÒNG, '
                .'Import báo cáo REJECTED vẫn được ghi và `data.import_report_id` khác null để tải file lỗi CSV.'),
            new OA\Response(response: 503, description: 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED — không ghi/không đọc được Import báo cáo '
                .'(`import_reports`), là output BẮT BUỘC của WWHN-224. Toàn bộ transaction đã rollback: `committed=false`, '
                .'`partial_write=false`, `import_report_id=null`, `retryable=true`, kèm header `Retry-After`. Không có SKU nào được '
                .'tạo/cập nhật, không audit nghiệp vụ nào còn lại (DEV224-P1-05R).'),
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
        // WWHN-SEC-09: rule `mimes:` ở trên tin phần mở rộng client khai — sniff
        // nội dung thật trước khi đưa file vào PhpSpreadsheet.
        SpreadsheetUploadGuard::assertRealSpreadsheet($file);
        $path = $file->store('temp/imports', 'local');
        if (! is_string($path) || $path === '') {
            // Cùng lý do với ImportReportPersistenceException bên dưới: đây là sự cố
            // KHẢ DỤNG của một phụ thuộc hạ tầng (đĩa lưu trữ tạm, có thể đầy/hết
            // quyền ghi), không phải lỗi input hay lỗi không lường trước — nên trả
            // 503 retryable thay vì để RuntimeException thoát ra ngoài mọi try-catch
            // của action này thành 500 không có cấu trúc.
            return $this->respondError(
                'Không lưu được file import tạm thời. Vui lòng thử lại.',
                [],
                503,
                'SKU_IMPORT_TEMP_STORAGE_FAILED',
                ['data' => $this->withContract([
                    'committed' => false,
                    'partial_write' => false,
                    'import_report_id' => null,
                    'retryable' => true,
                ], (bool) ($validated['update_existing'] ?? false))],
            );
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

            // ImportService ghi thẳng qua Eloquent rồi gọi ProductRepository::forgetCache(),
            // tức chỉ bump 'warehouse.sku:list'. Nhưng sku_code/sku_name/status vừa ghi còn
            // nằm trong các read cache khoá theo 'stock:version' (danh sách sản phẩm Mini
            // App, phiếu, truy vết, báo cáo). Transaction của service đã đóng ở đây nên
            // afterCommit chạy ngay; dry-run không ghi gì nên không cần bump.
            if (($result['committed'] ?? false) === true) {
                DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
            }

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

            // DEV224-P1-05R: Import báo cáo đã được ImportService ghi TRONG cùng
            // transaction với SKU/audit, nên đến được đây nghĩa là báo cáo chắc chắn tồn
            // tại. Controller không còn ghi báo cáo sau khi transaction đã commit — đó
            // chính là chỗ cũ khiến "ghi SKU" và "ghi báo cáo" không thể nguyên tử.
            $reportId = is_string($result['import_report_id'] ?? null) ? $result['import_report_id'] : null;
            $hasRowErrors = $result['row_errors'] !== [];

            if ($hasRowErrors) {
                // WWHN-330 P1-01: sinh sẵn + lưu file lỗi CSV (đã sanitize) ngay khi có row lỗi,
                // để GET .../import-reports/{id}/error-file phục vụ file thật thay vì phải tính
                // lại; sinh file lỗi không được làm hỏng response import chính nên chỉ log khi fail.
                $report = $reportId === null ? null : ImportReport::query()->find($reportId);
                if ($report !== null) {
                    $this->attachErrorFile(
                        $report,
                        $result['row_errors'],
                        $file->getClientOriginalName(),
                        is_string($result['sheet_name'] ?? null) ? $result['sheet_name'] : null,
                    );
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
        } catch (ImportConcurrencyException $e) {
            // DEV330-P1-07R3: đã có một lần import COMMIT trùng ĐỦ định danh đang chạy và
            // ta không giành được khoá trong thời gian chờ đã duyệt.
            //
            // SRS NFR-SRS-003 + AC-GWT-CONC-001: với request song song, "tối đa một
            // request thành công; request còn lại trả 409/412/422 theo điểm phát hiện" —
            // nên chạy tiếp mà không có khoá (hành vi cũ) là vi phạm trực tiếp, không chỉ
            // là một lựa chọn kỹ thuật kém. Điểm phát hiện ở đây là tranh chấp khoá, nên
            // mã đúng là 409 `CONCURRENCY_CONFLICT` — một trong bốn mã 409 mà §8.2 công
            // nhận. (Docs baseline không định nghĩa 423/429/503 nên không được tự chế.)
            //
            // Không có SKU nào được tạo/cập nhật và không có Import báo cáo nào được ghi:
            // exception ném ra TRƯỚC khi vào transaction, đúng cam kết "State, stock and
            // movement unchanged" của §8.2 cho 409 không phải replay.
            return $this->respondError(
                $e->getMessage(),
                [],
                409,
                'CONCURRENCY_CONFLICT',
                ['data' => $this->withContract([
                    'committed' => false,
                    'partial_write' => false,
                    'lock_wait_seconds' => $e->waitedSeconds,
                ], $updateExisting)],
            );
        } catch (ImportReportPersistenceException $e) {
            // DEV224-P1-05R — Import báo cáo là OUTPUT BẮT BUỘC của WWHN-224, không phải
            // metadata tuỳ nghi. Hành vi cũ (nuốt lỗi, vẫn trả 200 với
            // import_report_id=null) sai ở ba tầng cùng lúc: mất truy vết của một lần ghi
            // dữ liệu thật; response nói "thành công" nhưng thiếu chính output mà task
            // này sinh ra; và cửa sổ idempotent mất bản ghi COMMITTED nên chính lần gửi
            // lại của người dùng sẽ import lần hai.
            //
            // Nay lỗi ném ra TỪ BÊN TRONG transaction ghi SKU/audit nên rollback là của
            // chính DB, không phải bồi thường thủ công: không SKU nào được tạo/sửa,
            // không audit nghiệp vụ nào còn lại, không báo cáo nửa vời.
            //
            // Vì sao 503 chứ không 409/500: đây là sự cố KHẢ DỤNG của một phụ thuộc bắt
            // buộc (kho báo cáo), server-side và tự hết khi phụ thuộc đó trở lại — khác
            // hẳn 409 CONCURRENCY_CONFLICT (tranh chấp nghiệp vụ, DEV330-P1-07R3) và
            // khác 500 INTERNAL_ERROR (lỗi không lường trước, không hứa hẹn thử lại
            // được). §8.2 chưa liệt kê 503; mã này do DEV224-P1-05R chỉ định và kèm
            // `Retry-After`, đã ghi vào DEC-IMP-001 §10 như một yêu cầu bổ sung baseline.
            return $this->respondError(
                'Không lưu được Import báo cáo; toàn bộ giao dịch import đã rollback, không có SKU nào được tạo/cập nhật. Vui lòng thử lại.',
                [],
                503,
                'SKU_IMPORT_REPORT_PERSISTENCE_FAILED',
                ['data' => $this->withContract([
                    // `dry_run` phải có mặt: client cần biết lần chạy vừa hỏng là kiểm
                    // tra thử hay import thật để hiển thị đúng thông điệp thử lại.
                    'dry_run' => $dryRun,
                    'committed' => false,
                    'partial_write' => false,
                    'import_report_id' => null,
                    'retryable' => true,
                ], $updateExisting)],
            )->header('Retry-After', (string) SkuImportContract::lockWaitSeconds());
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
     * WWHN-330 P1-01: sinh file lỗi CSV (đã sanitize) và gắn path + expiry vào Import
     * báo cáo. Lỗi sinh file không được làm hỏng response import chính — chỉ log, vì
     * file lỗi là tiện ích tra cứu thêm, không phải nguồn sự thật nghiệp vụ.
     *
     * DEV330-P1-01R3: truyền thêm tên file nguồn và tên sheet để file lỗi đủ 9 cột theo
     * SRS §13.12 — người nhận file mở ra là biết lỗi thuộc file/sheet nào, không phải
     * đối chiếu ngược qua import_report_id.
     *
     * @param  array<int, array<string, mixed>>  $rowErrors
     */
    private function attachErrorFile(ImportReport $report, array $rowErrors, ?string $sourceFile = null, ?string $sheetName = null): void
    {
        try {
            $generated = $this->errorFileService->generate(
                $report->import_report_id,
                $rowErrors,
                $sourceFile,
                $sheetName,
            );
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
