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
 * DEV224-P1-02/P1-05 regression contract.
 *
 * Proves checksum reconciliation and the mandatory-report atomicity rule:
 * no request may report success after ImportReport persistence fails, and a
 * COMMIT attempt must leave neither SKU nor business audit behind.
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

    public function test_response_and_report_reconcile_to_exact_uploaded_file_sha256(): void
    {
        $file = $this->xlsx(
            ['code', 'name', 'published_to_app'],
            [['CHK-001', 'Checksum fixture', 'true']]
        );
        $expectedSha = hash_file('sha256', (string) $file->getRealPath());
        $this->assertIsString($expectedSha);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]);

        $response->assertOk()
            ->assertJsonPath('data.file_sha256', $expectedSha)
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.dry_run', true);

        $reportId = (string) $response->json('data.import_report_id');
        $this->assertNotSame('', $reportId);
        $this->assertDatabaseHas('import_reports', [
            'import_report_id' => $reportId,
            'file_sha256' => $expectedSha,
            'mode' => 'DRY_RUN',
            'status' => 'VALID',
        ]);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'CHK-001']);
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

    public function test_validation_errors_keep_exact_row_field_code_message_and_no_business_side_effect(): void
    {
        $file = $this->xlsx(
            ['code', 'name', 'status', 'published_to_app'],
            [['ROW-ERR-001', 'Invalid row', 'NOT_A_STATUS', 'maybe']]
        );

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_DRY_RUN_REJECTED')
            ->assertJsonFragment([
                'row' => 2,
                'code' => 'INVALID_ENUM',
                'field' => 'status',
                'message' => 'status phải thuộc ACTIVE, INACTIVE.',
                'value' => 'NOT_A_STATUS',
            ])
            ->assertJsonFragment([
                'row' => 2,
                'code' => 'INVALID_BOOLEAN',
                'field' => 'published_to_app',
                'message' => 'published_to_app không hợp lệ.',
                'value' => 'maybe',
            ]);

        $reportId = (string) $response->json('data.import_report_id');
        $this->assertNotSame('', $reportId);
        $this->assertDatabaseHas('import_reports', [
            'import_report_id' => $reportId,
            'status' => 'REJECTED',
            'mode' => 'DRY_RUN',
            'error_rows' => 2,
        ]);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'ROW-ERR-001']);
        $this->assertSame(0, DB::table('audit_log')->whereIn('action', [
            'SKU_IMPORT_CREATE',
            'SKU_IMPORT_UPDATE',
            'SKU_IMPORT_COMMIT',
        ])->count());
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
