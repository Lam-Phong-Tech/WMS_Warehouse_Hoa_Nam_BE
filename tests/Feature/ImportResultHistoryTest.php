<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

class ImportResultHistoryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $actor;

    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(AuthDatabaseSeeder::class);
        $this->actor = User::where('email', 'test-super-admin@gmail.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($this->actor);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Danh mục SKU');
        $book->getActiveSheet()->fromArray([
            ['code', 'name', 'status'],
            ['HISTORY-001', 'Máy khoan', 'ACTIVE'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'history-');
        (new Xlsx($book))->save($tmp);
        $this->bytes = file_get_contents($tmp);
        unlink($tmp);
        $book->disconnectWorksheets();
    }

    private function upload(bool $dryRun = true, ?string $bytes = null): string
    {
        $response = $this->withToken($this->token)->post('/api/v1/skus/import-excel', [
            'file' => UploadedFile::fake()->createWithContent('same-name.xlsx', $bytes ?? $this->bytes),
            'dry_run' => $dryRun,
            'update_existing' => false,
        ], ['Accept' => 'application/json']);
        $response->assertOk();

        return $response->json('data.import_report_id');
    }

    public function test_upload_preserves_source_metadata_and_separates_planned_from_written_counts(): void
    {
        $id = $this->upload();
        $data = $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$id)
            ->assertOk()
            ->assertJsonPath('data.file_size_bytes', strlen($this->bytes))
            ->assertJsonPath('data.sheet_name', 'Danh mục SKU')
            ->assertJsonPath('data.uploaded_by_name', $this->actor->display_name)
            ->assertJsonPath('data.uploaded_by_account', $this->actor->username)
            ->assertJsonPath('data.result_version', 1)
            ->assertJsonPath('data.update_existing', false)
            ->assertJsonPath('data.source_file_available', true)
            ->assertJsonPath('data.actual_inserted_rows', 0)
            ->assertJsonPath('data.not_written_rows', 1)
            ->assertJsonPath('data.planned_inserted_rows', 1)
            ->json('data');
        $this->assertNotNull($data['uploaded_at']);
        $this->assertNotNull($data['completed_at']);
        $this->assertGreaterThanOrEqual(0, $data['processing_duration_ms']);
        $this->assertArrayNotHasKey('file_path', $data);
        $this->assertArrayNotHasKey('source_file', $data);
    }

    public function test_identical_bytes_append_versions_but_same_filename_with_different_bytes_is_separate(): void
    {
        $first = $this->upload();
        $second = $this->upload();
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$first.'/versions')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.import_report_id', $second)
            ->assertJsonPath('data.0.result_version', 2)
            ->assertJsonPath('data.1.result_version', 1);
        // A ZIP comment changes the bytes without changing the workbook or its filename.
        $third = $this->upload(bytes: $this->bytes.'different-content');
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$third.'/versions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.result_version', 1);
    }

    public function test_recheck_retains_original_snapshot_and_never_rewrites_sku(): void
    {
        $id = $this->upload(false);
        $original = ImportReport::findOrFail($id)->getAttributes();
        $sku = Sku::where('sku_code', 'HISTORY-001')->firstOrFail();
        $sku->update(['sku_name' => 'Tên đã sửa sau khi nhập']);
        $response = $this->withToken($this->token)->postJson('/api/v1/skus/import-reports/'.$id.'/recheck');
        $response->assertOk()->assertJsonPath('data.mode', 'DRY_RUN')
            ->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.result_version', 2)
            ->assertJsonPath('data.actual_inserted_rows', 0)->assertJsonPath('data.actual_updated_rows', 0);
        $this->assertSame($original, ImportReport::findOrFail($id)->getAttributes());
        $this->assertSame('Tên đã sửa sau khi nhập', $sku->fresh()->sku_name);
        $this->assertNotSame($id, $response->json('data.import_report_id'));
        $this->assertTrue($response->json('data.error_file_available'));
        // An ordinary duplicate COMMIT remains idempotent and does not allocate v3.
        $this->assertSame($id, $this->upload(false));
        $this->assertSame(2, ImportReport::count());
    }

    public function test_downloads_return_exact_original_bytes_and_only_selected_result(): void
    {
        $first = $this->upload();
        $second = $this->upload();
        $source = $this->withToken($this->token)->get('/api/v1/skus/import-reports/'.$first.'/source-file');
        $source->assertOk();
        $this->assertSame($this->bytes, $source->streamedContent());
        $export = $this->withToken($this->token)->get('/api/v1/skus/import-reports/'.$second.'/result-file');
        $export->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'history-result-');
        file_put_contents($tmp, $export->streamedContent());
        try {
            $book = IOFactory::load($tmp);
            $cells = json_encode($book->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString($second, $cells);
            $this->assertStringNotContainsString($first, $cells);
            $this->assertStringContainsString('same-name.xlsx', $cells);
            $book->disconnectWorksheets();
        } finally {
            unlink($tmp);
        }
    }

    public function test_missing_source_is_explicit_and_recheck_does_not_add_a_report(): void
    {
        $id = $this->upload();
        Storage::disk('local')->deleteDirectory('import-sources');
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$id)
            ->assertOk()->assertJsonPath('data.source_file_available', false);
        $this->withToken($this->token)->postJson('/api/v1/skus/import-reports/'.$id.'/recheck')->assertNotFound();
        $this->assertSame(1, ImportReport::count());
    }

    public function test_error_file_expiry_reason_is_decided_by_server(): void
    {
        $id = $this->upload();
        ImportReport::findOrFail($id)->forceFill(['error_file_path' => 'expired.csv', 'error_file_expires_at' => now()->subMinute()])->save();
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$id)
            ->assertOk()->assertJsonPath('data.error_file_available', false)->assertJsonPath('data.error_file_status', 'EXPIRED');
    }

    public function test_result_counts_distinct_rows_instead_of_counting_multiple_field_errors_as_rows(): void
    {
        $report = ImportReport::create([
            'import_type' => 'SKU', 'mode' => 'DRY_RUN', 'status' => 'REJECTED',
            'file_sha256' => str_repeat('f', 64), 'total_rows' => 3, 'valid_rows' => 1, 'error_rows' => 4,
            'row_errors' => [
                ['row' => 2, 'code' => 'DUPLICATE_CODE', 'field' => 'sku_code'],
                ['row' => 2, 'code' => 'DUPLICATE_IN_FILE', 'field' => 'sku_name'],
                ['row' => 3, 'code' => 'REQUIRED', 'field' => 'sku_name'],
                ['row' => 3, 'code' => 'INVALID_STATUS', 'field' => 'status'],
            ],
        ]);
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$report->getKey())
            ->assertOk()->assertJsonPath('data.row_counts', ['valid' => 1, 'duplicate' => 1, 'invalid' => 1]);
    }

    public function test_legacy_migration_numbers_history_without_fabricating_upload_timing_and_recovers_known_options(): void
    {
        $migration = require base_path('Modules/Warehouse/database/migrations/2026_11_14_000100_add_import_result_history.php');
        $migration->down();
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ($ids as $index => $id) {
            DB::table('import_reports')->insert([
                'import_report_id' => $id, 'import_type' => 'SKU', 'mode' => 'DRY_RUN', 'status' => 'VALID',
                'file_name' => 'same-name.xlsx', 'file_sha256' => hash('sha256', $this->bytes),
                'actor_id' => $this->actor->getKey(), 'total_rows' => 1, 'valid_rows' => 1, 'error_rows' => 0,
                'options_fingerprint' => SkuImportContract::optionsFingerprint(false),
                'created_at' => '2026-11-10 0'.($index + 1).':00:00', 'updated_at' => '2026-11-10 0'.($index + 1).':00:00',
            ]);
        }
        $migration->up();
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$ids[1])
            ->assertOk()->assertJsonPath('data.result_version', 2)
            ->assertJsonPath('data.update_existing', false)
            ->assertJsonPath('data.uploaded_at', null)
            ->assertJsonPath('data.file_size_bytes', null)
            ->assertJsonPath('data.processing_duration_ms', null)
            ->assertJsonPath('data.source_file_status', 'NOT_RETAINED');
        $third = $this->upload();
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$ids[1])
            ->assertOk()->assertJsonPath('data.source_file_available', true);
        $this->withToken($this->token)->getJson('/api/v1/skus/import-reports/'.$third.'/versions')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.result_version', 3);
        $this->withToken($this->token)->postJson('/api/v1/skus/import-reports/'.$ids[0].'/recheck')
            ->assertOk()->assertJsonPath('data.result_version', 4)->assertJsonPath('data.mode', 'DRY_RUN');
    }

    public function test_foreign_actor_cannot_read_download_or_recheck_and_permission_is_required(): void
    {
        $id = $this->upload();
        $permission = Permission::where('permission_key', 'sku.manage')->firstOrFail();
        $role = Role::create(['role_code' => 'HISTORY_SCOPED', 'role_name' => 'Scoped', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $role->permissions()->attach($permission->getKey(), ['role_permission_id' => (string) Str::uuid()]);
        $other = User::create(['username' => 'history-scoped', 'display_name' => 'Other', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $other->roles()->attach($role->getKey(), ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) Str::uuid()]);
        foreach (['versions', 'source-file', 'result-file'] as $suffix) {
            $this->withToken(JWTAuth::fromUser($other))->getJson('/api/v1/skus/import-reports/'.$id.'/'.$suffix)->assertNotFound();
        }
        $this->withToken(JWTAuth::fromUser($other))->postJson('/api/v1/skus/import-reports/'.$id.'/recheck')->assertNotFound();
        $other = User::create(['username' => 'history-no-permission', 'display_name' => 'No permission', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        foreach (['versions', 'source-file', 'result-file'] as $suffix) {
            $this->withToken(JWTAuth::fromUser($other))->getJson('/api/v1/skus/import-reports/'.$id.'/'.$suffix)->assertForbidden();
        }
        $this->withToken(JWTAuth::fromUser($other))->postJson('/api/v1/skus/import-reports/'.$id.'/recheck')->assertForbidden();
    }
}
