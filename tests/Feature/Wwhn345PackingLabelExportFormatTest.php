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
 * WWHN-345 / TC_FUNC_06 — "Export job không hoàn tất tại màn hình Nhãn đóng gói - In/In
 * lại, không tải được file".
 *
 * Màn RPT-05 xuất qua `report_code=label-log`, tức là một trong ba báo cáo "aligned" tự
 * dựng bytes trong controller riêng thay vì đi qua `OperationalReportController`. Hai lỗ
 * hổng đi kèm cách tổ chức đó, cả hai đều dựng lại được trên stack dev 09/09/2026:
 *
 *  1. ĐỊNH DẠNG BỊ BỎ RƠI. `exportFilters()` CỐ Ý loại `format` khỏi `filters`, nên
 *     $request mà job nền dựng lại không mang `format`; `exportPayload()` đọc query rồi
 *     rơi về mặc định 'xlsx'. Người dùng bấm "Xuất PDF" → job READY, `format=pdf`, nhưng
 *     file tải về là XLSX (`Content-Type: …spreadsheetml.sheet`, magic `PK\x03\x04`) đặt
 *     tên .xlsx. Không có lỗi nào lộ ra để mà lần theo.
 *
 *  2. NHÁNH PDF KHÔNG CÓ TRẦN. Hàng rào bộ nhớ/trần dòng của WWHN-347 nằm trong
 *     `OperationalReportController`, ba controller aligned không hưởng. Bảng RPT-05 rộng
 *     20 cột nên chỉ 166 dòng đã vượt `memory_limit` 128M: PHP fatal (KHÔNG phải Throwable)
 *     giết luôn tiến trình dựng file, bản ghi nằm lại PENDING/PROCESSING với
 *     error_code=null — và nếu tiến trình đó là worker `queue` thì mọi export sau cũng treo.
 *
 * Bộ test này ghim cả hai: định dạng tải về phải ĐÚNG thứ người dùng chọn, và khi vượt
 * trần thì phải là thất bại NHÌN THẤY ĐƯỢC (FAILED + error_code + error_message).
 */
