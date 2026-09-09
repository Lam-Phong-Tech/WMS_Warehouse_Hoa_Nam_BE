<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Jobs\GenerateReportExportJob;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\ReportExportJobRunner;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-343 / TC_FUNC_03 — "Export job bị treo ở trạng thái PENDING, không tải được file
 * XLSX".
 *
 * Triệu chứng tester ghi nhận: `POST /reports/export` trả 202 + PENDING, rồi mọi lần
 * `GET /reports/export/{id}` đều trả 200 + `status=PENDING`, `download_url=null`,
 * `error_code=null`, `error_message=null` — không bao giờ READY mà cũng không bao giờ
 * FAILED. Nguyên nhân: `QUEUE_CONNECTION=redis` nên createExport() chỉ ĐẨY
 * GenerateReportExportJob vào Redis; container `queue` không chạy thì không có gì nhặt
 * job ra.
 *
 * Cả file này chạy với `Queue::fake()` — đó chính là "không có worker": job được đẩy đi
 * nhưng KHÔNG BAO GIỜ được thực thi. (Bộ test mặc định dùng QUEUE_CONNECTION=sync nên job
 * chạy ngay lúc dispatch, tức là hoàn cảnh của tester không thể lộ ra.)
 */
class Wwhn343ReportExportStalledJobTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Warehouse $warehouse;

    private Sku $product;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $this->user = User::create([
            'username' => 'wwhn343-admin', 'display_name' => 'WWHN-343 Admin',
            'email' => 'wwhn343@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $this->user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->token = JWTAuth::fromUser($this->user);

        $this->warehouse = $this->ensureActiveWarehouse('KHO-343', 'Kho test WWHN-343');
        $this->product = Sku::create([
            'code' => 'SP-343', 'name' => 'Sản phẩm WWHN-343', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);
        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(8),
            'business_key' => 'test:343:'.Str::random(6),
            'movement_type' => 'RECEIPT',
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0, 'qty_delta' => 5, 'qty_after' => 5, 'quantity' => 5,
            'created_by' => $this->user->user_id,
            'occurred_at' => now()->toDateString().' 12:00:00',
            'created_at' => now()->toDateString().' 12:00:00',
        ]);
    }

    /**
     * Bằng chứng hồi quy chính: đúng kịch bản của tester (movement-trace + xlsx, worker
     * không chạy) giờ kết thúc bằng READY + file XLSX tải được, thay vì PENDING vĩnh viễn.
     */
    public function test_pending_job_without_queue_worker_completes_on_next_poll(): void
    {
        Queue::fake();
        Storage::fake('local');

        $jobId = $this->createExportJob();

        // Trong thời gian ân hạn: vẫn PENDING, đúng như trước — không được "sửa" bằng
        // cách bỏ luôn hàng đợi, worker vẫn phải là đường chính.
        Queue::assertPushed(GenerateReportExportJob::class);
        $this->pollStatus($jobId)->assertJsonPath('data.status', 'PENDING');

        // Quá ân hạn mà worker vẫn im: lần poll kế tiếp phải tự chạy export.
        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();

        $status = $this->pollStatus($jobId);
        $status->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.error_code', null);
        $this->assertNotNull($status->json('data.row_count'));

        $downloadUrl = (string) $status->json('data.download_url');
        $this->assertStringContainsString("/reports/export/{$jobId}/download", $downloadUrl);

        $download = $this->getJson($downloadUrl, ['Authorization' => "Bearer {$this->token}"]);
        $download->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $download->headers->get('content-type'),
            'File tải về phải là XLSX thật, không phải JSON lỗi.'
        );
        $this->assertGreaterThan(0, strlen($download->streamedContent()));
    }

    /**
     * `download_url` bị bấm thẳng (không poll) trên job worker bỏ quên cũng phải ra file,
     * không phải 404 vĩnh viễn.
     */
    public function test_download_endpoint_also_rescues_a_forgotten_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $jobId = $this->createExportJob();
        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();

        $this->getJson("/api/v1/reports/export/{$jobId}/download", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();

        $this->assertSame('READY', ReportExportJob::query()->find($jobId)->status);
    }

    /**
     * Khi tắt đường cứu hộ (muốn ép mọi export đi qua worker), job vẫn KHÔNG được đứng
     * PENDING: nó phải chốt FAILED kèm error_code + error_message để FE nói được lý do —
     * đúng Expected Result của ticket.
     */
    public function test_pending_job_fails_loudly_when_inline_fallback_is_disabled(): void
    {
        Queue::fake();
        config()->set('wms_export.inline_fallback', false);

        $jobId = $this->createExportJob();
        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();

        $status = $this->pollStatus($jobId);
        $status->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.error_code', ReportExportJobRunner::ERROR_STALLED)
            ->assertJsonPath('data.download_url', null);
        $this->assertNotEmpty($status->json('data.error_message'));
    }

    /**
     * Worker nhận job rồi chết giữa chừng (timeout/OOM/container bị kill): bản ghi kẹt ở
     * PROCESSING. Cùng lớp lỗi "treo vô thời hạn" nên cũng phải được chốt.
     */
    public function test_processing_job_abandoned_by_a_dead_worker_is_failed(): void
    {
        Queue::fake();

        $jobId = $this->createExportJob();
        ReportExportJobRunner::claim($jobId);
        ReportExportJob::query()->where('export_job_id', $jobId)->update([
            'updated_at' => now()->subSeconds((int) config('wms_export.processing_timeout_seconds') + 10),
        ]);

        $this->pollStatus($jobId)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.error_code', ReportExportJobRunner::ERROR_STALLED);
    }

    /** Job đang chạy thật (PROCESSING còn tươi) KHÔNG bị giết oan. */
    public function test_processing_job_within_timeout_is_left_alone(): void
    {
        Queue::fake();

        $jobId = $this->createExportJob();
        ReportExportJobRunner::claim($jobId);

        $this->pollStatus($jobId)->assertJsonPath('data.status', 'PROCESSING');
    }

    /**
     * Bất biến quan trọng nhất của thiết kế: đường cứu hộ và worker có thể chạy chồng
     * nhau, nhưng chỉ MỘT bên được thực thi job.
     */
    public function test_claim_is_atomic_so_a_job_never_runs_twice(): void
    {
        Queue::fake();

        $jobId = $this->createExportJob();

        $this->assertTrue(ReportExportJobRunner::claim($jobId), 'Lần giành quyền đầu tiên phải thắng.');
        $this->assertFalse(ReportExportJobRunner::claim($jobId), 'Lần thứ hai phải thua.');

        // Worker nhặt job sau khi đường cứu hộ đã chạy xong: handle() phải im lặng bỏ qua
        // thay vì chạy lại và ghi đè kết quả.
        ReportExportJob::query()->where('export_job_id', $jobId)->update([
            'status' => 'READY', 'row_count' => 7, 'file_path' => 'report-exports/x.xlsx',
        ]);
        (new GenerateReportExportJob($jobId, 'movement-trace', 'xlsx', [], $this->user->user_id))->handle();

        $job = ReportExportJob::query()->find($jobId);
        $this->assertSame('READY', $job->status);
        $this->assertSame(7, $job->row_count);
    }

    /** Worker chết hẳn: hook failed() của Laravel phải chốt bản ghi, không để nó treo. */
    public function test_queue_failure_hook_records_error_on_the_job_row(): void
    {
        Queue::fake();

        $jobId = $this->createExportJob();
        ReportExportJobRunner::claim($jobId);

        (new GenerateReportExportJob($jobId, 'movement-trace', 'xlsx', [], $this->user->user_id))
            ->failed(new RuntimeException('worker bi kill'));

        $job = ReportExportJob::query()->find($jobId);
        $this->assertSame('FAILED', $job->status);
        $this->assertSame(ReportExportJobRunner::ERROR_STALLED, $job->error_code);
        $this->assertStringContainsString('worker bi kill', (string) $job->error_message);
    }

    /**
     * Người dùng đóng tab (không ai poll) thì bản ghi vẫn phải được đóng lại — nếu không
     * bảng đầy job PENDING zombie và Expected Result "không PENDING vô thời hạn" chỉ đúng
     * với ai còn ngồi chờ.
     */
    public function test_scheduled_reaper_resolves_jobs_nobody_is_polling(): void
    {
        Queue::fake();
        Storage::fake('local');

        $jobId = $this->createExportJob();
        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();

        $this->artisan('report-export:reap')->assertExitCode(0);

        $this->assertSame('READY', ReportExportJob::query()->find($jobId)->status);
    }

    private function createExportJob(): string
    {
        $response = $this->postJson('/api/v1/reports/export', [
            'report_code' => 'movement-trace',
            'format' => 'xlsx',
            'filters' => ['from_date' => now()->subMonth()->toDateString(), 'to_date' => now()->toDateString()],
        ], [
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => 'wwhn343-'.Str::random(12),
        ])->assertStatus(202);

        $response->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.download_url', null);

        return (string) $response->json('data.export_job_id');
    }

    private function pollStatus(string $jobId)
    {
        return $this->getJson("/api/v1/reports/export/{$jobId}", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();
    }
}
