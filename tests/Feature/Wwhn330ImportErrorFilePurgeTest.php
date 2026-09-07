<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;
use Tests\TestCase;

/**
 * WWHN-330 (DEV330-P1-01 gap): `import:purge-error-files` phải xoá file CSV lỗi đã hết
 * hạn khỏi storage và null hoá error_file_path/error_file_expires_at, để "hết hạn"
 * nhất quán ở cả DB lẫn filesystem — trước đây file vật lý không bao giờ bị xoá.
 */
class Wwhn330ImportErrorFilePurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_deletes_expired_error_file_and_clears_report_path(): void
    {
        $disk = Storage::disk(ImportErrorFileService::diskName());
        $relativePath = ImportErrorFileService::STORAGE_SUBDIR.'/expired-report.csv';
        $disk->put($relativePath, "row,field,error_code,message,value\n2,sku_code,X,Y,Z\n");

        $report = ImportReport::query()->create([
            'import_type' => 'SKU',
            'mode' => 'COMMIT',
            'status' => 'REJECTED',
            'file_name' => 'expired.xlsx',
            'file_sha256' => str_repeat('a', 64),
            'total_rows' => 1,
            'valid_rows' => 0,
            'error_rows' => 1,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'row_errors' => [],
            'error_file_path' => $relativePath,
            'error_file_expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($disk->exists($relativePath));

        $this->artisan('import:purge-error-files')->assertExitCode(0);

        $this->assertFalse($disk->exists($relativePath));
        $report->refresh();
        $this->assertNull($report->error_file_path);
        $this->assertNull($report->error_file_expires_at);
    }

    public function test_purge_leaves_non_expired_error_file_untouched(): void
    {
        $disk = Storage::disk(ImportErrorFileService::diskName());
        $relativePath = ImportErrorFileService::STORAGE_SUBDIR.'/fresh-report.csv';
        $disk->put($relativePath, "row,field,error_code,message,value\n2,sku_code,X,Y,Z\n");

        $report = ImportReport::query()->create([
            'import_type' => 'SKU',
            'mode' => 'COMMIT',
            'status' => 'REJECTED',
            'file_name' => 'fresh.xlsx',
            'file_sha256' => str_repeat('b', 64),
            'total_rows' => 1,
            'valid_rows' => 0,
            'error_rows' => 1,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'row_errors' => [],
            'error_file_path' => $relativePath,
            'error_file_expires_at' => now()->addHours(12),
        ]);

        $this->artisan('import:purge-error-files')->assertExitCode(0);

        $this->assertTrue($disk->exists($relativePath));
        $report->refresh();
        $this->assertNotNull($report->error_file_path);
        $this->assertNotNull($report->error_file_expires_at);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $disk = Storage::disk(ImportErrorFileService::diskName());
        $relativePath = ImportErrorFileService::STORAGE_SUBDIR.'/dry-run-report.csv';
        $disk->put($relativePath, "row,field,error_code,message,value\n2,sku_code,X,Y,Z\n");

        ImportReport::query()->create([
            'import_type' => 'SKU',
            'mode' => 'COMMIT',
            'status' => 'REJECTED',
            'file_name' => 'dry-run.xlsx',
            'file_sha256' => str_repeat('c', 64),
            'total_rows' => 1,
            'valid_rows' => 0,
            'error_rows' => 1,
            'inserted_rows' => 0,
            'updated_rows' => 0,
            'row_errors' => [],
            'error_file_path' => $relativePath,
            'error_file_expires_at' => now()->subDay(),
        ]);

        $this->artisan('import:purge-error-files --dry-run')->assertExitCode(0);

        $this->assertTrue($disk->exists($relativePath));
    }
}