class Wwhn345PackingLabelExportFormatTest extends TestCase
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
            'username' => 'wwhn345-admin', 'display_name' => 'WWHN-345 Admin',
            'email' => 'wwhn345@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $this->user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->token = JWTAuth::fromUser($this->user);

        $this->seedPackingLabels(3);
    }

    /**
     * Bằng chứng hồi quy chính của ticket: chọn PDF thì file tải về phải là PDF THẬT.
     *
     * Kiểm bằng magic bytes chứ không bằng đuôi tên file hay `format` trong JSON — chính
     * hai thứ đó đã "đúng" trong suốt thời gian lỗi còn sống, nên chúng không chứng minh
     * được điều gì.
     */
    public function test_pdf_export_job_delivers_a_real_pdf_not_an_xlsx(): void
    {
        Storage::fake('local');

        $jobId = $this->createExportJob('pdf');
        $status = $this->pollUntilSettled($jobId);

        $status->assertJsonPath('data.status', 'READY')
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
            'File phải là PDF thật; trước bản vá chỗ này là "PK" (ZIP của XLSX).'
        );
    }

    /** Nhánh XLSX phải giữ nguyên hành vi cũ — bản vá định dạng không được làm lệch nó. */
    public function test_xlsx_export_job_still_delivers_a_real_xlsx(): void
    {
        Storage::fake('local');

        $jobId = $this->createExportJob('xlsx');
        $this->pollUntilSettled($jobId)
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.format', 'xlsx');

        $download = $this->getJson("/api/v1/reports/export/{$jobId}/download", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $download->headers->get('content-type'));
        $this->assertStringStartsWith("PK\x03\x04", $download->streamedContent());
    }

    /**
     * Expected Result của ticket: "Nếu export thất bại, hệ thống phải chuyển job sang
     * trạng thái lỗi… Không được để export job tồn tại ở trạng thái PENDING kéo dài mà
     * không hoàn tất hoặc không trả lỗi."
     *
     * Vượt trần dòng PDF là ca thất bại DUY NHẤT có thể dựng lại một cách xác định, và nó
     * chính là ca đã giết tiến trình dựng file trước bản vá.
     */
    public function test_pdf_export_beyond_row_limit_fails_loudly_instead_of_hanging(): void
    {
        Storage::fake('local');
        // Trần hạ xuống 1 dòng để khỏi phải seed 800 nhãn chỉ để chạm ngưỡng; đường đi
        // của mã là một.
        config()->set('wms_export.pdf_row_limit', 1);

        $jobId = $this->createExportJob('pdf');
        $status = $this->pollUntilSettled($jobId);

        $status->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.error_code', 'REPORT_EXPORT_PDF_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.download_url', null);

        $this->assertNotEmpty(
            $status->json('data.error_message'),
            'FE hiển thị nguyên văn error_message, nên nó không được rỗng.'
        );
        $this->assertStringContainsString('XLSX', (string) $status->json('data.error_message'));
    }

    /** Trần XLSX (10.000 dòng) KHÔNG được kéo theo trần PDF — hai hợp đồng khác nhau. */
    public function test_xlsx_is_not_subject_to_the_pdf_row_limit(): void
    {
        Storage::fake('local');
        config()->set('wms_export.pdf_row_limit', 1);

        $jobId = $this->createExportJob('xlsx');
        $this->pollUntilSettled($jobId)->assertJsonPath('data.status', 'READY');
    }

    /**
     * Trần thực tế phải co theo SỐ CỘT: Dompdf tốn bộ nhớ theo ô, nên cùng một trần dòng
     * áp cho bảng rộng hơn là vượt ngân sách đã đo mà không ai thấy.
     */
    public function test_pdf_row_limit_tightens_for_wide_tables(): void
    {
        config()->set('wms_export.pdf_row_limit', 800);
        config()->set('wms_export.pdf_cell_limit', 16000);

        $this->assertSame(800, ReportPdfRowLimit::limitFor(20), 'Bảng 20 cột của RPT-05 = đúng mốc đã đo.');
        $this->assertSame(800, ReportPdfRowLimit::limitFor(0), 'Không khai số cột = giữ hành vi cũ.');
        $this->assertLessThan(800, ReportPdfRowLimit::limitFor(22), 'Bảng rộng hơn phải bị siết lại.');
    }

    private function createExportJob(string $format): string
    {
        $response = $this->postJson('/api/v1/reports/export', [
            'report_code' => 'label-log',
            'format' => $format,
            'filters' => [
                'from_date' => now()->subMonth()->toDateString(),
                'to_date' => now()->toDateString(),
            ],
        ], [
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(202);

        return (string) $response->json('data.export_job_id');
    }

    /**
     * Bộ test chạy `QUEUE_CONNECTION=sync` nên job đã chạy xong ngay lúc dispatch; lần
     * đọc trạng thái này chỉ để lấy kết quả cuối, không phải vòng poll thật.
     */
    private function pollUntilSettled(string $jobId): TestResponse
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

    /** Nhãn đóng gói cấp document: đúng một label_snapshots cho mỗi phiếu xuất. */
    private function seedPackingLabels(int $count): void
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-PDF-TEST', 'TEST packing label export warehouse');
        for ($i = 0; $i < $count; $i++) {
            $documentId = (string) Str::uuid();
            DB::table('stock_documents')->insert([
                'id' => $documentId,
                'doc_type' => 'issue',
                'doc_no' => 'PX-345-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'doc_date' => now()->toDateString(),
                'status' => 'POSTED',
                'src_warehouse_id' => $warehouse->id,
                'posted_at' => now(),
                'posted_by' => $this->user->user_id,
                'recipient_name' => 'Đại lý Hoa Nam '.$i,
                'created_by' => $this->user->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('label_snapshots')->insert([
                'id' => (string) Str::uuid(),
                'outbound_document_id' => $documentId,
                'outbound_item_match_id' => null,
                'sku_id' => null,
                'item_id' => null,
                'label_payload' => json_encode(['label_code' => 'PL-345-'.$i]),
                'status' => 'PRINTED',
                'applied_at' => now(),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
