<?php

namespace Tests\Feature;

use App\Support\ReportPdfRowLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Support\ReportExportFileStore;
use Modules\Warehouse\Support\ReportExportJobRunner;
use Tests\TestCase;

/**
 * WWHN-346 · UAT TC_FUNC_07 — "Export job bị treo ở PENDING, không tải được file"
 * ở màn Báo cáo → Bảo hành & linh kiện.
 *
 * Ba lỗi ĐỘC LẬP cùng nằm trên một đường đi, mỗi lỗi tự nó đủ để tái hiện ticket:
 *
 *  1. `Auth::guard('api')->setUser(null)` trong khối `finally` khôi phục guard.
 *     Worker `queue:work` và lệnh `report-export:reap` chạy NGOÀI mọi request nên
 *     guard chưa có ai — `JWTGuard::setUser()` khai tham số non-nullable nên ném
 *     TypeError ngay trong `finally`, nuốt mất kết quả export đã dựng xong và chốt
 *     job FAILED kèm một câu tiếng Anh của PHP. Triệu chứng quan sát được ngày
 *     09/09/2026: job ĐẦU TIÊN sau mỗi lần worker khởi động lại luôn hỏng, job kế
 *     tiếp lại chạy được — vì lúc đó guard còn giữ user của job trước.
 *
 *  2. `OperationalReportController::exportBytesFor()` BỎ RƠI tham số `$format` ở
 *     nhánh 3 báo cáo aligned (warranty-defects, import-match, label-log). Ba
 *     controller đó đọc định dạng từ `$request->query('format')`, nhưng job nền giữ
 *     định dạng ở CỘT `report_export_jobs.format` — không nằm trong `filters` — nên
 *     `exportPayload()` luôn rơi về mặc định 'xlsx'. Bấm "Xuất PDF" tải về một file
 *     Excel đặt tên .xlsx, mà job vẫn READY nên không có lỗi nào lộ ra.
 *
 *  3. Nhánh aligned không đi qua `ReportExportMemory::raise()` (nó chỉ nằm trong hai
 *     builder dùng chung của OperationalReportController), nên Dompdf dựng file dưới
 *     trần 128M của base image. Đo trên chính báo cáo này: 181 dòng × 14 cột = 134,5
 *     MB → fatal "Allowed memory size exhausted". Fatal KHÔNG phải Throwable nên
 *     không nhánh catch nào chốt được job, bản ghi kẹt lại PROCESSING vĩnh viễn.
 *
 * Test chạy runner TRỰC TIẾP (không qua HTTP) để dựng đúng bối cảnh của worker:
 * guard `api` rỗng. Đi qua `actingAs()` sẽ luôn có sẵn actor và lỗi (1) biến mất.
 */
class Wwhn346WarrantyExportFormatTest extends TestCase
{
    use RefreshDatabase;

    private const FILTERS = ['from_date' => '2026-06-01', 'to_date' => '2026-09-09'];

    /**
     * Lỗi (2) + (3): định dạng đã chọn phải tới được bộ dựng file.
     *
     * Khoá bằng MAGIC BYTES chứ không chỉ bằng đuôi tên file: bản lỗi vẫn đặt tên
     * đúng ở một số nhánh, chỉ nội dung là sai — đúng kiểu lỗi mà tester chỉ phát
     * hiện được khi mở file tải về.
     */
    public function test_job_nen_format_pdf_sinh_ra_file_pdf_that(): void
    {
        Storage::fake('local');
        $job = $this->makeJob('pdf');

        $this->assertTrue(ReportExportJobRunner::claim($job->export_job_id));
        ReportExportJobRunner::run($job->refresh());

        $job->refresh();
        $this->assertSame('READY', $job->status, 'Job PDF phải chốt READY. error='.$job->error_message);
        $this->assertStringEndsWith('.pdf', (string) $job->file_name);

        $bytes = ReportExportFileStore::read($job);
        $this->assertStringStartsWith(
            '%PDF',
            (string) $bytes,
            'Bấm "Xuất PDF" nhưng nội dung file không phải PDF — $format lại bị bỏ rơi ở nhánh aligned.',
        );
    }

    /** Nhánh XLSX phải giữ nguyên hành vi cũ (chữ ký ZIP "PK" của Office Open XML). */
    public function test_job_nen_format_xlsx_van_sinh_ra_file_xlsx(): void
    {
        Storage::fake('local');
        $job = $this->makeJob('xlsx');

        $this->assertTrue(ReportExportJobRunner::claim($job->export_job_id));
        ReportExportJobRunner::run($job->refresh());

        $job->refresh();
        $this->assertSame('READY', $job->status, 'error='.$job->error_message);
        $this->assertStringEndsWith('.xlsx', (string) $job->file_name);
        $this->assertStringStartsWith('PK', (string) ReportExportFileStore::read($job));
    }

    /**
     * Lỗi (1) đóng lại: chạy trong bối cảnh worker (guard rỗng) phải xong xuôi, và
     * KHÔNG được chốt FAILED bằng thông điệp TypeError của PHP.
     */
    public function test_chay_trong_boi_canh_worker_guard_rong_van_hoan_tat(): void
    {
        Storage::fake('local');
        Auth::forgetGuards();
        $this->assertNull(Auth::guard('api')->user(), 'Tiền đề của test: guard phải rỗng như trong worker.');

        $job = $this->makeJob('xlsx');
        ReportExportJobRunner::claim($job->export_job_id);
        ReportExportJobRunner::run($job->refresh());

        $job->refresh();
        $this->assertSame('READY', $job->status);
        $this->assertNull($job->error_code);
        $this->assertStringNotContainsStringIgnoringCase(
            'setUser',
            (string) $job->error_message,
            'Lỗi khôi phục guard lại rò ra thành thông điệp lỗi của người dùng.',
        );
    }

