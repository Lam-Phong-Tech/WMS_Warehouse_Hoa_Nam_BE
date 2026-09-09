<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WWHN-347. Chốt job export sang FAILED khi tiến trình chết vì LỖI FATAL của PHP.
 *
 * VÌ SAO `catch (Throwable)` KHÔNG ĐỦ: `Allowed memory size of N bytes exhausted` là
 * E_ERROR, không phải exception. Nó không đi qua `try/catch` nào cả — PHP dừng thẳng
 * tiến trình. Nên `ReportExportJobRunner::run()` dù đã bọc `catch (Throwable)` vẫn KHÔNG
 * bao giờ chạy tới nhánh fail() khi file export vượt trần bộ nhớ; bản ghi
 * `report_export_jobs` đứng lại ở PROCESSING với error_code = null, FE poll mãi không ra
 * kết quả. Đúng "Actual Result" của WWHN-347 (job không chuyển FAILED, không error_code).
 *
 * Dựng lại được 100%: `POST /reports/export` với report_code=document-history, format=pdf
 * trên dữ liệu 06→09/2026 (4.243 dòng) khi trần là 128M.
 *
 * CÁCH HOẠT ĐỘNG: `register_shutdown_function` vẫn chạy sau một fatal error. Handler đọc
 * lại từng job còn "đang bay", và chỉ chốt FAILED những job CHƯA ở trạng thái cuối — nên
 * đường thành công không phải gọi disarm() (giảm bề mặt sửa ở phía người gọi xuống đúng
 * MỘT dòng), còn job đã READY/FAILED thì handler không đụng vào.
 *
 * KHÔNG THAY THẾ được bánh đà `ensureProgress()` của WWHN-343: nếu tiến trình bị SIGKILL
 * (OOM killer của container, `docker kill`) thì shutdown function KHÔNG chạy. Hai lớp bổ
 * sung cho nhau — lớp này chốt lỗi NGAY và nói đúng nguyên nhân, lớp kia quét dọn muộn
 * theo timeout cho những cái chết không kịp trăng trối.
 */
class ReportExportFatalGuard
{
    public const ERROR_FATAL = 'REPORT_EXPORT_FATAL';

    /** @var array<string, string> export_job_id => report_code, những job đang dựng file. */
    private static array $inFlight = [];

    private static bool $registered = false;

    /**
     * Bộ nhớ để dành, nhả ra ngay đầu handler.
     *
     * Sau một fatal vì hết bộ nhớ, tiến trình đang ở SÁT trần: handler cần cấp phát để
     * dựng câu query và chuỗi thông báo, mà mọi cấp phát lúc đó đều fail — handler chết
     * im lặng và ta lại mất bản ghi FAILED. Giữ sẵn vài MB rồi unset() ở dòng đầu handler
     * là cách chuẩn để handler có chỗ thở.
     */
    private static ?string $reserve = null;

    /** Đánh dấu job đang được dựng file trong tiến trình này. */
    public static function watch(string $exportJobId, string $reportCode): void
    {
        self::$inFlight[$exportJobId] = $reportCode;
        self::register();
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        self::$reserve = str_repeat('x', 2 * 1024 * 1024);

        register_shutdown_function(static function (): void {
            self::$reserve = null;

            $error = error_get_last();
            if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                // Thoát bình thường (hoặc chỉ có warning/notice) — không có gì để chốt.
                return;
            }

            // Trần cũ đã bị chạm nên mọi thao tác tiếp theo cần chỗ; nâng thêm cho riêng
            // đoạn dọn dẹp ngắn này.
            @ini_set('memory_limit', '-1');

            foreach (self::$inFlight as $exportJobId => $reportCode) {
                self::failIfUnfinished($exportJobId, $reportCode, $error);
            }
        });
    }

    /**
     * @param  array{type:int, message:string, file:string, line:int}  $error
     */
    private static function failIfUnfinished(string $exportJobId, string $reportCode, array $error): void
    {
        try {
            // Truy vấn thẳng qua DB facade: sau fatal, container/Eloquent có thể đã ở
            // trạng thái nửa vời, còn câu UPDATE có điều kiện thì không cần gì ngoài
            // connection. Điều kiện `whereIn` giữ nguyên bất biến "chỉ MỘT nơi chốt kết
            // quả": job đã READY/FAILED thì không ghi đè.
            $message = self::humanMessage($error);

            $updated = DB::table('report_export_jobs')
                ->where('export_job_id', $exportJobId)
                ->whereIn('status', ['PENDING', 'PROCESSING'])
                ->update([
                    'status' => 'FAILED',
                    'error_code' => self::ERROR_FATAL,
                    'error_message' => mb_substr($message, 0, 1000),
                    'updated_at' => now(),
                ]);

            if ($updated > 0) {
                Log::error('Report export job chết vì lỗi fatal của PHP', [
                    'export_job_id' => $exportJobId,
                    'report_code' => $reportCode,
                    'php_error' => $error['message'],
                    'file' => $error['file'].':'.$error['line'],
                ]);
            }
        } catch (Throwable $e) {
            // Handler chạy trong lúc tiến trình đang hấp hối — không được ném tiếp.
            error_log('ReportExportFatalGuard không chốt được job '.$exportJobId.': '.$e->getMessage());
        }
    }

    /**
     * Đổi thông điệp fatal của PHP thành câu người dùng đọc được. Hết bộ nhớ là nguyên
     * nhân áp đảo ở đường export nên nó được nói riêng, kèm việc cần làm tiếp.
     *
     * @param  array{type:int, message:string, file:string, line:int}  $error
     */
    private static function humanMessage(array $error): string
    {
        if (str_contains($error['message'], 'Allowed memory size')) {
            return 'Tiến trình tạo file đã dùng hết bộ nhớ cho phép trước khi dựng xong. '
                .'Hãy thu hẹp bộ lọc (khoảng ngày ngắn hơn, chọn thêm kho/loại phiếu) rồi xuất lại.';
        }

        if (str_contains($error['message'], 'Maximum execution time')) {
            return 'Tiến trình tạo file chạy quá thời gian cho phép. '
                .'Hãy thu hẹp bộ lọc rồi xuất lại.';
        }

        return 'Tiến trình tạo file dừng đột ngột: '.$error['message'];
    }
}
