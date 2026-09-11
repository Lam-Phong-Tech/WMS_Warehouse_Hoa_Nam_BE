<?php

namespace Modules\Warehouse\Support;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\ReportExportJob;
use RuntimeException;
use Throwable;

/**
 * Nơi lưu NỘI DUNG file export báo cáo — một cửa duy nhất cho ghi (job), đọc (tải về) và
 * xoá (purge).
 *
 * Mặc định `wms_export.disk = database`: nội dung nằm ở bảng `report_export_files`, dùng
 * chung giữa mọi tiến trình. Triệu chứng và lý do: xem migration
 * `2026_09_11_120000_create_report_export_files_table`.
 *
 * Đặt `REPORT_EXPORT_DISK` sang tên một filesystem disk (vd `s3`, `r2`) để lưu ra ổ đĩa như
 * trước — chỉ nên là ổ DÙNG CHUNG; `local` chỉ đúng khi mọi container cùng bind-mount một
 * thư mục (compose dev).
 *
 * Đọc luôn thử bản trong database TRƯỚC rồi mới tới ổ đĩa: job tạo trước khi chuyển chế độ
 * (file đang nằm trên ổ) vẫn tải được, và bản do ReportExportJobRunner::recoverMissingFile()
 * dựng lại (luôn vào database) được ưu tiên hơn ổ đĩa vừa đọc hỏng.
 *
 * `report_export_jobs.file_path` vẫn là khoá logic `report-exports/<id>.<ext>` ở mọi nơi lưu:
 * status/download/purge dựa vào nó để biết job còn file hay đã bị purge.
 */
final class ReportExportFileStore
{
    public const DATABASE = 'database';

    private const TABLE = 'report_export_files';

    /** Nơi lưu đang cấu hình: `database` hoặc tên một filesystem disk. */
    public static function target(): string
    {
        $configured = trim((string) config('wms_export.disk', self::DATABASE));

        return $configured === '' ? self::DATABASE : $configured;
    }

    /**
     * Lưu nội dung file của job vào `$target` (mặc định: nơi đang cấu hình). Ném
     * RuntimeException khi không lưu được — người gọi chốt FAILED kèm nguyên nhân, không bao
     * giờ để job READY cho một file không tồn tại.
     */
    public static function put(
        ReportExportJob $job,
        string $path,
        string $bytes,
        DateTimeInterface $expiresAt,
        ?string $target = null,
    ): void {
        $target ??= self::target();

        if ($target === self::DATABASE) {
            self::putInDatabase($job, $bytes, $expiresAt);
        } elseif (Storage::disk($target)->put($path, $bytes) === false) {
            // `put()` KHÔNG ném lỗi khi ghi hỏng — nó trả về `false`. Không kiểm giá trị trả
            // về thì job vẫn tự chuyển sang READY cho một file CHƯA BAO GIỜ được ghi (dựng lại
            // được 06/09/2026 khi trỏ đĩa export sang s3 lúc bucket chưa được tạo).
            throw new RuntimeException(
                "Không ghi được file export vào đĩa '{$target}' (đường dẫn: {$path})."
            );
        }

        // Dấu vết để đối chiếu với log "Report export file missing at download": nơi lưu,
        // đường dẫn và MÁY đã ghi (container `queue`, hay request cứu hộ trên `backend`).
        Log::info('Report export file stored', [
            'export_job_id' => $job->export_job_id,
            'target' => $target,
            'path' => $path,
            'bytes' => strlen($bytes),
            'host' => gethostname() ?: null,
        ]);
    }

    /** Nội dung file nếu còn — bản trong database trước, rồi tới ổ đĩa; `null` nếu không thấy ở đâu. */
    public static function read(ReportExportJob $job): ?string
    {
        $bytes = self::readFromDatabase($job);
        if ($bytes !== null) {
            return $bytes;
        }

        $path = (string) $job->file_path;
        if ($path === '') {
            return null;
        }

        $disk = Storage::disk(self::diskForFiles());
        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        return is_string($bytes) ? $bytes : null;
    }

