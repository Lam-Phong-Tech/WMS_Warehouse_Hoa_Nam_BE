<?php

namespace Tests\Feature;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Exceptions\ImportConcurrencyException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\ImportErrorFileService;
use Modules\Warehouse\Services\ImportService;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-330 review R3 (71/100) — đóng bốn mục còn thuộc phần code:
 *
 * - DEV330-P1-07R3: `withImportLock()` fail-open khi hết thời gian chờ → phải fail-closed.
 * - DEV330-P1-02R3: hạn mức cỡ file / phần mở rộng / chính sách quét dòng vật lý còn là
 *   số cứng trong `ImportService`, lệch khỏi hợp đồng mà validator đã dùng.
 * - DEV330-P1-01R3: retention VẬT LÝ của file lỗi (khác hạn dùng logic), gồm cả file mồ
 *   côi và bằng chứng audit của lần purge.
 * - DEV330-P1-01R3 (hợp đồng file lỗi): cột file lỗi theo SRS §13.12.
 */
class Wwhn330ImportR3ClosureTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.write_policy' => 'fail_closed']);
        $this->seed(AuthDatabaseSeeder::class);
        $user = User::query()->where('email', 'test-super-admin@gmail.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ── DEV330-P1-07R3: hết thời gian chờ khoá là TỪ CHỐI, không phải chạy tiếp ──────

    public function test_lock_timeout_refuses_the_import_instead_of_running_without_the_lock(): void
    {
        $path = $this->plainXlsxPath([['LOCKTO-1', 'San pham khoa het gio']]);
        $actorId = $this->superAdminId();

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        // Không giành được khoá thì cũng không được release khoá của người khác.
        $lock->shouldNotReceive('release');
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        try {
            app(ImportService::class)->import($path, false, $actorId, null, 'locktimeout.xlsx', false);
            $this->fail('Hết thời gian chờ khoá mà import vẫn chạy — đây chính là hành vi fail-open phải bỏ.');
        } catch (ImportConcurrencyException $e) {
            $this->assertSame(SkuImportContract::lockWaitSeconds(), $e->waitedSeconds);
        }

        // §8.2 cho 409 không phải replay: "State, stock and movement unchanged".
        $this->assertSame(0, Sku::query()->where('sku_code', 'LOCKTO-1')->count());
        $this->assertSame(0, ImportReport::query()->where('import_type', 'SKU')->count());
    }

    public function test_lock_timeout_is_surfaced_as_409_concurrency_conflict_with_the_contract(): void
    {
        // Kiểm tầng HTTP tách khỏi tầng khoá: ở đây cần biết controller ánh xạ đúng mã lỗi
        // §8.2 nào, không cần dựng lại tranh chấp khoá thật.
        $service = Mockery::mock(ImportService::class);
        $service->shouldReceive('import')->once()->andThrow(
            new ImportConcurrencyException('Cùng file này đang được import với cùng tùy chọn; vui lòng đợi lần chạy hiện tại kết thúc rồi thử lại.', 15)
        );
        $this->app->instance(ImportService::class, $service);

        $response = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], [['CONC-001', 'Hop le']]),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CONCURRENCY_CONFLICT')
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.partial_write', false)
            ->assertJsonPath('data.lock_wait_seconds', 15)
            // Hợp đồng vẫn đi kèm cả ở ca 409 — UI không mất hạn mức đang hiển thị.
            ->assertJsonPath('data.constraints.decision_ref', config('warehouse.sku_import.decision_ref'));

        $this->assertSame(0, ImportReport::query()->where('import_type', 'SKU')->count());
    }

    public function test_fail_open_remains_possible_but_only_as_an_explicit_written_decision(): void
    {
        // Hướng ngược lại phải cấu hình được (sự cố hạ tầng kéo dài, chấp nhận rủi ro có
        // văn bản) nhưng KHÔNG được là mặc định ẩn. Test này khoá đúng điều đó: chỉ đúng
        // chuỗi FAIL_OPEN mới đổi hành vi.
        config(['warehouse.sku_import.lock.on_timeout' => 'FAIL_OPEN']);
        $this->assertFalse(SkuImportContract::lockFailsClosed());

        $path = $this->plainXlsxPath([['LOCKOPEN-1', 'San pham fail open']]);
        $actorId = $this->superAdminId();

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $result = app(ImportService::class)->import($path, false, $actorId, null, 'failopen.xlsx', false);

        $this->assertTrue($result['committed']);

        // Một giá trị gõ sai KHÔNG được vô tình hạ tiêu chuẩn toàn vẹn dữ liệu.
        config(['warehouse.sku_import.lock.on_timeout' => 'fail open']);
        $this->assertTrue(SkuImportContract::lockFailsClosed());
    }

    // ── DEV330-P1-02R3: hạn mức ở tầng service đọc cùng một hợp đồng ────────────────

    public function test_service_level_file_size_ceiling_reads_the_contract_not_a_hard_coded_10mb(): void
    {
        $path = $this->plainXlsxPath([['SIZE-1', 'San pham co file']]);
        $sizeKb = (int) ceil(((int) filesize($path)) / 1024);
        config(['warehouse.sku_import.max_file_size_kb' => max(1, $sizeKb - 1)]);

        // Gọi thẳng service: đường CLI/job không đi qua validator HTTP nào, nên hàng rào
        // này phải tự đúng. Trước khi vá, nó so với hằng số 10 MB viết cứng và sẽ cho qua.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/vượt quá/u');

        app(ImportService::class)->import($path, false, $this->superAdminId(), null, 'size.xlsx', true);
    }

    public function test_service_level_extension_whitelist_reads_the_contract(): void
    {
        $path = $this->plainXlsxPath([['EXT-1', 'San pham dinh dang']]);
        config(['warehouse.sku_import.allowed_extensions' => ['xls']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/xls/u');

        app(ImportService::class)->import($path, false, $this->superAdminId(), null, 'ext.xlsx', true);
    }

    public function test_physical_scan_policy_comes_from_the_contract_and_rejects_explicitly(): void
    {
        // Hạ cả hạn mức lẫn bội số quét: trần quét = 1 × (5 + 2) = 7 dòng vật lý.
        config([
            'warehouse.sku_import.max_rows' => 5,
            'warehouse.sku_import.max_physical_scan_multiplier' => 1,
        ]);
        $this->assertSame(7, SkuImportContract::maxPhysicalRowsToScan());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['code', 'name'], null, 'A1');
        $sheet->fromArray(['SCAN-1', 'Gan dau file'], null, 'A2');
        $sheet->fromArray(['SCAN-2', 'Nam ngoai tran quet'], null, 'A30');

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $this->save($spreadsheet)]);

        // Không bỏ qua im lặng phần đuôi: người dùng phải biết file của mình bị từ chối.
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED');
        $this->assertSame(0, Sku::query()->where('sku_code', 'LIKE', 'SCAN-%')->count());
    }

    public function test_constraints_publish_the_scan_retention_and_concurrency_policies(): void
    {
        $response = $this->withHeaders($this->headers())->get('/api/v1/skus/import-reports');

        $response->assertOk()
            ->assertJsonPath('meta.constraints.max_physical_rows_to_scan', SkuImportContract::maxPhysicalRowsToScan())
            ->assertJsonPath('meta.constraints.error_file_max_on_disk_hours', SkuImportContract::errorFileMaxOnDiskHours())
            ->assertJsonPath('meta.constraints.concurrent_import_policy', 'FAIL_CLOSED_409');

        // Hạn dùng LOGIC và thời gian tồn tại VẬT LÝ là hai con số khác nhau và phải
        // đọc được riêng — gộp làm một chính là chỗ review chỉ ra.
        $this->assertGreaterThan(
            (int) $response->json('meta.constraints.error_file_expiry_hours'),
            (int) $response->json('meta.constraints.error_file_max_on_disk_hours'),
        );
    }

    // ── DEV330-P1-01R3: file lỗi theo schema SRS §13.12 ─────────────────────────────

    public function test_error_file_follows_the_srs_13_12_schema_with_severity_and_suggested_action(): void
    {
        $import = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], [['SCHEMA-001', 'A'], ['SCHEMA-001', 'B']]),
        ]);
        $import->assertStatus(422);
        $reportId = $import->json('data.import_report_id');

        $csv = $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertOk()
            ->streamedContent();

        $rows = $this->parseCsv($csv);

        $this->assertSame(ImportErrorFileService::COLUMNS, $rows[0], 'Header file lỗi phải khớp SRS §13.12.');
        $this->assertNotEmpty($rows[1] ?? []);

        $row = array_combine(ImportErrorFileService::COLUMNS, $rows[1]);
        $this->assertNotSame('', $row['source_file'], 'Phải nêu file nguồn để người nhận không phải tra ngược.');
        $this->assertNotSame('', $row['sheet'], 'Phải nêu sheet nguồn theo §13.12.');
        $this->assertSame('DUPLICATE_SKU_CODE_IN_FILE', $row['error_code']);
        $this->assertSame('CRITICAL', $row['severity'], 'Xung đột định danh là CRITICAL theo §13.12.');
        $this->assertStringContainsString('trùng', mb_strtolower($row['suggested_action']));
    }

    public function test_error_file_masks_values_that_look_like_personal_data(): void
    {
        $import = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            // sku_code sai định dạng và nội dung lại là một email → giá trị sai bị chép
            // nguyên vào file lỗi rồi tải về được. §13.12: raw_value "Masked if PII".
            'file' => $this->xlsx(['code', 'name'], [['khach.hang@example.com', 'Ten hang']]),
        ]);
        $import->assertStatus(422);
        $reportId = $import->json('data.import_report_id');

        $csv = $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('khach.hang@example.com', mb_strtolower($csv));
        $this->assertStringContainsString('***@***', $csv);
    }

    // ── DEV330-P1-01R3: retention VẬT LÝ ────────────────────────────────────────────

    public function test_expired_error_file_is_removed_from_disk_and_the_purge_run_is_audited(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $report = $this->reportWithErrorFile('expired.csv', now()->subHours(2));

        $this->artisan('import:purge-error-files')->assertSuccessful();

        $disk->assertMissing((string) $report->error_file_path);
        $report->refresh();
        $this->assertNull($report->error_file_path);
        $this->assertNull($report->error_file_expires_at);

        // SRS §13.8: purge job phải audit số record đã purge. stdout của cron không phải
        // bằng chứng tuân thủ — nó biến mất.
        $audit = AuditLog::query()->where('action', 'IMPORT_ERROR_FILE_PURGE')->latest('created_at')->first();
        $this->assertNotNull($audit, 'Lần chạy purge phải để lại bằng chứng audit.');
    }

    public function test_purge_is_idempotent_and_dry_run_changes_nothing(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $report = $this->reportWithErrorFile('dry.csv', now()->subHours(2));

        $this->artisan('import:purge-error-files', ['--dry-run' => true])->assertSuccessful();
        $disk->assertExists((string) $report->error_file_path);
        $this->assertNotNull($report->refresh()->error_file_path);

        $this->artisan('import:purge-error-files')->assertSuccessful();
        // Chạy lại lần nữa: không còn gì để xoá, không lỗi, không đổi trạng thái.
        $this->artisan('import:purge-error-files')->assertSuccessful();
        $this->assertNull($report->refresh()->error_file_path);
    }

    public function test_unexpired_error_file_is_kept(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $report = $this->reportWithErrorFile('fresh.csv', now()->addHours(5));

        $this->artisan('import:purge-error-files')->assertSuccessful();

        $disk->assertExists((string) $report->error_file_path);
        $this->assertNotNull($report->refresh()->error_file_path);
    }

    public function test_orphan_error_file_with_no_report_row_is_swept_after_retention(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        // Ca thật: ghi file xong nhưng lưu path vào DB thất bại → không bản ghi nào trỏ
        // tới file này, nên vòng quét theo báo cáo không bao giờ chạm tới nó.
        $orphan = ImportErrorFileService::STORAGE_SUBDIR.'/orphan-'.bin2hex(random_bytes(4)).'.csv';
        $disk->put($orphan, "row,field\n1,sku_code\n");
        $this->ageFile($disk->path($orphan), SkuImportContract::errorFileExpiryHours() + 2);

        $this->artisan('import:purge-error-files')->assertSuccessful();

        $disk->assertMissing($orphan);
    }

    public function test_orphan_sweep_never_touches_a_file_still_inside_its_retention(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        // File vừa sinh trong một lần import đang chạy: chưa kịp lưu path vào DB không có
        // nghĩa là rác. Xoá sớm ở đây sẽ làm hỏng đúng lần import đang diễn ra.
        $fresh = ImportErrorFileService::STORAGE_SUBDIR.'/fresh-'.bin2hex(random_bytes(4)).'.csv';
        $disk->put($fresh, "row,field\n1,sku_code\n");

        $this->artisan('import:purge-error-files')->assertSuccessful();

        $disk->assertExists($fresh);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────

    private function reportWithErrorFile(string $name, Carbon $expiresAt): ImportReport
    {
        $report = ImportReport::query()->create([
            'import_type' => 'SKU',
            'mode' => 'COMMIT',
            'status' => 'REJECTED',
            'file_name' => $name,
            'file_sha256' => hash('sha256', $name),
            'total_rows' => 1,
            'valid_rows' => 0,
            'error_rows' => 1,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'row_errors' => [],
            'actor_id' => $this->superAdminId(),
        ]);

        $path = ImportErrorFileService::STORAGE_SUBDIR.'/'.$report->import_report_id.'.csv';
        Storage::disk('local')->put($path, "row,field\n1,sku_code\n");

        $report->forceFill(['error_file_path' => $path, 'error_file_expires_at' => $expiresAt])->save();

        return $report;
    }

    /** Đẩy mtime của file lùi lại N giờ để mô phỏng file đã nằm trên đĩa lâu. */
    private function ageFile(string $absolutePath, int $hours): void
    {
        touch($absolutePath, time() - ($hours * 3600));
        clearstatcache(true, $absolutePath);
    }

    /** @return array<int, array<int, string>> */
    private function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }

    private function superAdminId(): string
    {
        return (string) User::query()->where('email', 'test-super-admin@gmail.com')->value('user_id');
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function plainXlsxPath(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['code', 'name'], null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn330-r3-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        return $this->save($spreadsheet);
    }

    private function save(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = sys_get_temp_dir().'/wwhn330-r3-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return new UploadedFile(
            $path,
            basename($path),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }
}
