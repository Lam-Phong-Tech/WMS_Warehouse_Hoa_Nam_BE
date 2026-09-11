<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Support\ReportExportJobRunner;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Tải file export phải đúng ở MỌI cách bố trí tiến trình, không chỉ ở stack local.
 *
 * 11/09/2026, hai môi trường cùng nhận "Không tìm thấy file export (có thể đã bị dọn dẹp)."
 * khi bấm Tải về một export READY:
 *  - khohoanamfe.bigk.click (BE build 07/09): `put()` ghi hỏng mà không kiểm giá trị trả
 *    về, job vẫn READY;
 *  - khohoanamfe.lptech.info.vn (BE đã có bản vá put()): file được ghi THÀNH CÔNG nhưng bởi
 *    tiến trình khác, lên ổ đĩa mà tiến trình tải về không nhìn thấy. Stack local không lộ
 *    vì compose dev bind-mount cùng một thư mục cho mọi container.
 *
 * "Máy khác" được mô phỏng bằng `Storage::fake('local')` lần hai giữa lúc ghi và lúc tải:
 * ổ đĩa của tiến trình tải về trống trơn, chỉ database là dùng chung.
 */
class ReportExportFileStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM', 'status' => 'ACTIVE',
        ]);
        $this->user = User::create([
            'username' => 'export-store-admin', 'display_name' => 'Export store admin',
            'email' => 'export-store@wms.test', 'password_hash' => Hash::make(Str::random(24)),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $this->user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->token = JWTAuth::fromUser($this->user);

        $warehouse = $this->ensureActiveWarehouse('KHO-EXP-STORE', 'Kho test nơi lưu file export');
        $product = Sku::create([
            'code' => 'SP-EXP-STORE', 'name' => 'Sản phẩm test export', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);
        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(8),
            'business_key' => 'test:export-store:'.Str::random(6),
            'movement_type' => 'RECEIPT',
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0, 'qty_delta' => 5, 'qty_after' => 5, 'quantity' => 5,
            'created_by' => $this->user->user_id,
            'occurred_at' => now()->toDateString().' 12:00:00',
            'created_at' => now()->toDateString().' 12:00:00',
        ]);
    }

    public function test_export_content_lives_in_the_database_so_another_process_can_serve_the_download(): void
    {
        Queue::fake();
        Storage::fake('local');

        $jobId = $this->readyExport();
        $job = ReportExportJob::query()->find($jobId);
        Storage::disk('local')->assertMissing((string) $job->file_path);

        $stored = DB::table('report_export_files')->where('export_job_id', $jobId)->first();
        $this->assertNotNull($stored, 'Nội dung file phải nằm trong database — nơi duy nhất chắc chắn dùng chung.');

        // Tiến trình tải về chạy ở máy khác: ổ đĩa của nó trống trơn.
        Storage::fake('local');

        $download = $this->download($jobId)->assertOk();
        $bytes = $download->streamedContent();

        $this->assertStringStartsWith("PK\x03\x04", $bytes);
        $this->assertSame($stored->sha256, hash('sha256', $bytes));
        $this->assertSame((int) $stored->byte_size, strlen($bytes));
        $this->assertStringContainsString('spreadsheetml', (string) $download->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        $this->assertTrue(
            $job->expires_at->equalTo(ReportExportJob::query()->find($jobId)->expires_at),
            'Đọc được bản trong database thì không được dựng lại file.'
        );
    }

    public function test_ready_job_whose_file_sits_on_another_machines_disk_is_rebuilt_on_download(): void
    {
        Queue::fake();
        Storage::fake('local');
        // Bản cũ, hoặc môi trường đặt REPORT_EXPORT_DISK trỏ vào một ổ KHÔNG dùng chung.
        config(['wms_export.disk' => 'local']);

        $jobId = $this->readyExport();
        $filePath = (string) ReportExportJob::query()->find($jobId)->file_path;
        Storage::disk('local')->assertExists($filePath);

        Storage::fake('local');
        Log::spy();

        $download = $this->download($jobId)->assertOk();
        $this->assertStringStartsWith("PK\x03\x04", $download->streamedContent());

        $job = ReportExportJob::query()->find($jobId);
        $this->assertSame('READY', $job->status);
        $this->assertNull($job->error_code);
        $this->assertTrue(
            DB::table('report_export_files')->where('export_job_id', $jobId)->exists(),
            'Bản dựng lại phải vào database — ghi lại vào đúng ổ vừa đọc hỏng thì lần tải sau hỏng y hệt.'
        );

        // Lần tải sau từ một tiến trình khác nữa: đọc bản trong database, không dựng lại lần hai.
        Storage::fake('local');
        $this->assertStringStartsWith("PK\x03\x04", $this->download($jobId)->assertOk()->streamedContent());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_starts_with($message, 'Report export file missing at download')
                && $context['export_job_id'] === $jobId
                && $context['target'] === 'local'
                && $context['path'] === $filePath
                && array_key_exists('host', $context))
            ->once();
    }

    public function test_missing_file_fails_with_a_reason_when_inline_fallback_is_disabled(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['wms_export.disk' => 'local']);

        $jobId = $this->readyExport();
        $filePath = (string) ReportExportJob::query()->find($jobId)->file_path;
        Storage::fake('local');

        // Tắt đường cứu hộ = mọi export phải đi qua worker, request tải về không được dựng file.
        config(['wms_export.inline_fallback' => false]);
        Log::spy();

        $download = $this->download($jobId);
        $download->assertNotFound()
            ->assertJsonPath('error.code', ReportExportJobRunner::ERROR_FILE_MISSING);
        $this->assertStringContainsString('xuất lại', (string) $download->json('message'));
        $this->assertStringNotContainsString('dọn dẹp', (string) $download->json('message'));

        // Lần poll sau không còn trả READY + download_url cho một file không tồn tại.
        $this->pollStatus($jobId)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.error_code', ReportExportJobRunner::ERROR_FILE_MISSING)
            ->assertJsonPath('data.download_url', null);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Report export file missing at download'
                && $context['export_job_id'] === $jobId
                && $context['target'] === 'local'
                && $context['path'] === $filePath
                && array_key_exists('host', $context))
            ->once();
    }

    public function test_ready_job_from_before_the_switch_still_downloads_from_the_local_disk(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['wms_export.disk' => 'local']);

        $jobId = $this->readyExport();
        $expiresAt = ReportExportJob::query()->find($jobId)->expires_at;

        // Deploy bản mới: mặc định chuyển sang database, file của job cũ vẫn nằm trên ổ.
        config(['wms_export.disk' => 'database']);

        $this->assertStringStartsWith("PK\x03\x04", $this->download($jobId)->assertOk()->streamedContent());
        $this->assertFalse(DB::table('report_export_files')->where('export_job_id', $jobId)->exists());
        $this->assertTrue(
            $expiresAt->equalTo(ReportExportJob::query()->find($jobId)->expires_at),
            'Đọc được file cũ trên ổ thì không được dựng lại.'
        );
    }

    public function test_disk_write_failure_marks_job_failed_with_the_cause_instead_of_ready(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['wms_export.disk' => 'local']);

        $jobId = $this->createExportJob();
        // Chiếm đúng đường dẫn file bằng một THƯ MỤC: file_put_contents hỏng nên put() trả
        // false — cùng đường đi với đĩa hết chỗ/thiếu quyền, và chạy được cả khi test chạy
        // bằng root (quyền không chặn được root, còn "đường dẫn là thư mục" thì có).
        mkdir(Storage::disk('local')->path("report-exports/{$jobId}.xlsx"), 0777, true);

        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();

        $status = $this->pollStatus($jobId);
        $status->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.download_url', null);
        $this->assertStringContainsString(
            "Không ghi được file export vào đĩa 'local'",
            (string) $status->json('data.error_message'),
        );
    }

    public function test_database_write_failure_marks_job_failed_with_a_short_reason(): void
    {
        Queue::fake();
        // Deploy mã mới nhưng quên `php artisan migrate`.
        Schema::drop('report_export_files');

        $jobId = $this->createExportJob();
        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();

        $status = $this->pollStatus($jobId);
        $status->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.download_url', null);

        $message = (string) $status->json('data.error_message');
        $this->assertStringContainsString('Không lưu được file export vào database', $message);
        $this->assertStringContainsString('report_export_files', $message);
        // QueryException chép cả tham số — tức nội dung file base64 — vào thông điệp của nó.
        $this->assertLessThan(600, strlen($message));
    }

    public function test_expired_export_keeps_its_own_message_and_is_neither_rebuilt_nor_failed(): void
    {
        Queue::fake();

        $jobId = $this->readyExport();
        ReportExportJob::query()->where('export_job_id', $jobId)->update(['expires_at' => now()->subMinute()]);

        $this->download($jobId)->assertNotFound()->assertJsonPath('message', 'File export này đã hết hạn.');

        $this->assertSame('READY', ReportExportJob::query()->find($jobId)->status);
    }

    public function test_purge_removes_expired_content_from_the_database_and_clears_the_path(): void
    {
        Queue::fake();
        Storage::fake('local');

        $jobId = $this->readyExport();

        $this->travel(25)->hours();
        $this->artisan('report-export:purge')->assertExitCode(0);

        $this->assertFalse(DB::table('report_export_files')->where('export_job_id', $jobId)->exists());
        $this->assertNull(ReportExportJob::query()->find($jobId)->file_path);
    }

    public function test_each_new_export_prunes_expired_content_even_without_the_scheduler(): void
    {
        Queue::fake();

        $expired = $this->readyExport();

        $this->travel(25)->hours();
        // Token của setUp đã quá hạn sau 25 giờ.
        $this->token = JWTAuth::fromUser($this->user);
        $fresh = $this->readyExport();

        $this->assertFalse(DB::table('report_export_files')->where('export_job_id', $expired)->exists());
        $this->assertTrue(DB::table('report_export_files')->where('export_job_id', $fresh)->exists());
    }

    /** Tạo export rồi để quá ân hạn: lần poll kế tiếp tự chạy job (Queue::fake — không worker nào nhặt). */
    private function readyExport(): string
    {
        $jobId = $this->createExportJob();
        $this->travel((int) config('wms_export.pickup_grace_seconds') + 5)->seconds();
        $this->pollStatus($jobId)->assertJsonPath('data.status', 'READY');

        return $jobId;
    }

    private function createExportJob(): string
    {
        $response = $this->postJson('/api/v1/reports/export', [
            'report_code' => 'movement-trace',
            'format' => 'xlsx',
            'filters' => ['from_date' => now()->subMonth()->toDateString(), 'to_date' => now()->toDateString()],
        ], [
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => 'export-store-'.Str::random(12),
        ])->assertStatus(202);

        return (string) $response->json('data.export_job_id');
    }

    private function pollStatus(string $jobId)
    {
        return $this->getJson("/api/v1/reports/export/{$jobId}", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();
    }

    private function download(string $jobId)
    {
        return $this->getJson("/api/v1/reports/export/{$jobId}/download", [
            'Authorization' => "Bearer {$this->token}",
        ]);
    }
}