    /** Xoá nội dung file của job ở mọi nơi có thể; trả `true` nếu thực sự có gì bị xoá. */
    public static function delete(ReportExportJob $job): bool
    {
        $deleted = DB::table(self::TABLE)->where('export_job_id', $job->export_job_id)->delete() > 0;

        $path = (string) $job->file_path;
        if ($path !== '') {
            $disk = Storage::disk(self::diskForFiles());
            if ($disk->exists($path)) {
                $disk->delete($path);
                $deleted = true;
            }
        }

        return $deleted;
    }

    /**
     * Dọn nội dung đã quá hạn mỗi khi có export mới — bảng không phình vô hạn kể cả khi
     * scheduler (`report-export:purge`) không chạy. Chỉ xoá NỘI DUNG; null hoá `file_path`
     * trên job vẫn là việc của lệnh purge (job hết hạn đã có nhánh "đã hết hạn" khi tải về).
     *
     * Là việc dọn dẹp nên KHÔNG BAO GIỜ được làm hỏng lần export vừa lưu xong.
     */
    public static function pruneExpired(int $limit = 50): int
    {
        try {
            $ids = DB::table(self::TABLE)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->limit($limit)
                ->pluck('export_job_id');

            return $ids->isEmpty()
                ? 0
                : DB::table(self::TABLE)->whereIn('export_job_id', $ids->all())->delete();
        } catch (Throwable $e) {
            Log::warning('Không dọn được nội dung file export đã hết hạn', ['exception' => $e]);

            return 0;
        }
    }

    private static function putInDatabase(ReportExportJob $job, string $bytes, DateTimeInterface $expiresAt): void
    {
        try {
            // upsert = một câu INSERT ... ON CONFLICT nguyên tử: dựng lại file cho job đã có
            // bản lưu thì ghi đè, không vỡ khoá chính. Bọc transaction để câu lỗi chỉ lùi về
            // savepoint khi người gọi đang ở trong transaction (pgsql huỷ CẢ transaction bao
            // ngoài sau một câu lỗi, kéo theo câu chốt FAILED ngay sau đó).
            DB::transaction(fn () => DB::table(self::TABLE)->upsert(
                [[
                    'export_job_id' => $job->export_job_id,
                    'content_base64' => base64_encode($bytes),
                    'byte_size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                ]],
                ['export_job_id'],
                ['content_base64', 'byte_size', 'sha256', 'expires_at', 'created_at'],
            ));
        } catch (QueryException $e) {
            // Thông điệp của QueryException chép NGUYÊN câu SQL kèm tham số — tức toàn bộ nội
            // dung file dạng base64, vài MB — vào error_message của job lẫn log. Chỉ giữ lỗi
            // của driver (vd bảng chưa có vì deploy quên `php artisan migrate`).
            $reason = $e->getPrevious()?->getMessage() ?? 'SQLSTATE '.$e->getCode();

            throw new RuntimeException(
                'Không lưu được file export vào database (bảng '.self::TABLE.'): '.mb_substr($reason, 0, 300)
            );
        }
    }

    private static function readFromDatabase(ReportExportJob $job): ?string
    {
        try {
            $encoded = DB::table(self::TABLE)
                ->where('export_job_id', $job->export_job_id)
                ->value('content_base64');
        } catch (QueryException $e) {
            // Bảng chưa có (chưa migrate): vẫn thử ổ đĩa rồi để đường dựng lại báo lỗi rõ
            // ràng, thay cho một 500 ở bước tải về.
            Log::warning('Không đọc được bảng nội dung file export', [
                'export_job_id' => $job->export_job_id,
                'error' => mb_substr((string) $e->getPrevious()?->getMessage(), 0, 300),
            ]);

            return null;
        }

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $bytes = base64_decode($encoded, true);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Ổ đĩa để đọc/xoá file nằm NGOÀI database: ổ đang cấu hình, hoặc `local` cho job tạo
     * trước khi chuyển sang lưu trong database.
     */
    private static function diskForFiles(): string
    {
        $target = self::target();

        return $target === self::DATABASE ? 'local' : $target;
    }
}
