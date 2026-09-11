<?php

namespace Modules\Warehouse\Support;

use App\Support\ReportExportFatalGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\OperationalReportController;
use Modules\Warehouse\Models\ReportExportJob;
use Throwable;

/**
 * WWHN-343. Toàn bộ vòng đời của một `report_export_jobs` nằm ở ĐÂY, không nằm trong
 * GenerateReportExportJob nữa, vì cùng một job có thể được chạy bởi HAI tiến trình khác
 * nhau:
 *
 *   1. Worker `queue:work` nhặt GenerateReportExportJob (đường chính, nhanh).
 *   2. Chính request `GET /reports/export/{id}` hoặc lệnh `report-export:reap` chạy
 *      thẳng trong tiến trình của mình khi worker KHÔNG nhặt job trong thời gian ân hạn
 *      (đường cứu hộ).
 *
 * VÌ SAO CẦN ĐƯỜNG CỨU HỘ (WWHN-343 / TC_FUNC_03): `QUEUE_CONNECTION=redis` nên
 * `dispatch()` chỉ ĐẨY job vào Redis rồi trả về ngay. Nếu container `queue` không chạy
 * (deploy quên, worker chết, OOM…), bản ghi nằm nguyên ở PENDING VĨNH VIỄN: API vẫn trả
 * 200 + `status=PENDING`, `download_url=null`, `error_code=null` nên FE không tải được
 * file mà cũng không có gì để báo cho người dùng. Đúng triệu chứng tester ghi trong
 * WWHN-343 — dựng lại được 100% bằng cách tắt container `queue`.
 *
 * BẤT BIẾN: một job chỉ được chạy MỘT lần. Mọi tiến trình phải giành quyền chạy qua
 * claim() — một câu UPDATE có điều kiện `status='PENDING'`, nguyên tử ở tầng DB — nên
 * worker và đường cứu hộ có chạy chồng nhau cũng chỉ một bên thắng, bên kia bỏ qua.
 */
class ReportExportJobRunner
{
    /** Job chờ worker quá lâu — đã hết ân hạn thì tiến trình đang đọc tự chạy nó. */
    public const ERROR_STALLED = 'REPORT_EXPORT_STALLED';

    /**
     * WWHN-346: không dựng lại được actor đã submit (user bị xoá/khoá, hoặc
     * `requested_by` rỗng) nên không áp được warehouse scope + PII gating.
     */
    public const ERROR_ACTOR_UNRESOLVED = 'REPORT_EXPORT_ACTOR_UNRESOLVED';

    /**
     * Job READY, còn hạn, nhưng tiến trình phục vụ tải về không đọc được nội dung file ở
     * đâu cả và không được phép dựng lại — xem recoverMissingFile() / markFileMissing().
     */
    public const ERROR_FILE_MISSING = 'REPORT_EXPORT_FILE_MISSING';

    /**
     * Giành quyền chạy job: PENDING -> PROCESSING, nguyên tử.
     *
     * Trả `true` cho ĐÚNG MỘT tiến trình. Ai nhận `false` nghĩa là job đã được tiến
     * trình khác nhận (hoặc đã READY/FAILED) — không được chạy tiếp, nếu không cùng một
     * export chạy hai lần và ghi đè kết quả của nhau.
     */
    public static function claim(string $exportJobId): bool
    {
        return ReportExportJob::query()
            ->where('export_job_id', $exportJobId)
            ->where('status', 'PENDING')
            ->update(['status' => 'PROCESSING', 'updated_at' => now()]) === 1;
    }

