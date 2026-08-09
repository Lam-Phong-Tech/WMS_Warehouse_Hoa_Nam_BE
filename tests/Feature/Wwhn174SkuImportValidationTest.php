<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class Wwhn174SkuImportValidationTest extends TestCase
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

    public function test_import_maps_legacy_headers_to_current_sku_schema_and_commits_atomically(): void
    {
        $file = $this->xlsx(
            ['name', 'status', 'code', 'is_serial_tracked', 'sku_type'],
            [
                ['SKU nhập A', 'ACTIVE', 'IMP-A-001', 'true', 'PRODUCT'],
                ['Linh kiện B', 'ACTIVE', 'IMP-B-001', 'false', 'COMPONENT'],
            ]
        );

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.partial_write', false)
            ->assertJsonPath('data.transaction_mode', 'ALL_OR_NOTHING')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.inserted_rows', 2)
            ->assertJsonPath('data.header_map.name', 0)
            ->assertJsonPath('data.header_map.code', 2);

        $this->assertDatabaseHas('sku', [
            'sku_code' => 'IMP-A-001',
            'sku_name' => 'SKU nhập A',
            'is_serial_tracked' => true,
        ]);
        $this->assertDatabaseHas('sku', [
            'sku_code' => 'IMP-B-001',
            'sku_type' => 'COMPONENT',
        ]);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'SKU_IMPORT_CREATE')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SKU_IMPORT_COMMIT')->count());
    }

    public function test_any_duplicate_invalid_or_inactive_row_rejects_the_whole_file_without_side_effect(): void
    {
        Sku::query()->create([
            'sku_code' => 'INACTIVE-001',
            'sku_name' => 'SKU inactive',
            'status' => 'INACTIVE',
            'version_code' => 'V4',
        ]);

        $auditBefore = DB::table('audit_log')->count();
        $file = $this->xlsx(
            ['code', 'name', 'status', 'sku_type', 'published_to_app'],
            [
                ['VALID-BUT-ROLLBACK', 'Không được ghi', 'ACTIVE', 'PRODUCT', 'true'],
                ['DUP-FILE', 'Dòng đầu', 'ACTIVE', 'PRODUCT', 'false'],
                ['DUP-FILE', 'Dòng trùng', 'ACTIVE', 'PRODUCT', 'false'],
                ['INACTIVE-001', 'Không được kích hoạt', 'ACTIVE', 'PRODUCT', 'false'],
                ['INVALID-ENUM', 'Enum sai', 'UNKNOWN', 'PRODUCT', 'false'],
            ]
        );

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', [
                'file' => $file,
                'update_existing' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'SKU_IMPORT_VALIDATION_FAILED')
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.partial_write', false)
            ->assertJsonPath('data.inserted_rows', 0)
            ->assertJsonPath('data.updated_rows', 0);

        $this->assertDatabaseMissing('sku', ['sku_code' => 'VALID-BUT-ROLLBACK']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'DUP-FILE']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'INVALID-ENUM']);
        $this->assertDatabaseHas('sku', [
            'sku_code' => 'INACTIVE-001',
            'status' => 'INACTIVE',
            'version_code' => 'V4',
        ]);
        $this->assertSame($auditBefore, DB::table('audit_log')->count());
    }

    public function test_inactive_existing_sku_is_rejected_even_when_update_is_requested(): void
    {
        Sku::query()->create([
            'sku_code' => 'INACTIVE-ONLY-001',
            'sku_name' => 'Không được sửa',
            'status' => 'INACTIVE',
            'version_code' => 'V7',
        ]);
        $file = $this->xlsx(['code', 'name'], [['INACTIVE-ONLY-001', 'Tên mới không được ghi']]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'update_existing' => true])
            ->assertStatus(422)
            ->assertJsonPath('data.committed', false)
            ->assertJsonFragment(['code' => 'INACTIVE_SKU_CONFLICT']);

        $this->assertDatabaseHas('sku', [
            'sku_code' => 'INACTIVE-ONLY-001',
            'sku_name' => 'Không được sửa',
            'status' => 'INACTIVE',
            'version_code' => 'V7',
        ]);
    }

    public function test_unknown_header_and_invalid_boolean_are_rejected_without_silent_coercion(): void
    {
        $file = $this->xlsx(['code', 'name', 'unknown_column'], [['HEADER-001', 'Header invalid', 'x']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_FILE_INVALID')
            ->assertJsonPath('data.committed', false);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'HEADER-001']);

        $file = $this->xlsx(['code', 'name', 'published_to_app'], [['BOOL-001', 'Boolean invalid', 'maybe']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_VALIDATION_FAILED')
            ->assertJsonFragment(['code' => 'INVALID_BOOLEAN']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'BOOL-001']);
    }

    public function test_import_audit_failure_rolls_back_every_row_and_outbox(): void
    {
        $file = $this->xlsx(
            ['code', 'name'],
            [['IMPORT-AUDIT-A', 'A'], ['IMPORT-AUDIT-B', 'B']]
        );
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('forced import audit failure');
        });

        try {
            $response = $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]);
        } finally {
            Event::forget($eventName);
        }

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR')
            ->assertJsonMissingPath('trace');
        $this->assertDatabaseMissing('sku', ['sku_code' => 'IMPORT-AUDIT-A']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'IMPORT-AUDIT-B']);
        $this->assertSame(0, DB::table('audit_outbox')->count());
    }

    public function test_existing_active_sku_requires_explicit_update_and_maps_legacy_version_header(): void
    {
        Sku::query()->create([
            'sku_code' => 'UPDATE-001',
            'sku_name' => 'Tên cũ',
            'status' => 'ACTIVE',
            'version_code' => 'V3',
        ]);

        $file = $this->xlsx(['code', 'name', 'version'], [['UPDATE-001', 'Tên mới', 'V4']]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('data.committed', false);

        $this->assertDatabaseHas('sku', [
            'sku_code' => 'UPDATE-001',
            'sku_name' => 'Tên cũ',
            'version_code' => 'V3',
        ]);

        $file = $this->xlsx(['code', 'name', 'version'], [['UPDATE-001', 'Tên mới', 'V4']]);
        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'update_existing' => true])
            ->assertOk()
            ->assertJsonPath('data.updated_rows', 1)
            ->assertJsonPath('data.committed', true);

        $this->assertDatabaseHas('sku', [
            'sku_code' => 'UPDATE-001',
            'sku_name' => 'Tên mới',
            'version_code' => 'V4',
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'SKU_IMPORT_UPDATE')->count());
    }

    public function test_template_endpoint_returns_versioned_xlsx(): void
    {
        $response = $this->withHeaders($this->headers())
            ->get('/api/v1/skus/import-template');

        $response->assertOk();
        $this->assertStringContainsString(
            'WWHN-172-SKU-import-template-v1.1.0.xlsx',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertNotSame('', $response->getContent());
    }

    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn174-'.bin2hex(random_bytes(8)).'.xlsx';
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
