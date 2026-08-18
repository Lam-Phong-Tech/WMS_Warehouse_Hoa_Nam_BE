<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-330 (review 47/100 → khắc phục P1-01/02/03) — error file thật cho row lỗi,
 * idempotent replay theo sha256+actor, và error code chuẩn khi vượt giới hạn dòng.
 */
class Wwhn330ImportHardeningTest extends TestCase
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
        $user = User::query()->where('email', 'test-super-admin@hoanam.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ── P1-01: error file thật cho rows bị reject ───────────────────────────

    public function test_import_with_row_errors_generates_a_downloadable_error_file(): void
    {
        $file = $this->xlsx(['code', 'name'], [
            ['DUP-EF', 'Dong 1'],
            ['DUP-EF', 'Dong trung'],
        ]);

        $import = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $import->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_VALIDATION_FAILED');

        $reportId = $import->json('data.import_report_id');
        $this->assertNotNull($reportId);

        $report = ImportReport::query()->findOrFail($reportId);
        $this->assertNotNull($report->error_file_path);
        $this->assertNotNull($report->error_file_expires_at);

        $download = $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file");

        $download->assertOk();
        $download->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $download->streamedContent();
        $this->assertStringContainsString('row', $csv);
        $this->assertStringContainsString('field', $csv);
        $this->assertStringContainsString('DUP-EF', $csv);
    }

    public function test_error_file_download_requires_sku_manage_permission(): void
    {
        $file = $this->xlsx(['code', 'name'], [['DUP-PERM', 'A'], ['DUP-PERM', 'B']]);
        $import = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);
        $reportId = $import->json('data.import_report_id');

        $this->withHeaders($this->authHeadersWithoutSkuManage())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_error_file_download_returns_404_for_unknown_report(): void
    {
        $this->withHeaders($this->headers())
            ->get('/api/v1/skus/import-reports/'.Str::uuid().'/error-file')
            ->assertStatus(404);
    }

    public function test_error_file_download_returns_404_when_report_has_no_errors(): void
    {
        $file = $this->xlsx(['code', 'name'], [['NO-ERR-EF', 'Khong loi']]);
        $import = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);
        $reportId = $import->json('data.import_report_id');

        $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertStatus(404);
    }

    public function test_error_file_download_returns_404_after_expiry(): void
    {
        $file = $this->xlsx(['code', 'name'], [['DUP-EXP', 'A'], ['DUP-EXP', 'B']]);
        $import = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);
        $reportId = $import->json('data.import_report_id');

        $report = ImportReport::query()->findOrFail($reportId);
        $report->forceFill(['error_file_expires_at' => now()->subHour()])->save();

        $this->withHeaders($this->headers())
            ->get("/api/v1/skus/import-reports/{$reportId}/error-file")
            ->assertStatus(404);
    }

    // ── P1-02: vượt giới hạn dòng trả error code chuẩn, không 500 ───────────

    public function test_import_over_row_limit_returns_422_with_standard_error_code_not_500(): void
    {
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ["ROWLIMIT-{$i}", "SKU {$i}"];
        }
        $file = $this->xlsx(['code', 'name'], $rows);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.max_rows', 1000)
            ->assertJsonPath('data.actual_rows', 1001);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'ROWLIMIT-0']);
    }

    // ── P1-03: idempotent replay theo sha256 + actor ─────────────────────────

    public function test_reimporting_identical_file_by_same_actor_returns_duplicate_without_rewriting(): void
    {
        // 2 UploadedFile tro toi 2 file vat ly khac nhau nhung noi dung byte-for-byte
        // giong het nhau (copy truoc khi upload), de dam bao cung sha256 mot cach on
        // dinh — sinh lai qua PhpSpreadsheet 2 lan co the lech nhau o metadata timestamp.
        [$file1, $file2] = $this->identicalXlsxPair(['code', 'name'], [['IDEMPOTENT-001', 'Ban goc']]);

        $first = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file1]);
        $first->assertOk()->assertJsonPath('data.committed', true);
        $firstReportId = $first->json('data.import_report_id');

        $second = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file2]);

        $second->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.import_report_id', $firstReportId);

        $this->assertSame(1, ImportReport::query()->where('mode', 'COMMIT')->count());
        $this->assertSame(
            1,
            \Modules\Warehouse\Models\Sku::query()->where('sku_code', 'IDEMPOTENT-001')->count()
        );
    }

    public function test_reimporting_identical_file_by_different_actor_is_not_treated_as_duplicate(): void
    {
        [$file1, $file2] = $this->identicalXlsxPair(['code', 'name'], [['IDEMPOTENT-CROSS-001', 'Actor A']]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file1])
            ->assertOk()
            ->assertJsonPath('data.committed', true);

        $otherHeaders = $this->authHeadersForAnotherSkuManager();
        $second = $this->withHeaders($otherHeaders)
            ->post('/api/v1/skus/import-excel', ['file' => $file2, 'update_existing' => true]);

        // Actor khac -> khong duoc coi la duplicate cua actor dau; van chay that (update_existing=true nen khong loi).
        $second->assertOk();
        $this->assertNotTrue((bool) $second->json('data.duplicate'));
    }

    private function authHeadersForAnotherSkuManager(): array
    {
        $role = Role::create(['role_code' => 'SKU_MANAGER_330', 'role_name' => 'Sku manager 330', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $permission = Permission::firstOrCreate(
            ['permission_key' => 'sku.manage'],
            ['permission_name' => 'sku.manage', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $user = User::create([
            'username' => 'sku_manager_330',
            'display_name' => 'Sku manager 330',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function authHeadersWithoutSkuManage(): array
    {
        $role = Role::create(['role_code' => 'NO_SKU_MANAGE_330', 'role_name' => 'Test role', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $permission = Permission::firstOrCreate(
            ['permission_key' => 'warehouse.view'],
            ['permission_name' => 'warehouse.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $user = User::create([
            'username' => 'no_sku_manage_330',
            'display_name' => 'No sku manage',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    /** @return array{0: UploadedFile, 1: UploadedFile} */
    private function identicalXlsxPair(array $headers, array $rows): array
    {
        $first = $this->xlsx($headers, $rows);
        $copyPath = sys_get_temp_dir().'/wwhn330-copy-'.bin2hex(random_bytes(8)).'.xlsx';
        copy($first->getRealPath(), $copyPath);
        $this->tempFiles[] = $copyPath;

        $second = new UploadedFile(
            $copyPath,
            basename($copyPath),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        return [$first, $second];
    }

    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn330-'.bin2hex(random_bytes(8)).'.xlsx';
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

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }
}