    /**
     * Chạy phần nặng (query + dựng XLSX/PDF + lưu nội dung) rồi chốt job READY/FAILED.
     *
     * CHỈ gọi sau khi claim() (hoặc recoverMissingFile()) giành được quyền chạy. Hàm KHÔNG
     * ném lỗi ra ngoài: mọi Throwable đều được chuyển thành FAILED + error_code +
     * error_message, vì người gọi có thể là một request HTTP đang poll trạng thái — ném lỗi
     * ở đó sẽ biến câu trả lời poll thành 500 và lại giấu mất nguyên nhân thật của lần export.
     *
     * `$storeTarget` null = lưu vào nơi đang cấu hình (`wms_export.disk`); recoverMissingFile()
     * ép `database` — lý do ở đó.
     */
    public static function run(ReportExportJob $job, ?string $storeTarget = null): void
    {
        // WWHN-347: `catch (Throwable)` bên dưới KHÔNG bắt được lỗi fatal của PHP (hết bộ
        // nhớ, quá thời gian) — tiến trình chết thẳng và job nằm lại PROCESSING với
        // error_code=null. Guard đăng ký shutdown function để vẫn chốt được FAILED kèm lý
        // do trong đúng tình huống đó.
        ReportExportFatalGuard::watch($job->export_job_id, $job->report_code);

        try {
            $user = $job->requested_by !== null ? User::find($job->requested_by) : null;

            // WWHN-346 fail-closed: cả warehouse data-scope (WWHN-254) lẫn PII gating
            // bảo hành (WWHN-258, §13.8) đều suy ra TỪ actor. Chạy tiếp khi không dựng
            // lại được actor nghĩa là xuất một file KHÔNG áp scope nào — sai nguy hiểm
            // hơn hẳn việc báo lỗi. (Trước đây dòng setUser() bên dưới nhận null và ném
            // TypeError, nên lỗi tới tay người dùng dưới dạng thông báo PHP khó hiểu.)
            if ($user === null) {
                self::fail(
                    $job,
                    self::ERROR_ACTOR_UNRESOLVED,
                    'Không xác định được người yêu cầu export nên không áp được phạm vi kho/PII. '
                        .'Vui lòng đăng nhập lại rồi xuất báo cáo lần nữa.'
                );

                return;
            }

            // Đóng vai actor đã submit — cả hai dòng dưới đây đều cần: một số builder
            // đọc $request->user(), một số khác (ví dụ reportWarehouseScope()) đọc thẳng
            // auth('api')->user(). Thiếu một trong hai sẽ âm thầm bỏ qua warehouse
            // data-scope (WWHN-254) hoặc PII gating bảo hành (WWHN-258, §13.8).
            $previousUser = Auth::guard('api')->user();
            $request = Request::create('/', 'GET', $job->filters ?? []);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('request_id', 'export-job:'.$job->export_job_id);
            Auth::guard('api')->setUser($user);

            try {
                $payload = app(OperationalReportController::class)
                    ->exportBytesFor($job->report_code, $request, $job->format);
            } finally {
                self::restoreGuardUser($previousUser);
            }

            $extension = $job->format === 'pdf' ? 'pdf' : 'xlsx';
            $storedPath = 'report-exports/'.$job->export_job_id.'.'.$extension;
            $expiresAt = now()->addHours(24);

            // Lưu hỏng thì store NÉM lỗi — kể cả khi `put()` của ổ đĩa chỉ lặng lẽ trả
            // `false` — nên nhánh catch bên dưới chốt FAILED kèm nguyên nhân: job không bao
            // giờ READY cho một file chưa từng được lưu. Nơi lưu mặc định là database, dùng
            // chung cho mọi tiến trình — xem ReportExportFileStore.
            ReportExportFileStore::put($job, $storedPath, (string) $payload['bytes'], $expiresAt, $storeTarget);

            $job->forceFill([
                'status' => 'READY',
                'row_count' => $payload['rowCount'],
                'file_path' => $storedPath,
                'file_name' => $payload['fileName'],
                'error_code' => null,
                'error_message' => null,
                'expires_at' => $expiresAt,
            ])->save();

            ReportExportFileStore::pruneExpired();
        } catch (Throwable $e) {
            Log::error('Report export job failed', [
                'export_job_id' => $job->export_job_id,
                'report_code' => $job->report_code,
                'exception' => $e,
            ]);

            self::fail($job, self::errorCodeFor($e), self::errorMessageFor($e));
        }
    }

