<?php

namespace Tests\Feature;

use App\Support\ReportPdfRowLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ReportExportJob;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-344 / TC_FUNC_04 — "Không thể tải file XLSX và PDF sau khi thực hiện xuất báo cáo"
 * ở màn Báo cáo → Nhập dữ liệu / đối chiếu & lỗi dữ liệu (RPT-04, `report_code=import-match`).
 *
 * Màn này xuất qua `AlignedImportMatchReportController` — một trong ba báo cáo "aligned"
 * tự dựng bytes trong controller riêng, KHÔNG đi qua `OperationalReportController`. Vì
 * vậy nó nằm ngoài tầm che của các hàng rào đã dựng cho những báo cáo khác, và bảng của
 * nó lại là bảng RỘNG NHẤT hệ thống (22 cột). Ba lỗ hổng cộng dồn, dựng lại được 100%
 * trên stack dev 09/09/2026:
 *
 *  1. KHÔNG CÓ WORKER thì job nằm PENDING vĩnh viễn (xử lý ở WWHN-343).
 *  2. ĐỊNH DẠNG BỊ BỎ RƠI: `exportFilters()` cố ý loại `format` khỏi `filters`, nên
 *     $request mà job nền dựng lại không mang `format` và `exportPayload()` rơi về mặc
 *     định 'xlsx' (xử lý ở WWHN-346).
 *  3. NHÁNH PDF KHÔNG CÓ TRẦN — phần của ticket này. Bộ dựng PDF ở đây là bản chép tay
 *     với `border-collapse` + viền 4 cạnh trên MỌI ô, không nâng `memory_limit` (base
 *     image để 128M) và không có trần dòng riêng cho PDF. Đo trên đúng bảng 22 cột này,
 *     trần 512M:
 *
 *         367 dòng, template cũ      → HẾT BỘ NHỚ (Dompdf Frame.php:803)
 *         367 dòng, template rút gọn → 232 MB / 5,4 s, ra PDF thật 395 KB
 *
 *     "Allowed memory size exhausted" là PHP fatal, KHÔNG phải Throwable, nên
 *     `catch (Throwable)` của ReportExportJobRunner không bắt được: tiến trình dựng file
 *     chết tại chỗ và bản ghi `report_export_jobs` nằm lại PROCESSING với error_code=null.
 *     Đúng "Actual Result" của tester: không có file, cũng không có thông báo lỗi.
 *
 * Bộ test này ghim ba điều mà Expected Result của ticket đòi: file tải về ĐÚNG định dạng
 * người dùng chọn, dữ liệu trong file theo ĐÚNG bộ lọc màn hình, và khi không xuất được
 * thì phải là thất bại NHÌN THẤY ĐƯỢC (FAILED + error_code + error_message) chứ không
 * phải một job treo im lặng.
 */
