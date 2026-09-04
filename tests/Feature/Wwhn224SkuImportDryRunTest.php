<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\Sku;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-224 — dry-run "kiểm tra thử không ghi dữ liệu" cho import SKU, và Import báo cáo
 * (import_reports) làm output truy vết lâu dài cho FE1/QA.
 */
class Wwhn224SkuImportDryRunTest extends TestCase
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

    public function test_dry_run_with_all_new_valid_rows_writes_no_sku_but_persists_valid_report(): void
    {
        $file = $this->xlsx(['code', 'name', 'status'], [['DRY-NEW-001', 'SKU moi', 'ACTIVE']]);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.transaction_mode', 'DRY_RUN_NO_WRITE')
            ->assertJsonPath('data.would_insert_rows', 1)
            ->assertJsonPath('data.inserted_rows', 0)
            ->assertJsonPath('data.error_rows', 0);

        $reportId = $response->json('data.import_report_id');
        $this->assertNotNull($reportId);

        $this->assertDatabaseMissing('sku', ['sku_code' => 'DRY-NEW-001']);
        $this->assertDatabaseHas('import_reports', [
            'import_report_id' => $reportId,
            'mode' => 'DRY_RUN',
            'status' => 'VALID',
            'total_rows' => 1,
            'error_rows' => 0,
        ]);
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DRY-NEW-001')->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'SKU_IMPORT_CREATE')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SKU_IMPORT_DRY_RUN_CHECK')->count());
    }

    public function test_dry_run_with_invalid_rows_reports_errors_without_writing_sku(): void
    {
        Sku::query()->create([
            'sku_code' => 'DRY-INACTIVE-001',
            'sku_name' => 'SKU inactive',
            'status' => 'INACTIVE',
            'version_code' => 'V1',
        ]);

        $file = $this->xlsx(
            ['code', 'name', 'status'],
            [
                ['DRY-INACTIVE-001', 'Khong duoc kich hoat', 'ACTIVE'],
                ['DUP-DRY', 'Dong 1', 'ACTIVE'],
                ['DUP-DRY', 'Dong trung', 'ACTIVE'],
            ]
        );

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'SKU_IMPORT_DRY_RUN_REJECTED')
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.committed', false)
            ->assertJsonFragment(['code' => 'DUPLICATE_SKU_CODE_IN_FILE']);

        $reportId = $response->json('data.import_report_id');
        $this->assertDatabaseHas('import_reports', [
            'import_report_id' => $reportId,
            'mode' => 'DRY_RUN',
            'status' => 'REJECTED',
        ]);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'DUP-DRY']);
        $this->assertDatabaseHas('sku', ['sku_code' => 'DRY-INACTIVE-001', 'status' => 'INACTIVE']);
    }

    public function test_running_dry_run_twice_in_a_row_never_writes_sku_and_creates_two_reports(): void
    {
        $file1 = $this->xlsx(['code', 'name'], [['DRY-TWICE-001', 'Lan 1']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file1, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.committed', false);

        $file2 = $this->xlsx(['code', 'name'], [['DRY-TWICE-001', 'Lan 2']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file2, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.committed', false);

        $this->assertDatabaseMissing('sku', ['sku_code' => 'DRY-TWICE-001']);
        $this->assertSame(2, ImportReport::query()->where('mode', 'DRY_RUN')->count());
    }

    public function test_real_import_after_a_passing_dry_run_commits_successfully(): void
    {
        $dryFile = $this->xlsx(['code', 'name'], [['DRY-THEN-REAL-001', 'Truoc khi ghi that']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $dryFile, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.committed', false);

        $this->assertDatabaseMissing('sku', ['sku_code' => 'DRY-THEN-REAL-001']);

        $realFile = $this->xlsx(['code', 'name'], [['DRY-THEN-REAL-001', 'Da ghi that']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $realFile])
            ->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.inserted_rows', 1);

        $this->assertDatabaseHas('sku', ['sku_code' => 'DRY-THEN-REAL-001', 'sku_name' => 'Da ghi that']);
        $this->assertSame(1, ImportReport::query()->where('mode', 'COMMIT')->where('status', 'COMMITTED')->count());
    }

    public function test_real_import_without_dry_run_flag_still_commits_and_records_report_as_before(): void
    {
        $file = $this->xlsx(['code', 'name'], [['REG-COMMIT-001', 'Regression commit']]);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.transaction_mode', 'ALL_OR_NOTHING')
            ->assertJsonPath('data.inserted_rows', 1);

        $this->assertDatabaseHas('sku', ['sku_code' => 'REG-COMMIT-001']);
        $this->assertDatabaseHas('import_reports', [
            'mode' => 'COMMIT',
            'status' => 'COMMITTED',
            'inserted_rows' => 1,
        ]);
    }

    public function test_import_report_list_and_detail_endpoints_return_persisted_report(): void
    {
        $file = $this->xlsx(['code', 'name'], [['REPORT-LOOKUP-001', 'Tra cuu report']]);
        $create = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]);
        $reportId = $create->json('data.import_report_id');

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/skus/import-reports/{$reportId}")
            ->assertOk()
            ->assertJsonPath('data.import_report_id', $reportId)
            ->assertJsonPath('data.mode', 'DRY_RUN');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/skus/import-reports?mode=DRY_RUN&per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['import_report_id' => $reportId]);
    }

    public function test_import_report_detail_returns_404_for_unknown_id(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/skus/import-reports/'.Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_user_without_sku_manage_permission_is_blocked_from_dry_run_and_reports(): void
    {
        $headers = $this->authHeadersWithoutSkuManage();
        $file = $this->xlsx(['code', 'name'], [['NO-PERM-001', 'Khong co quyen']]);

        $this->withHeaders($headers)
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->withHeaders($headers)
            ->getJson('/api/v1/skus/import-reports')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    private function authHeadersWithoutSkuManage(): array
    {
        $role = Role::create(['role_code' => 'NO_SKU_MANAGE', 'role_name' => 'Test role', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $permission = Permission::firstOrCreate(
            ['permission_key' => 'warehouse.view'],
            ['permission_name' => 'warehouse.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);

        $user = User::create([
            'username' => 'no_sku_manage_224',
            'display_name' => 'No sku manage',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn224-'.bin2hex(random_bytes(8)).'.xlsx';
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