    /**
     * Hệ quả thứ hai của lỗi (1): guard KHÔNG được giữ lại actor của job vừa xong.
     * Worker chạy nhiều job nối nhau trong CÙNG một tiến trình — job sau sẽ khởi
     * động dưới danh tính người khác, và warehouse data-scope (WWHN-254) lẫn PII
     * gating bảo hành (WWHN-258 §13.8) đều suy ra từ đúng danh tính đó.
     */
    public function test_guard_khong_giu_lai_actor_cua_job_vua_chay(): void
    {
        Storage::fake('local');
        Auth::forgetGuards();

        $job = $this->makeJob('xlsx');
        ReportExportJobRunner::claim($job->export_job_id);
        ReportExportJobRunner::run($job->refresh());

        $this->assertNull(
            Auth::guard('api')->user(),
            'Actor của job vừa chạy còn nằm trong guard — job kế tiếp sẽ mang nhầm danh tính.',
        );
    }

    /**
     * Không dựng lại được actor thì KHÔNG được xuất file: cả data-scope lẫn PII
     * gating đều suy ra từ actor, nên xuất "không scope" nguy hiểm hơn báo lỗi.
     * Phải là FAILED có error_code + error_message — không được nằm lại PENDING.
     */
    public function test_actor_khong_ton_tai_thi_that_bai_co_ma_loi_ro_rang(): void
    {
        Storage::fake('local');
        $job = ReportExportJob::query()->create([
            'export_job_id' => (string) Str::uuid(),
            'report_code' => 'warranty-defects',
            'format' => 'xlsx',
            'filters' => self::FILTERS,
            // Actor hợp lệ về hình thức nhưng không còn trong bảng users.
            'requested_by' => (string) Str::uuid(),
            'status' => 'PENDING',
        ]);

        ReportExportJobRunner::claim($job->export_job_id);
        ReportExportJobRunner::run($job->refresh());

        $job->refresh();
        $this->assertSame('FAILED', $job->status);
        $this->assertSame(ReportExportJobRunner::ERROR_ACTOR_UNRESOLVED, $job->error_code);
        $this->assertNotEmpty($job->error_message);
        $this->assertNull($job->file_path, 'Không được để lại file cho một lần export đã bị từ chối.');
    }

    /**
     * Hàng rào tĩnh cho trần dòng PDF. Bộ test chạy trên dữ liệu rỗng nên không vượt
     * nổi ngưỡng thật; lớp này đọc thẳng mã nguồn để bắt việc ai đó gỡ hàng rào khỏi
     * MỘT trong HAI đường xuất (endpoint HTTP export() và job nền exportPayload()) —
     * thiếu ở đường nào thì đường đó lại chết fatal im lặng vì Dompdf hết bộ nhớ.
     *
     * Trần phải đi qua `ReportPdfRowLimit` dùng chung (kèm số cột, vì Dompdf tốn bộ nhớ
     * theo Ô chứ không theo dòng) chứ không phải một bản sao riêng của controller này:
     * ba báo cáo aligned phải cùng một trần và cùng một mã lỗi để FE chỉ hiểu một hợp
     * đồng.
     */
    public function test_ca_hai_duong_xuat_bao_hanh_deu_gac_tran_dong_pdf(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php')
        );

        $this->assertSame(
            2,
            substr_count($source, 'ReportPdfRowLimit::assert($rows, count($headers));'),
            'export() và exportPayload() đều phải gác trần dòng PDF trước khi gọi Dompdf.',
        );
        $this->assertSame(
            800,
            ReportPdfRowLimit::limitFor(14),
            'Bảng bảo hành 14 cột nằm trong ngân sách ô, nên giữ đúng trần công bố.',
        );
    }

    /**
     * Hàng rào tĩnh cho lỗi (3): nhánh aligned của exportBytesFor() phải tự nâng trần
     * bộ nhớ, vì nó KHÔNG đi qua buildXlsxBytes()/buildPdfBytes() — hai chỗ duy nhất
     * còn lại có ReportExportMemory::raise().
     */
    public function test_nhanh_aligned_tu_nang_tran_bo_nho(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php')
        );
        $aligned = (string) strstr($source, 'if (isset(self::ALIGNED_REPORTS[$reportCode])) {');
        $aligned = substr($aligned, 0, (int) strpos($aligned, 'return match ($reportCode)'));

        $this->assertStringContainsString('ReportExportMemory::raise();', $aligned);
        $this->assertStringContainsString("'format' => \$format", $aligned);
    }

    private function makeJob(string $format): ReportExportJob
    {
        $user = User::create([
            'username' => 'wwhn346_'.$format,
            'display_name' => 'WWHN-346 '.$format,
            'email' => "wwhn346-{$format}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        return ReportExportJob::query()->create([
            'export_job_id' => (string) Str::uuid(),
            'report_code' => 'warranty-defects',
            'format' => $format,
            'filters' => self::FILTERS,
            'requested_by' => $user->user_id,
            'status' => 'PENDING',
        ]);
    }
}