class Wwhn344ImportMatchExportFormatTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $this->user = User::create([
            'username' => 'wwhn344-admin', 'display_name' => 'WWHN-344 Admin',
            'email' => 'wwhn344@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $this->user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->token = JWTAuth::fromUser($this->user);

        $this->seedImportBatch('CONSUMED', 3);
        $this->seedImportBatch('REJECTED', 2);
    }

    /**
     * Bằng chứng hồi quy chính: bấm "Xuất PDF" thì file tải về phải là PDF THẬT.
     *
     * Kiểm bằng magic bytes chứ không bằng đuôi tên file hay `format` trong JSON — hai
     * thứ đó "đúng" suốt thời gian lỗi còn sống nên chúng không chứng minh được gì.
     */
    public function test_pdf_export_job_delivers_a_real_pdf_not_an_xlsx(): void
    {
        Storage::fake('local');

        $jobId = $this->createExportJob('pdf');
        $this->settledStatus($jobId)
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.format', 'pdf')
            ->assertJsonPath('data.error_code', null);

        $download = $this->getJson("/api/v1/reports/export/{$jobId}/download", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();

        $this->assertStringContainsString(
            'application/pdf',
            (string) $download->headers->get('content-type'),
            'Người dùng chọn PDF thì Content-Type phải là application/pdf.'
        );
        $this->assertStringContainsString(
            '.pdf',
            (string) $download->headers->get('content-disposition'),
            'Tên file tải về phải mang đuôi .pdf.'
        );
        $this->assertStringStartsWith(
            '%PDF',
            $download->streamedContent(),
            'File phải là PDF thật; trước bản vá chỗ này là "PK" (ZIP của XLSX), rồi sau đó là job treo.'
        );
    }

    /** Nhánh XLSX phải giữ nguyên hành vi cũ — bản vá nhánh PDF không được làm lệch nó. */
    public function test_xlsx_export_job_still_delivers_a_real_xlsx(): void
    {
        Storage::fake('local');

        $jobId = $this->createExportJob('xlsx');
        $this->settledStatus($jobId)
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.format', 'xlsx');

        $download = $this->getJson("/api/v1/reports/export/{$jobId}/download", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $download->headers->get('content-type'));
        $this->assertStringStartsWith("PK\x03\x04", $download->streamedContent());
    }

    /**
     * Expected Result của ticket: "Không được để export job tồn tại ở trạng thái PENDING
     * vô thời hạn mà không có kết quả hoặc thông báo lỗi."
     *
     * Vượt trần PDF là ca thất bại DUY NHẤT dựng lại được một cách xác định, và nó chính
     * là ca đã giết tiến trình dựng file trước bản vá.
     */
    public function test_pdf_export_beyond_the_cell_budget_fails_loudly_instead_of_hanging(): void
    {
        Storage::fake('local');
        // Hạ NGÂN SÁCH Ô (không phải trần dòng) để khỏi phải seed hàng trăm dòng: đây
        // đúng là hàng rào mà bảng 22 cột chạm trước, và đường đi của mã là một.
        config()->set('wms_export.pdf_cell_limit', 22);

        $status = $this->settledStatus($this->createExportJob('pdf'));

        $status->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.error_code', 'REPORT_EXPORT_PDF_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.download_url', null);

        $this->assertNotEmpty(
            $status->json('data.error_message'),
            'FE hiển thị nguyên văn error_message, nên nó không được rỗng.'
        );
        $this->assertStringContainsString(
            'XLSX',
            (string) $status->json('data.error_message'),
            'Thông báo phải chỉ ra lối đi tiếp, không chỉ báo là hỏng.'
        );
    }

    /** Trần XLSX (10.000 dòng) KHÔNG được kéo theo trần PDF — hai hợp đồng khác nhau. */
    public function test_xlsx_is_not_subject_to_the_pdf_limits(): void
    {
        Storage::fake('local');
        config()->set('wms_export.pdf_cell_limit', 22);
        config()->set('wms_export.pdf_row_limit', 1);

        $this->settledStatus($this->createExportJob('xlsx'))->assertJsonPath('data.status', 'READY');
    }

    /**
     * Trần thực tế phải co theo SỐ CỘT: Dompdf tốn bộ nhớ theo Ô, nên cùng một trần dòng
     * áp cho bảng rộng hơn là vượt ngân sách đã đo mà không ai thấy. Bảng của RPT-04 là
     * bảng rộng nhất (22 cột) nên nó chạm ngân sách trước khi chạm trần dòng.
     */
    public function test_pdf_row_limit_tightens_for_the_22_column_table(): void
    {
        config()->set('wms_export.pdf_row_limit', 800);
        config()->set('wms_export.pdf_cell_limit', 16000);

        $this->assertSame(800, ReportPdfRowLimit::limitFor(17), 'Bảng 17 cột đã hiệu chuẩn ở WWHN-347: không đổi.');
        $this->assertSame(800, ReportPdfRowLimit::limitFor(20), 'Bảng 20 cột đã hiệu chuẩn ở WWHN-345: không đổi.');
        $this->assertSame(800, ReportPdfRowLimit::limitFor(), 'Không khai số cột = giữ nguyên hành vi cũ.');
        $this->assertSame(727, ReportPdfRowLimit::limitFor(22), 'Bảng 22 cột của RPT-04 phải bị siết theo ngân sách ô.');
    }

    /**
     * "Dữ liệu trong file phải tương ứng với dữ liệu/điều kiện lọc trên màn hình"
     * (BR-EXP-01). Job giữ nguyên filter đã gửi, nên số dòng của file phải đổi theo bộ
     * lọc chứ không phải luôn là toàn bộ dữ liệu.
     */
    public function test_export_row_count_follows_the_screen_filters(): void
    {
        Storage::fake('local');

        $all = (int) $this->settledStatus($this->createExportJob('xlsx'))->json('data.row_count');
        $consumedOnly = (int) $this->settledStatus(
            $this->createExportJob('xlsx', ['status' => 'CONSUMED'])
        )->json('data.row_count');

        $this->assertSame(5, $all, 'Không lọc: cả hai batch (3 + 2 dòng).');
        $this->assertSame(3, $consumedOnly, 'Lọc CONSUMED: chỉ batch 3 dòng.');
    }

    /** @param  array<string, string>  $extraFilters */
    private function createExportJob(string $format, array $extraFilters = []): string
    {
        $response = $this->postJson('/api/v1/reports/export', [
            'report_code' => 'import-match',
            'format' => $format,
            'filters' => array_merge([
                'from_date' => now()->subMonth()->toDateString(),
                'to_date' => now()->toDateString(),
            ], $extraFilters),
        ], [
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(202);

        return (string) $response->json('data.export_job_id');
    }

    /**
     * Bộ test chạy `QUEUE_CONNECTION=sync` nên job đã chạy xong ngay lúc dispatch; lần
     * đọc trạng thái này chỉ để lấy kết quả cuối, không phải một vòng poll thật.
     */
    private function settledStatus(string $jobId): TestResponse
    {
        $response = $this->getJson("/api/v1/reports/export/{$jobId}", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();

        $this->assertContains(
            $response->json('data.status'),
            ['READY', 'FAILED'],
            'Job phải kết thúc ở một trạng thái CUỐI; PENDING kéo dài chính là lỗi của ticket này.'
        );
        $this->assertNotNull(ReportExportJob::query()->find($jobId));

        return $response;
    }

    /** Một batch Excel + N dòng toa — đủ để báo cáo sinh ra đúng N dòng ở tầng dòng SKU. */
    private function seedImportBatch(string $status, int $rowCount): void
    {
        $importId = (string) Str::uuid();
        $suffix = strtolower($status);

        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => 'IMP-344-'.$suffix,
            'source_file_name' => 'toa-hang-'.$suffix.'.xlsx',
            'source_checksum' => str_pad($suffix, 64, '0'),
            'source_key' => 'wwhn344/'.$suffix,
            'status' => $status,
            'total_rows' => $rowCount,
            'valid_rows' => $status === 'REJECTED' ? 0 : $rowCount,
            'error_rows' => $status === 'REJECTED' ? $rowCount : 0,
            'created_by' => $this->user->user_id,
            'uploaded_by' => $this->user->user_id,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'uploaded_at' => now()->subDays(2),
            'version' => 0,
        ]);

        for ($i = 1; $i <= $rowCount; $i++) {
            DB::table('outbound_request_import_rows')->insert([
                'id' => (string) Str::uuid(),
                'import_id' => $importId,
                'row_no' => $i,
                'validation_status' => $status === 'REJECTED' ? 'INVALID' : 'VALID',
                'raw_payload' => json_encode(['sku_code' => 'SKU-344-'.$i, 'required_qty' => $i]),
                'normalized_payload' => json_encode([
                    'request_no' => 'TOA-344-'.$suffix,
                    'sku_code' => 'SKU-344-'.$i,
                    'required_qty' => $i,
                ]),
                'created_at' => now()->subDays(2),
            ]);
        }
    }
}
