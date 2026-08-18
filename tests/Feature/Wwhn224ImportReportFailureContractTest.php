<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * DEV224-P1-05 regression contract.
 *
 * Proves the mandatory-report atomicity rule: no request may report success
 * after ImportReport persistence fails, and a COMMIT attempt must leave
 * neither SKU nor business audit behind (outer transaction rollback).
 */
class Wwhn224ImportReportFailureContractTest extends TestCase
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

    public function test_dry_run_report_write_failure_returns_503_and_rolls_back_dry_run_audit(): void
    {
        $eventName = 'eloquent.creating: '.ImportReport::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('forced import report failure');
        });

        try {
            $file = $this->xlsx(['code', 'name'], [['REPORT-FAIL-DRY', 'Dry-run failure']]);
            $response = $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]);
        } finally {
            Event::forget($eventName);
        }

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED')
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.partial_write', false)
            ->assertJsonPath('data.import_report_id', null)
            ->assertJsonPath('data.retryable', true)
            ->assertJsonMissingPath('trace');

        $this->assertDatabaseMissing('sku', ['sku_code' => 'REPORT-FAIL-DRY']);
        $this->assertSame(0, ImportReport::query()->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'SKU_IMPORT_DRY_RUN_CHECK')->count());
    }

    public function test_commit_report_write_failure_rolls_back_sku_and_business_audit(): void
    {
        $eventName = 'eloquent.creating: '.ImportReport::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('forced import report failure');
        });

        try {
            $file = $this->xlsx(['code', 'name'], [['REPORT-FAIL-COMMIT', 'Commit failure']]);
            $response = $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]);
        } finally {
            Event::forget($eventName);
        }

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED')
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.partial_write', false)
            ->assertJsonPath('data.import_report_id', null)
            ->assertJsonPath('data.retryable', true);

        $this->assertDatabaseMissing('sku', ['sku_code' => 'REPORT-FAIL-COMMIT']);
        $this->assertSame(0, ImportReport::query()->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'SKU_IMPORT_CREATE')->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'SKU_IMPORT_COMMIT')->count());
    }

    public function test_permission_denied_response_no_longer_mentions_product_manage(): void
    {
        $headers = $this->authHeadersWithoutSkuManage();
        $file = $this->xlsx(['code', 'name'], [['NO-PERM-002', 'Khong co quyen']]);

        $this->withHeaders($headers)
            ->post('/api/v1/skus/import-excel', ['file' => $file])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    private function authHeadersWithoutSkuManage(): array
    {
        $role = \Modules\Auth\Models\Role::create(['role_code' => 'NO_SKU_MANAGE_224B', 'role_name' => 'Test role', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $permission = \Modules\Auth\Models\Permission::firstOrCreate(
            ['permission_key' => 'warehouse.view'],
            ['permission_name' => 'warehouse.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) \Illuminate\Support\Str::uuid()]);

        $user = User::create([
            'username' => 'no_sku_manage_224b',
            'display_name' => 'No sku manage',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) \Illuminate\Support\Str::uuid()]);

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

        $path = sys_get_temp_dir().'/wwhn224-p1-'.bin2hex(random_bytes(8)).'.xlsx';
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
