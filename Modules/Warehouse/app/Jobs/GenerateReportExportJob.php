<?php

namespace Modules\Warehouse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\OperationalReportController;
use Modules\Warehouse\Models\ReportExportJob;
use RuntimeException;
use Throwable;

/**
 * Chạy nền phần nặng của `POST /reports/export` (query + dựng XLSX/PDF) mà trước đây
 * chạy đồng bộ trong request khi FE gọi `download_url` — xem migration
 * `2026_08_27_090000_create_report_export_jobs_table.php`.
 *
 * Job không có "người gọi hiện tại" như một HTTP request thật, nhưng nhiều builder báo
 * cáo dựa vào `auth('api')->user()` / `$request->user()` để áp warehouse data-scope
 * (WWHN-254) và mask/permission PII bảo hành (WWHN-258, §13.8). Job PHẢI đóng vai đúng
 * actor đã submit — xem handle().
 */
class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $exportJobId,
        public readonly string $reportCode,
        public readonly string $format,
        public readonly array $filters,
        public readonly ?string $requestedBy,
    ) {
        // Queueable trait đã khai báo $afterCommit (không có type) nên không thể redeclare
        // với type ở đây (PHP fatal: incompatible property definition) — set qua constructor.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $job = ReportExportJob::query()->find($this->exportJobId);
        if ($job === null) {
            // Bản ghi đã bị dọn (ví dụ purge chạy trước khi worker kịp nhặt job) — không
            // còn chỗ nào để ghi kết quả, không có gì để làm.
            return;
        }

        $job->update(['status' => 'PROCESSING']);

        try {
            $user = $this->requestedBy !== null ? User::find($this->requestedBy) : null;

            // Đóng vai actor đã submit — cả hai dòng dưới đây đều cần: một số builder
            // đọc $request->user(), một số khác (ví dụ reportWarehouseScope()) đọc thẳng
            // auth('api')->user(). Thiếu một trong hai sẽ âm thầm bỏ qua warehouse
            // data-scope hoặc PII gating.
            $request = Request::create('/', 'GET', $this->filters);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('request_id', 'export-job:'.$this->exportJobId);
            Auth::guard('api')->setUser($user);

            $payload = app(OperationalReportController::class)->exportBytesFor($this->reportCode, $request, $this->format);

            $extension = $this->format === 'pdf' ? 'pdf' : 'xlsx';
            $storedPath = 'report-exports/'.$this->exportJobId.'.'.$extension;
            // `put()` KHÔNG ném lỗi khi ghi hỏng — nó trả về `false`. Không kiểm giá
            // trị trả về thì job vẫn tự chuyển sang READY cho một file CHƯA BAO GIỜ
            // được ghi, và người dùng chỉ phát hiện ở bước bấm Tải về (500/404) — mất
            // hẳn manh mối vì log lúc đó không còn nói gì về nguyên nhân thật.
            // Dựng lại được đúng tình huống này ngày 06/09/2026 khi thử trỏ đĩa export
            // sang s3 trong lúc bucket chưa được tạo.
            $disk = (string) config('wms_export.disk');
            if (Storage::disk($disk)->put($storedPath, $payload['bytes']) === false) {
                throw new RuntimeException(
                    "Không ghi được file export vào đĩa '{$disk}' (đường dẫn: {$storedPath})."
                );
            }

            $job->update([
                'status' => 'READY',
                'row_count' => $payload['rowCount'],
                'file_path' => $storedPath,
                'file_name' => $payload['fileName'],
                'expires_at' => now()->addHours(24),
            ]);
        } catch (Throwable $e) {
            Log::error('Report export job failed', [
                'export_job_id' => $this->exportJobId,
                'report_code' => $this->reportCode,
                'exception' => $e,
            ]);

            $job->update([
                'status' => 'FAILED',
                'error_code' => $this->errorCodeFor($e),
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `ApiError::abort()` (dùng trong các payload builder cho row-limit/PII/scope) ném
     * `HttpResponseException`/`HttpException` với payload JSON đã có `error.code` chuẩn;
     * job đứng ngoài vòng đời HTTP nên phải tự đọc lại field đó thay vì để mất mã lỗi cụ
     * thể, còn lại mọi lỗi khác đều là INTERNAL_ERROR.
     */
    private function errorCodeFor(Throwable $e): string
    {
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if ($response !== null) {
                $decoded = json_decode((string) $response->getContent(), true);
                $code = $decoded['error']['code'] ?? null;
                if (is_string($code) && $code !== '') {
                    return $code;
                }
            }
        }

        return 'REPORT_EXPORT_JOB_FAILED';
    }
}
