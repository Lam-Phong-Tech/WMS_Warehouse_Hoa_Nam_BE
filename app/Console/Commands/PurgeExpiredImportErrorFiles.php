<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;
use Modules\Warehouse\Support\SkuImportContract;

/**
 * WWHN-330 (DEV330-P1-01 / P1-01R3): dọn file lỗi CSV import đã hết hạn.
 *
 * `ImportErrorFileService` ghi file thật vào `storage/app/import-error-files/{id}.csv`
 * kèm `error_file_expires_at`. Hết hạn LOGIC thì API trả 404 — nhưng file vẫn nằm trên
 * đĩa. Nếu không có tiến trình nào xoá thì nội dung file người dùng nộp (mã SKU, tên
 * hàng, giá trị từng ô sai) tồn tại vô thời hạn dù hợp đồng đã nói "hết hạn sau 24 giờ",
 * và dung lượng đĩa phình dần theo số lần import lỗi.
 *
 * SRS §13.8 đặt ra bốn yêu cầu cho MỌI purge job và lệnh này đáp ứng đủ:
 * - **idempotent**: chạy lại không đổi kết quả (bản ghi đã null hoá không còn được chọn);
 * - **dry-run/report**: `--dry-run` báo cáo mà không xoá; mọi lần chạy đều in số liệu;
 * - **retry**: một lần lỗi giữa chừng không làm hỏng trạng thái — lần chạy kế tiếp gom
 *   đúng những gì còn sót, vì điều kiện chọn dựa trên dữ liệu chứ không dựa trên con trỏ;
 * - **audit số record đã purge**: ghi `IMPORT_ERROR_FILE_PURGE` vào audit log (trước đây
 *   chỉ in ra console — console log không phải bằng chứng tuân thủ, nó biến mất).
 *
 * `legal-hold exclusion` của §13.8 không áp dụng ở đây và đó là một quyết định, không
 * phải bỏ sót: legal hold gắn với hồ sơ bảo hành/PII khách hàng (DR-PRIV-001), còn đối
 * tượng ở đây là bản sao tạm của một file do chính người dùng nộp, luôn dựng lại được
 * bằng cách import lại. **Không** xoá `import_reports` hay `row_errors` — audit trail của
 * lần import vẫn nguyên vẹn theo NFR-SRS-014 (không hard-delete bằng chứng nghiệp vụ);
 * chỉ phần file tải về đã hết hạn bị xoá.
 */
class PurgeExpiredImportErrorFiles extends Command
{
    protected $signature = 'import:purge-error-files
        {--dry-run : Chỉ báo cáo sẽ xoá bao nhiêu file, không xoá thật}
        {--skip-orphans : Bỏ qua bước quét file mồ côi (chỉ xoá theo bản ghi báo cáo)}';

    protected $description = 'Xoá file lỗi CSV import (SKU) đã quá retention khỏi storage, null hoá path trên import_reports và ghi audit số lượng đã purge';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk(ImportErrorFileService::DISK);

        // DEV330-P1-01R3: mốc xoá VẬT LÝ = hết hạn logic + khoảng đệm đã duyệt, chứ không
        // phải "now()" như trước. Hai mốc là hai chính sách khác nhau và phải đọc được
        // riêng; gộp làm một khiến không ai trả lời được "file còn trên đĩa bao lâu".
        $graceHours = SkuImportContract::errorFilePurgeGraceHours();
        $purgeBefore = now()->subHours($graceHours);

        $expired = ImportReport::query()
            ->whereNotNull('error_file_path')
            ->whereNotNull('error_file_expires_at')
            ->where('error_file_expires_at', '<', $purgeBefore)
            ->get(['import_report_id', 'error_file_path', 'error_file_expires_at']);

        $purged = 0;
        $missingOnDisk = 0;
        $keptPaths = [];

        foreach ($expired as $report) {
            $path = (string) $report->error_file_path;

            if (! $dryRun) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                } else {
                    $missingOnDisk++;
                }

