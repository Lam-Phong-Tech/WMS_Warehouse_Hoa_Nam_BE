<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\AuditLog\Models\AuditLog;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\ImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SkuImportTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    public function test_mixed_valid_and_invalid_rows_roll_back_the_entire_import(): void
    {
        $result = app(ImportService::class)->import($this->spreadsheetPath([
            ['SKU-VALID', 'Valid SKU', 'pcs', 'PRODUCT', '', '', '', '', 'BARE', 'true', 'false', 'ACTIVE'],
            ['SKU-INVALID', 'Invalid SKU', 'pcs', 'UNKNOWN', '', '', '', '', 'BARE', 'true', 'false', 'ACTIVE'],
        ]));

        $this->assertSame(1, $result['error_rows']);
        $this->assertSame('INVALID_SKU_TYPE', $result['row_errors'][0]['code']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'SKU-VALID']);
    }

    public function test_duplicate_database_code_rolls_back_new_rows(): void
    {
        Sku::create(['sku_code' => 'SKU-EXISTS', 'sku_name' => 'Existing']);

        $result = app(ImportService::class)->import($this->spreadsheetPath([
            ['SKU-NEW', 'New SKU'],
            ['SKU-EXISTS', 'Duplicate SKU'],
        ]));

        $this->assertSame(1, $result['error_rows']);
        $this->assertSame('DUPLICATE_CODE', $result['row_errors'][0]['code']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'SKU-NEW']);
    }

    public function test_inactive_status_is_preserved_and_invalid_boolean_is_rejected(): void
    {
        $valid = app(ImportService::class)->import($this->spreadsheetPath([
            ['SKU-INACTIVE', 'Inactive SKU', 'pcs', 'COMPONENT', '', '', '', '', 'BARE', 'false', '0', 'INACTIVE'],
        ]));

        $this->assertSame(0, $valid['error_rows']);
        $this->assertDatabaseHas('sku', ['sku_code' => 'SKU-INACTIVE', 'status' => 'INACTIVE', 'published_to_app' => 0]);

        $invalid = app(ImportService::class)->import($this->spreadsheetPath([
            ['SKU-BAD-BOOL', 'Bad boolean', 'pcs', 'PRODUCT', '', '', '', '', 'BARE', 'sometimes', 'false', 'ACTIVE'],
        ]));

        $this->assertSame('INVALID_PUBLISHED_TO_APP', $invalid['row_errors'][0]['code']);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'SKU-BAD-BOOL']);
    }

    public function test_failed_import_does_not_write_an_audit_record(): void
    {
        Storage::fake();
        $path = $this->spreadsheetPath([
            ['SKU-AUDIT-FAIL', 'Audit failure', 'pcs', 'BAD_ENUM'],
        ]);

        $response = $this->post('/api/v1/skus/import-excel', [
            'file' => UploadedFile::fake()->createWithContent('sku.xlsx', file_get_contents($path)),
        ]);

        $response->assertOk()->assertJsonPath('data.error_rows', 1);
        $this->assertSame(0, AuditLog::where('action', 'IMPORT')->count());
        $this->assertDatabaseMissing('sku', ['sku_code' => 'SKU-AUDIT-FAIL']);
    }

    private function spreadsheetPath(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([
            ['sku_code', 'sku_name', 'unit', 'sku_type', 'brand_code', 'category_code', 'model_code', 'power_or_voltage', 'package_variant', 'published_to_app', 'is_serial_tracked', 'status'],
        ], $rows));

        $path = tempnam(sys_get_temp_dir(), 'sku-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
