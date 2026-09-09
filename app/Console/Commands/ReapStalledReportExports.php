<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Support\ReportExportJobRunner;

/**
 * WWHN-343 (TC_FUNC_03). Cùng bánh đà tự lành với `GET /reports/export/{id}`, nhưng chạy
 * theo lịch nên KHÔNG phụ thuộc việc có ai đang poll hay không:
 *
 * - Job PENDING quá `wms_export.pickup_grace_seconds` (worker hàng đợi không chạy) được
 *   chạy ngay trong tiến trình này, hoặc chốt FAILED nếu tắt inline_fallback.
 * - Job PROCESSING quá `wms_export.processing_timeout_seconds` (tiến trình chạy nó đã
 *   chết) được chốt FAILED kèm error_code/error_message.
 *
 * Không có lệnh này thì mọi job mà người dùng đóng tab giữa chừng sẽ nằm PENDING vĩnh
 * viễn trong bảng — đúng thứ Expected Result của WWHN-343 cấm, và cũng làm mọi truy vấn
 * vận hành theo `status` mất ý nghĩa.
 */
class ReapStalledReportExports extends Command
{
    protected $signature = 'report-export:reap
        {--limit=25 : Số job xử lý tối đa mỗi lần chạy}
        {--dry-run : Chỉ liệt kê job quá hạn, không chạy và không chốt FAILED}';

    protected $description = 'Chạy hoặc chốt FAILED các job export báo cáo kẹt ở PENDING/PROCESSING (WWHN-343)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $grace = (int) config('wms_export.pickup_grace_seconds', 20);
        $timeout = (int) config('wms_export.processing_timeout_seconds', 180);

        // Lọc sẵn theo mốc thời gian ở tầng SQL: ensureProgress() cũng tự kiểm lại, nhưng
        // để nó tự lọc thì lệnh phải nạp toàn bộ job PENDING/PROCESSING của mọi người
        // dùng mỗi phút.
        $stalled = ReportExportJob::query()
            ->where(function ($query) use ($grace, $timeout) {
                $query->where(fn ($q) => $q->where('status', 'PENDING')->where('created_at', '<', now()->subSeconds($grace)))
                    ->orWhere(fn ($q) => $q->where('status', 'PROCESSING')->where('updated_at', '<', now()->subSeconds($timeout)));
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($stalled->isEmpty()) {
            $this->info('Không có job export nào quá hạn.');

            return self::SUCCESS;
        }

        $ready = 0;
        $failed = 0;

        foreach ($stalled as $job) {
            if ($dryRun) {
                $this->line("[dry-run] {$job->export_job_id} status={$job->status} report_code={$job->report_code}");

                continue;
            }

            $resolved = ReportExportJobRunner::ensureProgress($job);
            match ($resolved->status) {
                'READY' => $ready++,
                'FAILED' => $failed++,
                default => null,
            };
        }

        $this->info($dryRun
            ? "Dry-run: {$stalled->count()} job export quá hạn."
            : "Đã xử lý {$stalled->count()} job export quá hạn: {$ready} READY, {$failed} FAILED.");

        return self::SUCCESS;
    }
}
