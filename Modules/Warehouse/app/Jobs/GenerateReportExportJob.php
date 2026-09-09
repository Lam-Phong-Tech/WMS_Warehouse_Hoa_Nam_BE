<?php

namespace Modules\Warehouse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Support\ReportExportJobRunner;
use Throwable;

/**
 * Chạy nền phần nặng của `POST /reports/export` (query + dựng XLSX/PDF) mà trước đây
 * chạy đồng bộ trong request khi FE gọi `download_url` — xem migration
 * `2026_08_27_090000_create_report_export_jobs_table.php`.
 *
 * Toàn bộ logic thật nằm ở ReportExportJobRunner: cùng một job còn có thể được chạy bởi
 * đường cứu hộ đồng bộ (`GET /reports/export/{id}` hoặc `report-export:reap`) khi không
 * có worker — WWHN-343. Lớp này chỉ là vỏ hàng đợi.
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

        // Giành quyền chạy. Thua nghĩa là đường cứu hộ đồng bộ đã nhận job này trong lúc
        // worker còn xếp hàng (WWHN-343) — chạy tiếp là export chạy hai lần và ghi đè kết
        // quả của nhau.
        if (! ReportExportJobRunner::claim($this->exportJobId)) {
            return;
        }

        ReportExportJobRunner::run($job->refresh());
    }

    /**
     * Worker hết `--timeout`, hết `--tries`, hoặc bị kill: Laravel gọi failed() nhưng
     * handle() KHÔNG chạy tới khối catch của nó, nên nếu không chốt ở đây bản ghi kẹt
     * PROCESSING mãi mãi — đúng lớp lỗi mà WWHN-343 cấm ("không được để job tồn tại ở
     * PENDING/đang chạy vô thời hạn").
     */
    public function failed(?Throwable $e): void
    {
        $job = ReportExportJob::query()->find($this->exportJobId);
        if ($job === null || in_array($job->status, ['READY', 'FAILED'], true)) {
            return;
        }

        ReportExportJobRunner::fail(
            $job,
            ReportExportJobRunner::ERROR_STALLED,
            'Worker hàng đợi dừng giữa chừng khi tạo file export'
                .($e !== null ? ': '.$e->getMessage() : '.')
        );
    }
}