    /**
     * Trả guard `api` về đúng actor trước khi job đóng vai — WWHN-346.
     *
     * BA điều kiện, thiếu bất kỳ điều nào cũng đã gây lỗi thật:
     *
     *  1. `$previous === null` là chuyện BÌNH THƯỜNG, không phải bất thường: worker
     *     `queue:work` và lệnh `report-export:reap` chạy ngoài mọi request nên guard
     *     chưa có ai. `JWTGuard::setUser()` khai tham số non-nullable, nên gọi thẳng
     *     `setUser(null)` ném TypeError NGAY TRONG `finally` — nó nuốt mất kết quả
     *     export đã dựng xong và bản ghi bị chốt FAILED với thông điệp PHP khó hiểu.
     *     Đúng triệu chứng WWHN-346: job ĐẦU TIÊN sau mỗi lần worker khởi động lại
     *     luôn hỏng, job sau đó lại chạy được (vì guard còn giữ user của job trước).
     *  2. Vì (1), cũng KHÔNG được để guard giữ lại actor của job vừa xong: job kế
     *     tiếp trong cùng tiến trình worker sẽ khởi động dưới danh tính người khác.
     *     `forgetGuards()` đưa guard về trạng thái chưa giải quyết — lần đọc sau tự
     *     resolve lại từ token của request (nếu có), đúng cả ở web lẫn CLI.
     *  3. Khôi phục guard là việc DỌN DẸP, không bao giờ được phép làm hỏng một lần
     *     export đã thành công — nên mọi lỗi ở đây chỉ ghi log.
     */
    private static function restoreGuardUser(?Authenticatable $previous): void
    {
        try {
            if ($previous !== null) {
                Auth::guard('api')->setUser($previous);

                return;
            }

            Auth::forgetGuards();
        } catch (Throwable $e) {
            Log::warning('Không khôi phục được guard sau khi chạy export job', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Job READY, còn hạn, nhưng tiến trình đang phục vụ tải về KHÔNG đọc được nội dung file
     * ở đâu cả (database, rồi ổ đĩa).
     *
     * Trước đây trường hợp này chỉ trả 404 "có thể đã bị dọn dẹp" và để job nằm nguyên
     * READY: bấm tải bao nhiêu lần cũng ra đúng câu đó, log không để lại gì. Gặp ở cả hai
     * môi trường ngày 11/09/2026 — bản BE build 07/09 ghi hỏng mà không kiểm `put()`, còn
     * khohoanamfe.lptech.info.vn (đã có bản vá đó) thì file được ghi THÀNH CÔNG nhưng bởi
     * một tiến trình khác, lên ổ đĩa mà tiến trình tải về không nhìn thấy. File hết hạn đã
     * có nhánh riêng ("đã hết hạn") và lệnh purge null hoá `file_path`, nên tới được đây
     * nghĩa là nội dung chưa từng tới được nơi lưu mà tiến trình này đọc.
     *
     * Mặc định (`inline_fallback` bật — cùng công tắc với đường cứu hộ WWHN-343): dựng lại
     * file NGAY trong tiến trình này từ bộ lọc + actor đã lưu của job (BR-EXP-01 giữ nguyên
     * filter/scope; số liệu là số liệu tại lúc dựng lại) và lưu bản mới vào DATABASE bất kể
     * `wms_export.disk` — nơi lưu đang cấu hình vừa chứng minh là tiến trình này không đọc
     * được, ghi lại vào đó thì lần tải sau hỏng y hệt. Người dùng nhận file thay vì 404.
     *
     * Tắt `inline_fallback` (muốn mọi export đi qua worker): markFileMissing().
     *
     * Trả về bản ghi đã refresh: READY (đọc lại được qua ReportExportFileStore), FAILED (kèm
     * lý do), hoặc PROCESSING khi một request khác đang dựng lại đúng job này.
     */
    public static function recoverMissingFile(ReportExportJob $job): ReportExportJob
    {
        if (! (bool) config('wms_export.inline_fallback', true)) {
            return self::markFileMissing($job);
        }

        Log::warning('Report export file missing at download — rebuilding it in this process', self::fileContext($job));

        // Giành quyền dựng lại giống claim(): READY -> PROCESSING nguyên tử, để hai lần bấm
        // tải cùng lúc không dựng hai lần. Bên thua nhận PROCESSING ("chưa sẵn sàng"); tiến
        // trình dựng lại mà chết giữa chừng thì FatalGuard/ensureProgress() chốt như mọi job.
        $reclaimed = ReportExportJob::query()
            ->where('export_job_id', $job->export_job_id)
            ->where('status', 'READY')
            ->update(['status' => 'PROCESSING', 'updated_at' => now()]) === 1;

        if ($reclaimed) {
            self::run($job->refresh(), ReportExportFileStore::DATABASE);
        }

        return $job->refresh();
    }

    /**
     * Chốt FAILED + REPORT_EXPORT_FILE_MISSING cho job READY mà nội dung file không đọc được ở
     * đâu cả và không dựng lại được — để lần poll sau không còn trả READY + download_url cho
     * một file không tồn tại. Log đủ nơi lưu/đường dẫn/máy để đối chiếu với log "Report export
     * file stored" lúc ghi.
     */
    public static function markFileMissing(ReportExportJob $job): ReportExportJob
    {
        Log::error('Report export file missing at download', self::fileContext($job));

        self::fail(
            $job,
            self::ERROR_FILE_MISSING,
            'Máy chủ không tìm thấy file của lần xuất này (file chưa được lưu thành công hoặc máy chủ '
                .'không đọc được nơi lưu file). Vui lòng bấm xuất lại; nếu lỗi lặp lại, báo quản trị viên '
                .'kiểm tra nơi lưu file xuất báo cáo.'
        );

        return $job->refresh();
    }

    /** Chốt job ở trạng thái lỗi — luôn kèm error_code + error_message để FE nói được lý do. */
    public static function fail(ReportExportJob $job, string $errorCode, string $errorMessage): void
    {
        $job->forceFill([
            'status' => 'FAILED',
            'error_code' => $errorCode,
            // Cột `error_message` là TEXT nhưng thông điệp exception có thể dài vô hạn
            // (stack của driver DB chẳng hạn) và FE hiển thị nguyên văn trong toast.
            'error_message' => mb_substr($errorMessage, 0, 1000),
        ])->save();
    }

    /**
     * Bánh đà chống "PENDING vô thời hạn" (Expected Result của WWHN-343). Gọi ở mọi chỗ
     * ĐỌC trạng thái job — cả `GET /reports/export/{id}` lẫn `report-export:reap` — nên
     * hệ thống tự lành kể cả khi không có worker lẫn scheduler.
     *
     * Trả về bản ghi đã cập nhật (đã refresh) để người gọi trả thẳng ra response.
     */
    public static function ensureProgress(ReportExportJob $job): ReportExportJob
    {
        if (in_array($job->status, ['READY', 'FAILED'], true)) {
            return $job;
        }

        if ($job->status === 'PENDING') {
            $grace = (int) config('wms_export.pickup_grace_seconds', 20);
            if ($job->created_at === null || $job->created_at->addSeconds($grace)->isFuture()) {
                // Còn trong ân hạn — worker vẫn đang có cơ hội nhặt job, để yên.
                return $job;
            }

            if (! (bool) config('wms_export.inline_fallback', true)) {
                self::fail(
                    $job,
                    self::ERROR_STALLED,
                    'Hệ thống xử lý export nền không nhận việc sau '.$grace.' giây. '
                        .'Vui lòng thử xuất lại; nếu vẫn lỗi, báo quản trị viên kiểm tra worker hàng đợi.'
                );

                return $job->refresh();
            }

            Log::warning('Report export job stalled in PENDING — chạy đường cứu hộ đồng bộ', [
                'export_job_id' => $job->export_job_id,
                'report_code' => $job->report_code,
                'pending_seconds' => (int) $job->created_at->diffInSeconds(now()),
            ]);

            if (self::claim($job->export_job_id)) {
                self::run($job->refresh());
            }

            return $job->refresh();
        }

        // PROCESSING: worker/đường cứu hộ đã nhận job nhưng chết giữa chừng (timeout,
        // OOM, container bị kill) — không ai còn ghi kết quả vào bản ghi này nữa.
        $timeout = (int) config('wms_export.processing_timeout_seconds', 180);
        if ($job->updated_at !== null && $job->updated_at->addSeconds($timeout)->isPast()) {
            self::fail(
                $job,
                self::ERROR_STALLED,
                'Tiến trình tạo file export dừng giữa chừng (quá '.$timeout.' giây không phản hồi). '
                    .'Vui lòng thử xuất lại với bộ lọc hẹp hơn.'
            );

            return $job->refresh();
        }

        return $job;
    }

    /**
     * Ngữ cảnh log chung của hai nhánh "không đọc được file": nơi lưu đang cấu hình, đường
     * dẫn và MÁY đang phục vụ tải về — so với log "Report export file stored" lúc ghi là
     * biết ngay hai tiến trình có nhìn cùng một nơi lưu hay không.
     *
     * @return array<string, mixed>
     */
    private static function fileContext(ReportExportJob $job): array
    {
        return [
            'export_job_id' => $job->export_job_id,
            'report_code' => $job->report_code,
            'target' => ReportExportFileStore::target(),
            'path' => $job->file_path,
            'host' => gethostname() ?: null,
            'ready_since' => $job->updated_at?->toIso8601String(),
        ];
    }

    /**
     * `ApiError::abort()` (dùng trong các payload builder cho row-limit/PII/scope) ném
     * `HttpResponseException`/`HttpException` với payload JSON đã có `error.code` chuẩn;
     * job đứng ngoài vòng đời HTTP nên phải tự đọc lại field đó thay vì để mất mã lỗi cụ
     * thể, còn lại mọi lỗi khác đều là INTERNAL_ERROR.
     */
    private static function errorCodeFor(Throwable $e): string
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

    /**
     * WWHN-347. Song sinh với errorCodeFor(), cho phần `error_message`.
     *
     * `HttpResponseException` mang toàn bộ nội dung lỗi trong RESPONSE, còn
     * `getMessage()` của chính exception thì RỖNG. Dùng thẳng `$e->getMessage()` nên
     * `error_code` có mà `error_message` là chuỗi rỗng — FE nhận được mã lỗi nhưng không
     * có câu nào để hiển thị, nên rơi về thông báo chung chung "Xuất báo cáo thất bại"
     * đúng lúc BE đã biết chính xác phải khuyên người dùng làm gì (thu hẹp bộ lọc, đổi
     * sang XLSX). Quan sát được ở lần export PDF đầu tiên sau khi thêm trần dòng PDF.
     */
    private static function errorMessageFor(Throwable $e): string
    {
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if ($response !== null) {
                $decoded = json_decode((string) $response->getContent(), true);
                foreach ([$decoded['message'] ?? null, $decoded['error']['message'] ?? null] as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        return $candidate;
                    }
                }
            }
        }

        $message = trim($e->getMessage());

        return $message !== ''
            ? $message
            : 'Tạo file export thất bại ('.$e::class.').';
    }
}
