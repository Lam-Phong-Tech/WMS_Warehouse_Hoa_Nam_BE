<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;

/**
 * WWHN-330 (DEV330-P1-01 gap): ImportErrorFileService ghi file CSV lỗi thật vào
 * storage/app/import-error-files/{report_id}.csv với error_file_expires_at trên
 * import_reports, nhưng không có tiến trình nào dọn dẹp — GET .../error-file coi file
 * hết hạn là 404, song file vật lý vẫn còn trên đĩa vô thời hạn (đĩa phình dần theo số
 * lần import lỗi). Lệnh này purge file đã hết hạn + null hoá error_file_path/
 * error_file_expires_at trên report tương ứng, để "hết hạn" là trạng thái nhất quán ở
 * cả DB lẫn filesystem, không chỉ ở response API.
 *
 * Không xoá report/row_errors (audit trail import vẫn giữ nguyên) — chỉ xoá phần
 * file lỗi tải về đã hết hạn.
 */
class PurgeExpiredImportErrorFiles extends Command
{
    protected $signature = 'import:purge-error-files
        {--dry-run : Chỉ báo cáo sẽ xoá bao nhiêu file, không xoá thật}';

    protected $description = 'Xoá các file lỗi CSV import (SKU) đã hết hạn error_file_expires_at khỏi storage và null hoá path trên import_reports';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk(ImportErrorFileService::DISK);

        $expired = ImportReport::query()
            ->whereNotNull('error_file_path')
            ->whereNotNull('error_file_expires_at')
            ->where('error_file_expires_at', '<', now())
            ->get(['import_report_id', 'error_file_path', 'error_file_expires_at']);

        $purged = 0;
        $missingOnDisk = 0;

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
            }

            $purged++;
        }

        if ($dryRun) {
            $this->info("Purge hết hạn (dry-run): {$purged} file lỗi import sẽ bị xoá.");
        } else {
            $this->info("Purge hết hạn: {$purged} file lỗi import đã xoá ({$missingOnDisk} report path không còn file vật lý trên đĩa).");
        }

        return self::SUCCESS;
    }
}
