<?php

namespace Tests\Feature;

use App\Support\ReportExportMemory;
use App\Support\ReportPdfRowLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Support\ReportExportJobRunner;
use Tests\TestCase;

/**
 * WWHN-347 · UAT TC_FUNC_08 — "Lịch sử nhập - xuất kho": Xuất XLSX/PDF tạo job rồi
 * job nằm mãi ở PENDING, download_url=null, không FAILED, không error_code.
 *
 * LỖI GỐC (dựng lại được trên stack dev ngày 09/09/2026, report_code=document-history,
 * dữ liệu thật 06→09/2026 = 4.243 dòng × 17 cột):
 *
 *   - XLSX cần 130 MB, PDF cần > 2 GB, trong khi base image PHP để `memory_limit=128M`.
 *   - PHP fatal "Allowed memory size exhausted" KHÔNG phải Throwable, nên
 *     `catch (Throwable)` của ReportExportJobRunner::run() không bao giờ chạy tới
 *     nhánh fail(). Tiến trình chết tại chỗ (container `queue` thoát 139) và bản ghi
 *     `report_export_jobs` giữ nguyên trạng thái cũ với error_code = null.
 *   - Trần 10.000 dòng mà API công bố là KHÔNG THẬT ở nhánh PDF: Dompdf dựng một frame
 *     cho mỗi ô nên 2.000 dòng đã không xong trong 3 GB.
 *
 * BỘ TEST NÀY CHẠY SQLITE với dữ liệu nhỏ nên KHÔNG tái hiện được cú fatal thật (muốn
 * thấy phải chạy worker với `-d memory_limit=64M` trên dữ liệu thật). Vì vậy file gồm
 * hai lớp, giống Wwhn340WarrantyListFilterTest:
 *
 *   1. Lớp hành vi — khoá hợp đồng mà tester đọc được: vượt trần PDF là 422 có mã, job
 *      hỏng luôn kèm error_message KHÁC RỖNG, và trần bộ nhớ chỉ đi lên.
 *   2. Lớp hàng rào tĩnh — đọc chính mã nguồn và chặn việc quay lại các nguyên nhân gốc
 *      (setAutoSize, thiếu ReportExportMemory::raise(), thiếu ReportPdfRowLimit::assert(),
 *      gỡ ReportExportFatalGuard, dùng lại $e->getMessage() cho error_message).
 */