                $report->forceFill([
                    'error_file_path' => null,
                    'error_file_expires_at' => null,
                ])->save();
            } else {
                $keptPaths[] = $path;
            }

            $purged++;
        }

        $orphans = $this->sweepOrphans($disk, $dryRun, $graceHours);

        // Bằng chứng tuân thủ phải nằm ở audit log, không phải ở stdout của cron.
        // Ghi cả lần chạy 0 file: "đã chạy và không có gì để xoá" khác hẳn "job không chạy".
        AuditLogger::record(
            'IMPORT_ERROR_FILE_PURGE',
            'sku_import',
            null,
            null,
            null,
            afterData: [
                'dry_run' => $dryRun,
                'expiry_hours' => SkuImportContract::errorFileExpiryHours(),
                'purge_grace_hours' => $graceHours,
                'purge_before' => $purgeBefore->toIso8601String(),
                'expired_reports_purged' => $purged,
                'expired_paths_missing_on_disk' => $missingOnDisk,
                'orphan_files_purged' => $orphans,
                'max_on_disk_hours' => SkuImportContract::errorFileMaxOnDiskHours(),
            ],
            reason: 'SRS §13.8 / NFR-SRS-018 retention-purge execution evidence (DEC-IMP-001 §5)',
        );

        if ($dryRun) {
            $this->info("Purge retention (dry-run): {$purged} file lỗi theo báo cáo + {$orphans} file mồ côi sẽ bị xoá.");
            foreach ($keptPaths as $path) {
                $this->line('  - '.$path);
            }
        } else {
            $this->info(
                "Purge retention: {$purged} file lỗi theo báo cáo đã xoá ({$missingOnDisk} path không còn file vật lý), "
                ."{$orphans} file mồ côi đã xoá."
            );
        }

        return self::SUCCESS;
    }

    /**
     * DEV330-P1-01R3 — quét file KHÔNG còn bản ghi nào trỏ tới.
     *
     * Vòng lặp theo `import_reports` ở trên không bao giờ chạm tới chúng, nên trước thay
     * đổi này chúng nằm trên đĩa vĩnh viễn. Chúng phát sinh thật, không phải giả định:
     * `ImportController::attachErrorFile()` ghi file TRƯỚC rồi mới lưu path vào DB — bước
     * lưu thất bại (đã có nhánh log riêng cho ca đó) để lại một file không ai biết; ngoài
     * ra `--dry-run` chạy nhầm, khôi phục DB từ backup, hoặc xoá báo cáo thủ công đều
     * sinh ra cùng lớp rác này.
     *
     * Điều kiện xoá dùng thời điểm SỬA ĐỔI của chính file (không có DB để tra) và cùng
     * mốc retention với nhánh trên, nên một file mồ côi không bao giờ bị xoá sớm hơn một
     * file có báo cáo hợp lệ.
     */
    private function sweepOrphans(Filesystem $disk, bool $dryRun, int $graceHours): int
    {
        if ((bool) $this->option('skip-orphans') || ! SkuImportContract::errorFileOrphanSweepEnabled()) {
            return 0;
        }

        $subdir = ImportErrorFileService::STORAGE_SUBDIR;
        if (! $disk->exists($subdir)) {
            return 0;
        }

        $referenced = ImportReport::query()
            ->whereNotNull('error_file_path')
            ->pluck('error_file_path')
            ->map(static fn ($path): string => (string) $path)
            ->flip();

        $olderThan = now()
            ->subHours(SkuImportContract::errorFileExpiryHours() + $graceHours)
            ->getTimestamp();

        $removed = 0;
        foreach ($disk->files($subdir) as $path) {
            if ($referenced->has($path)) {
                continue;
            }
            if ($disk->lastModified($path) > $olderThan) {
                // File vừa sinh trong lần import đang chạy: chưa kịp lưu path vào DB
                // không có nghĩa là mồ côi. Chỉ xoá khi đã quá đúng mốc retention.
                continue;
            }

            if (! $dryRun) {
                $disk->delete($path);
            }
            $removed++;
        }

        return $removed;
    }
}
