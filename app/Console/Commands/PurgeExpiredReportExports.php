<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Support\ReportExportFileStore;

/**
 * Dọn file XLSX/PDF export báo cáo đã quá `expires_at` (24h sau khi job READY — xem
 * ReportExportJobRunner::run()), cùng mẫu với PurgeExpiredImportErrorFiles: không hard-delete
 * bản ghi job (giữ audit trail), chỉ null hoá file_path/file_name sau khi xoá nội dung.
 *
 * Từ 11/09/2026 nội dung nằm ở bảng `report_export_files` (mặc định) hoặc trên ổ đĩa (job cũ,
 * hay `REPORT_EXPORT_DISK` trỏ ra ổ) — ReportExportFileStore::delete() xoá ở cả hai nơi.
 */
class PurgeExpiredReportExports extends Command
{
    protected $signature = 'report-export:purge {--dry-run : Chỉ báo cáo sẽ xoá bao nhiêu file, không xoá thật}';

    protected $description = 'Xoá file XLSX/PDF export báo cáo đã hết hạn khỏi storage và null hoá path trên report_export_jobs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = ReportExportJob::query()
            ->where('status', 'READY')
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get(['export_job_id', 'file_path']);

        $purged = 0;
        // Giữ tên khoá audit cũ; nay nghĩa là "không còn nội dung ở database lẫn ổ đĩa".
        $missingOnDisk = 0;

        foreach ($expired as $job) {
            if (! $dryRun) {
                if (! ReportExportFileStore::delete($job)) {
                    $missingOnDisk++;
                }

                $job->forceFill(['file_path' => null, 'file_name' => null])->save();
            }

            $purged++;
        }

        AuditLogger::record(
            'REPORT_EXPORT_FILE_PURGE',
            'report_export',
            null,
            null,
            null,
            afterData: [
                'dry_run' => $dryRun,
                'expired_jobs_purged' => $purged,
                'expired_paths_missing_on_disk' => $missingOnDisk,
            ],
            reason: 'Retention purge cho file export báo cáo — checklist Hiệu năng và triển khai 27/08',
        );

        $this->info($dryRun
            ? "Purge retention (dry-run): {$purged} file export sẽ bị xoá."
            : "Purge retention: {$purged} file export đã xoá ({$missingOnDisk} path không còn file vật lý).");

        return self::SUCCESS;
    }
}