class Wwhn347ReportExportMemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $this->user = User::create([
            'username' => 'wwhn347-admin', 'display_name' => 'WWHN-347 Admin',
            'email' => 'wwhn347@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $this->user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
    }

    // ----------------------------------------------------------------- lớp hành vi

    /**
     * Bằng chứng hồi quy chính của nhánh PDF: quá trần thì người dùng nhận được MỘT CÂU
     * NÓI ĐƯỢC LÝ DO, không phải một job treo. Trước bản vá, đúng bộ lọc này giết tiến
     * trình dựng file.
     */
    public function test_pdf_over_the_row_limit_aborts_with_a_coded_422(): void
    {
        config(['wms_export.pdf_row_limit' => 10]);

        try {
            ReportPdfRowLimit::assert(array_fill(0, 11, ['x']));
            $this->fail('Vượt trần dòng PDF phải ném lỗi.');
        } catch (HttpResponseException $e) {
            $payload = json_decode((string) $e->getResponse()->getContent(), true);

            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('REPORT_EXPORT_PDF_ROW_LIMIT_EXCEEDED', $payload['error']['code']);
            // Câu chữ phải nêu cả trần lẫn con số thật và chỉ sang XLSX — đây là thứ
            // người dùng đọc trên toast, không phải mã lỗi.
            $this->assertStringContainsString('10', $payload['message']);
            $this->assertStringContainsString('11', $payload['message']);
            $this->assertStringContainsString('XLSX', $payload['message']);
        }
    }

    /** Dưới trần thì không được chặn — hàng rào chỉ để cứu, không để cản. */
    public function test_pdf_within_the_row_limit_is_not_blocked(): void
    {
        config(['wms_export.pdf_row_limit' => 10]);

        ReportPdfRowLimit::assert(array_fill(0, 10, ['x']));

        $this->assertTrue(true);
    }

    /**
     * Hợp đồng WWHN-347 với FE: job FAILED LUÔN có error_message khác rỗng.
     *
     * Đây là hồi quy cho một lỗi thật quan sát được ngày 09/09/2026: `ApiError::abort()`
     * ném HttpResponseException mà `getMessage()` của nó RỖNG (nội dung nằm trong
     * response), nên `fail($job, errorCodeFor($e), $e->getMessage())` ghi error_code
     * đúng nhưng error_message = "" — FE có mã lỗi mà không có câu nào để hiển thị.
     */
    public function test_failed_job_always_carries_a_non_empty_error_message(): void
    {
        $jobId = (string) Str::uuid();
        ReportExportJob::query()->create([
            'export_job_id' => $jobId,
            // report_code không nằm trong bảng export nền → exportBytesFor() abort 404.
            // Dùng nó để đi đúng nhánh HttpResponseException mà không cần dựng dữ liệu.
            'report_code' => 'khong-ton-tai',
            'format' => 'xlsx',
            'filters' => [],
            'requested_by' => $this->user->user_id,
            'status' => 'PROCESSING',
        ]);

        ReportExportJobRunner::run(ReportExportJob::query()->find($jobId));

        $job = ReportExportJob::query()->find($jobId);
        $this->assertSame('FAILED', $job->status);
        $this->assertNotNull($job->error_code);
        $this->assertNotSame('', trim((string) $job->error_message), 'error_message không được rỗng khi job FAILED.');
    }

    /**
     * ReportExportMemory chỉ được NÂNG trần. Nó còn chạy trong request HTTP thật (đường
     * cứu hộ inline của WWHN-343) và trong tiến trình test vốn có trần cao hơn — hạ trần
     * ở đó sẽ giết những tiến trình đang chạy bình thường.
     */
    public function test_memory_ceiling_is_never_lowered(): void
    {
        $before = ini_get('memory_limit');

        config(['wms_export.memory_limit' => '8M']);
        ReportExportMemory::raise();

        $this->assertSame(
            ReportExportMemory::toBytes((string) $before),
            ReportExportMemory::toBytes((string) ini_get('memory_limit')),
            'raise() không được hạ memory_limit xuống.'
        );
    }

    /** Trần rỗng = "giữ nguyên trần của tiến trình", không được hiểu thành 0 byte. */
    public function test_empty_memory_setting_is_a_no_op(): void
    {
        config(['wms_export.memory_limit' => '']);

        $this->assertFalse(ReportExportMemory::raise());
    }

    public function test_to_bytes_understands_php_ini_units(): void
    {
        $this->assertSame(512 * 1024 * 1024, ReportExportMemory::toBytes('512M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, ReportExportMemory::toBytes('2G'));
        $this->assertSame(64 * 1024, ReportExportMemory::toBytes('64K'));
        // -1 = không giới hạn: phải giữ nguyên nghĩa, nếu quy về 0 thì raise() sẽ tưởng
        // tiến trình đang chật và siết một tiến trình vốn không có trần.
        $this->assertSame(-1, ReportExportMemory::toBytes('-1'));
    }

    // ------------------------------------------------------------ lớp hàng rào tĩnh

    /**
     * setAutoSize() bắt PhpSpreadsheet đo bề rộng hiển thị của TỪNG ô (10.000 dòng ×
     * 17 cột = 170.000 phép đo). Bỏ nó rút 31% thời gian dựng file mà file mở ra không
     * khác gì — đừng để ai đưa lại vào vì "cột trông chật".
     */
    public function test_report_writers_do_not_use_autosize(): void
    {
        $source = file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'));

        $this->assertStringNotContainsString('->setAutoSize(', $source);
    }

    /**
     * Ba hàng rào phải nằm ĐÚNG trong hai hàm dựng bytes. Thiếu bất kỳ cái nào là quay
     * lại nguyên trạng WWHN-347: tiến trình chết vì hết bộ nhớ, job treo không mã lỗi.
     */
    public function test_byte_builders_keep_the_memory_and_pdf_guards(): void
    {
        $source = file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'));

        $xlsx = $this->methodBody($source, 'buildXlsxBytes');
        $pdf = $this->methodBody($source, 'buildPdfBytes');

        $this->assertStringContainsString('ReportExportMemory::raise()', $xlsx, 'buildXlsxBytes phải nâng trần bộ nhớ.');
        $this->assertStringContainsString('ReportExportMemory::raise()', $pdf, 'buildPdfBytes phải nâng trần bộ nhớ.');
        $this->assertStringContainsString('ReportPdfRowLimit::assert(', $pdf, 'buildPdfBytes phải chặn theo trần dòng PDF.');
    }

    /**
     * Guard bắt lỗi fatal là lớp DUY NHẤT chốt được FAILED khi tiến trình chết vì hết bộ
     * nhớ — `catch (Throwable)` ngay bên dưới nó không thấy loại lỗi này.
     */
    public function test_runner_arms_the_fatal_guard(): void
    {
        $source = file_get_contents(base_path('Modules/Warehouse/app/Support/ReportExportJobRunner.php'));

        $this->assertStringContainsString('ReportExportFatalGuard::watch(', $source);
        // error_message phải lấy qua errorMessageFor(): $e->getMessage() rỗng với
        // HttpResponseException nên FE mất câu giải thích.
        $this->assertStringContainsString('self::errorMessageFor($e)', $source);
        $this->assertStringNotContainsString('self::errorCodeFor($e), $e->getMessage()', $source);
    }

    /** Cắt thân một method ra khỏi mã nguồn để hàng rào tĩnh soi đúng phạm vi. */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        $this->assertNotFalse($start, "Không tìm thấy {$method}() — đổi tên thì phải sửa cả hàng rào này.");

        $next = strpos($source, "\n    private function ", $start + 10);
        $next = $next === false ? strlen($source) : $next;

        return substr($source, $start, $next - $start);
    }
}
