<?php

namespace Tests\Feature;

use App\Support\Migration\FinalDataLoadService;
use App\Support\Migration\ImportPackage;
use App\Support\Migration\MigrationErrorFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-260 — đóng register P1 của gói migration (SRS §13.12, NFR-SRS-017,
 * REQ-SRS-021/034, ERD bảng 58-59 + ERD-AMD-007).
 *
 * Test gọi thẳng `FinalDataLoadService` thay vì qua console command: cổng quyền
 * của command thuộc WWHN-299 và đang được siết riêng, còn các bất biến dưới đây
 * là hợp đồng **dữ liệu** nên phải đúng bất kể lớp vận hành thay đổi thế nào.
 */
class Wwhn260MigrationPackClosureTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    private string $operatorId;

    private string $approverId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureActiveWarehouse('HN-WH-01', 'Kho tổng Hòa Nam');
        $this->operatorId = (string) $this->makeUser('cutover-operator-260')->getKey();
        $this->approverId = (string) $this->makeUser('data-owner-260')->getKey();

        $this->packagePath = storage_path('framework/testing/wwhn260-'.uniqid());
        $this->writePackage();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->packagePath);
        parent::tearDown();
    }

    /** SRS §13.12: valid + invalid + duplicate = input row count. */
    public function test_dry_run_splits_duplicate_rows_from_invalid_rows_and_the_three_counts_sum_to_input(): void
    {
        // 1 dòng trùng mã (chỉ trùng) + 1 dòng sai enum (không trùng).
        $this->writeFile('sku.csv',
            "sku_code,sku_name,sku_type,unit,is_serial_tracked,version_code,status\n".
            "HN-MK-001-V1,Máy khoan mẫu,PRODUCT,cái,TRUE,V1,ACTIVE\n".
            "HN-PK-002-V1,Phụ kiện mẫu,COMPONENT,cái,FALSE,V1,ACTIVE\n".
            "HN-MK-001-V1,Máy khoan trùng,PRODUCT,cái,TRUE,V1,ACTIVE\n".
            "HN-XX-003-V1,Sai loại,GADGET,cái,TRUE,V1,ACTIVE\n");

        $report = $this->service()->dryRun($this->package(), 'WWHN260-COUNTS', $this->operatorId);

        $this->assertSame('FAILED', $report['status']);
        $counts = $report['counts'];
        $this->assertSame(1, $counts['duplicate'], 'Dòng trùng phải được đếm riêng.');
        $this->assertSame(1, $counts['invalid'], 'Dòng sai enum không được gộp vào nhóm trùng.');
        $this->assertTrue($counts['invariant_holds']);
        $this->assertSame(
            $counts['total'],
            $counts['valid'] + $counts['invalid'] + $counts['duplicate'],
            'valid + invalid + duplicate phải bằng đúng số dòng input.',
        );

        // ERD-AMD-007: ba con số phải kiểm lại được từ DB, không chỉ từ báo cáo.
        $run = DB::table('migration_runs')->where('migration_run_code', 'WWHN260-COUNTS#DRY_RUN')->first();
        $this->assertSame(1, (int) $run->duplicate_rows);
        $this->assertSame(1, (int) $run->error_rows);
        $this->assertSame(
            (int) $run->total_rows,
            (int) $run->valid_rows + (int) $run->error_rows + (int) $run->duplicate_rows,
        );
    }

    /** DEV260-P1-06: cùng package không được nạp hai lần dưới hai run code. */
    public function test_the_same_package_cannot_be_committed_twice_under_a_different_run_code(): void
    {
        $this->service()->dryRun($this->package(), 'WWHN260-FIRST', $this->operatorId);
        $first = $this->service()->commit($this->package(), 'WWHN260-FIRST', $this->operatorId, $this->approverId);
        $this->assertSame('COMMITTED', $first['status']);

        $stockAfterFirst = (int) DB::table('stock_movements')->sum('qty_delta');

        $this->service()->dryRun($this->package(), 'WWHN260-SECOND', $this->operatorId);
        $second = $this->service()->commit($this->package(), 'WWHN260-SECOND', $this->operatorId, $this->approverId);

        $this->assertSame('BLOCKED', $second['status']);
        $this->assertSame('PACKAGE_ALREADY_COMMITTED', $second['error_code']);
        $this->assertSame($stockAfterFirst, (int) DB::table('stock_movements')->sum('qty_delta'),
            'Lần nạp thứ hai không được cộng thêm bất kỳ tồn nào.');
    }

    /** SRS §13.12: "container mapping creates no extra stock". */
    public function test_committed_reconciliation_proves_container_mapping_creates_no_extra_stock(): void
    {
        $this->service()->dryRun($this->package(), 'WWHN260-CONTAINER', $this->operatorId);
        $report = $this->service()->commit($this->package(), 'WWHN260-CONTAINER', $this->operatorId, $this->approverId);

        $this->assertSame('COMMITTED', $report['status']);
        $checks = collect($report['reconciliation']['checks']);

        $noExtraStock = $checks->firstWhere('check', 'CONTAINER_MAPPING_NO_EXTRA_STOCK');
        $this->assertNotNull($noExtraStock, 'Đối soát commit phải có check container không sinh thêm tồn.');
        $this->assertSame('PASS', $noExtraStock['status']);
        $this->assertSame(3, $noExtraStock['expected'], 'Tổng lượng nguồn của package mẫu là 3.');
        $this->assertSame(3, $noExtraStock['actual']);

        $openingOnly = $checks->firstWhere('check', 'RUN_WRITES_ONLY_OPENING_MOVEMENTS');
        $this->assertSame('PASS', $openingOnly['status']);
        $this->assertSame(1, DB::table('containers')->whereNotNull('migration_run_id')->count());
    }

    /** DEV260-P1-03: dataset SKU của §13.12 gồm cả brand/category/model. */
    public function test_sku_brand_category_model_columns_are_mapped_to_master_foreign_keys(): void
    {
        $this->seedClassificationMasters();
        $this->writeSkuFileWithClassification('HN-BRAND-01', 'HN-CAT-01', 'HN-MODEL-01');

        $this->service()->dryRun($this->package(), 'WWHN260-CLASS', $this->operatorId);
        $report = $this->service()->commit($this->package(), 'WWHN260-CLASS', $this->operatorId, $this->approverId);
        $this->assertSame('COMMITTED', $report['status']);

        $sku = DB::table('sku')->where('sku_code', 'HN-MK-001-V1')->first();
        $this->assertSame(
            DB::table('brands')->where('brand_code', 'HN-BRAND-01')->value('id'),
            $sku->brand_id,
            'Migration phải map brand_code sang FK master thật.',
        );
        $this->assertSame(DB::table('product_categories')->where('category_code', 'HN-CAT-01')->value('id'), $sku->category_id);
        $this->assertSame(DB::table('product_models')->where('model_code', 'HN-MODEL-01')->value('id'), $sku->model_id);
    }

    public function test_unknown_classification_code_is_rejected_as_unknown_fk_instead_of_silently_dropped(): void
    {
        $this->seedClassificationMasters();
        $this->writeSkuFileWithClassification('HN-BRAND-KHONG-CO', 'HN-CAT-01', 'HN-MODEL-01');

        $report = $this->service()->dryRun($this->package(), 'WWHN260-CLASS-FK', $this->operatorId);

        $this->assertSame('FAILED', $report['status']);
        $this->assertContains('UNKNOWN_FK', array_column($report['errors'], 'error_code'));
        $this->assertContains('brand_code', array_column($report['errors'], 'field_name'));
    }

    /** DEV260-P1-10: QA dựng lại kết luận từ DB, không tin báo cáo của job. */
    public function test_verify_run_independently_confirms_a_committed_run(): void
    {
        $this->service()->dryRun($this->package(), 'WWHN260-VERIFY', $this->operatorId);
        $this->service()->commit($this->package(), 'WWHN260-VERIFY', $this->operatorId, $this->approverId);

        $output = storage_path('framework/testing/wwhn260-verify-'.uniqid().'.json');
        $this->artisan('migration:verify-run', [
            '--run-code' => 'WWHN260-VERIFY',
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--output' => $output,
        ])->assertExitCode(0);

        $report = json_decode((string) file_get_contents($output), true);
        @unlink($output);

        $this->assertSame('PASS', $report['verdict']);
        $names = array_column($report['checks'], 'check');
        foreach ([
            'RUN_REACHED_EXPECTED_TERMINAL_STATUS',
            'RECONCILIATION_PASSED',
            'RUN_RECORDS_SOURCE_VERSION_CHECKSUM_AND_ID',
            'VALID_PLUS_INVALID_PLUS_DUPLICATE_EQUALS_INPUT',
            'PACKAGE_CHECKSUM_RECOMPUTED',
            'AT_MOST_ONE_LIVE_COMMIT_PER_CHECKSUM',
            'BALANCE_EQUALS_LEDGER',
            'SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT',
            'CONTAINER_MAPPING_NO_EXTRA_STOCK',
        ] as $expected) {
            $this->assertContains($expected, $names, "Thiếu check {$expected} trong báo cáo xác minh.");
        }
        $this->assertNotNull($report['timing']['duration_ms']);
    }

    public function test_verify_run_fails_when_stored_counts_no_longer_match_the_error_table(): void
    {
        $this->service()->dryRun($this->package(), 'WWHN260-TAMPER', $this->operatorId);
        DB::table('migration_runs')
            ->where('migration_run_code', 'WWHN260-TAMPER#DRY_RUN')
            ->update(['error_rows' => 7]);

        $this->artisan('migration:verify-run', [
            '--run-code' => 'WWHN260-TAMPER',
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
        ])->assertExitCode(1);
    }

    /** DEV260-P1-04 / P1-11: dòng bị loại phải có lý do, chủ dữ liệu và cách xử lý. */
    public function test_rejected_rows_are_exported_with_owner_remediation_and_masked_pii(): void
    {
        $this->writeFile('agency.csv',
            "agency_code,agency_name,phone,address,status\n".
            "AG-900,Đại lý 900,0909009900,Hà Nội,ACTIVE\n".
            "AG-901,,0912345678,Hải Phòng,KHONG_HOP_LE\n");

        $this->service()->dryRun($this->package(), 'WWHN260-REJECT', $this->operatorId);

        $errorFile = storage_path('framework/testing/wwhn260-rejected-'.uniqid().'.csv');
        $this->artisan('migration:verify-run', [
            '--run-code' => 'WWHN260-REJECT',
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--error-file' => $errorFile,
        ]);

        $csv = (string) file_get_contents($errorFile);
        @unlink($errorFile);

        $header = explode(',', trim(strtok(ltrim($csv, "\xEF\xBB\xBF"), "\n")));
        $this->assertSame(MigrationErrorFile::COLUMNS, $header,
            'Error file phải đúng thứ tự cột của SRS §13.12 rồi mới tới cột mở rộng.');
        $this->assertStringContainsString('INVALID_ENUM', $csv);
        $this->assertStringContainsString((string) config('migration_mapping.dataset_owners.AGENCY'), $csv,
            'Mỗi dòng bị loại phải chỉ ra chủ dữ liệu chịu trách nhiệm remediation.');
        $this->assertStringNotContainsString('KHONG_HOP_LE,,', $csv);
    }

    /** DEV260-P1-04: evidence phải tự nói nguồn/phiên bản/checksum/thời lượng. */
    public function test_dry_run_report_pins_source_version_checksum_mapping_and_measured_duration(): void
    {
        $report = $this->service()->dryRun($this->package(), 'WWHN260-EVIDENCE', $this->operatorId);

        $this->assertSame('1.2', $report['package_version']);
        $this->assertSame((string) config('migration_mapping.mapping_version'), $report['mapping_version']);
        $this->assertSame('HoaNam_Import_Package_v1.2.zip', $report['source_file_name']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['source_checksum']);
        $this->assertSame(
            ['AGENCY', 'CONTAINER', 'ITEM', 'OPENING_BALANCE', 'PHYSICAL_CODE', 'SKU'],
            array_keys($report['file_checksums']),
        );
        $this->assertNotNull($report['timing']['duration_ms']);
        $this->assertNotNull($report['timing']['started_at']);
    }

    /** NFR-SRS-017: dry-run tối thiểu 10.000 dòng/file. */
    public function test_dry_run_handles_the_documented_capacity_of_ten_thousand_rows_per_file(): void
    {
        $rows = (int) config('migration_mapping.capacity.min_rows_per_file', 10000);

        $sku = "sku_code,sku_name,sku_type,unit,is_serial_tracked,version_code,status\n";
        for ($i = 1; $i <= $rows; $i++) {
            $sku .= sprintf("HN-CAP-%06d,SKU dung luong %d,COMPONENT,cái,FALSE,V1,ACTIVE\n", $i, $i);
        }
        $this->writeFile('sku.csv', $sku);
        $this->writeFile('container.csv', "container_code,type,carrier,physical_code,primary_sku,parent_tray,status\n");
        $this->writeFile('item.csv', "legacy_item_key,sku_code,serial_number,code_value,condition,import_batch\n");
        $this->writeFile('opening_balance.csv',
            "warehouse_code,sku_code,stock_status,quantity,source,owner_confirmation\n".
            "HN-WH-01,HN-CAP-000001,AVAILABLE,5,CUTOVER_COUNT,QL_KHO\n");

        $report = $this->service()->dryRun($this->package(), 'WWHN260-CAPACITY', $this->operatorId);

        $this->assertSame('PASSED', $report['status']);
        $this->assertSame($rows, $report['counts']['by_dataset']['SKU']);
        $this->assertSame(0, $report['counts']['invalid']);
        $this->assertTrue($report['counts']['invariant_holds']);
    }

    // ----------------------------------------------------------------- helpers

    private function service(): FinalDataLoadService
    {
        return app(FinalDataLoadService::class);
    }

    private function package(): ImportPackage
    {
        return ImportPackage::load($this->packagePath);
    }

    private function makeUser(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'display_name' => $username,
            'email' => $username.'@hoanam.com',
            'password_hash' => bcrypt('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /**
     * `sku.brand_id/category_id/model_id` neo FK vào `product_masters.master_id`,
     * và `master_id` dùng chung id với bảng canonical (migration 2026_08_16_135000).
     * Trên PostgreSQL trigger tự đồng bộ chiều canonical → product_masters; trên
     * sqlite trigger không được cài, nên test phải tự dựng cả hai vế.
     */
    private function seedClassificationMasters(): void
    {
        $now = now();
        foreach ([
            ['brands', 'brand_code', 'brand_name', 'HN-BRAND-01', 'Hãng mẫu', 'BRAND'],
            ['product_categories', 'category_code', 'category_name', 'HN-CAT-01', 'Nhóm mẫu', 'CATEGORY'],
            ['product_models', 'model_code', 'model_name', 'HN-MODEL-01', 'Model mẫu', 'MODEL'],
        ] as [$table, $codeColumn, $nameColumn, $code, $name, $type]) {
            $id = (string) Str::uuid();
            DB::table('product_masters')->insert([
                'master_id' => $id, 'master_type' => $type, 'code' => $code, 'name' => $name,
                'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now, 'version' => 0,
            ]);
            DB::table($table)->insert([
                'id' => $id, $codeColumn => $code, $nameColumn => $name,
                'sort_order' => 0, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now, 'version' => 0,
            ]);
        }
    }

    private function writeSkuFileWithClassification(string $brand, string $category, string $model): void
    {
        $this->writeFile('sku.csv',
            "sku_code,sku_name,sku_type,unit,is_serial_tracked,version_code,status,brand_code,category_code,model_code\n".
            "HN-MK-001-V1,Máy khoan mẫu,PRODUCT,cái,TRUE,V1,ACTIVE,{$brand},{$category},{$model}\n".
            "HN-PK-002-V1,Phụ kiện mẫu,COMPONENT,cái,FALSE,V1,ACTIVE,,,\n");
    }

    private function writeFile(string $name, string $content): void
    {
        file_put_contents($this->packagePath.'/'.$name, $content);
    }

    private function writePackage(): void
    {
        @mkdir($this->packagePath, 0755, true);

        file_put_contents($this->packagePath.'/manifest.json', json_encode([
            'package_version' => '1.2',
            'source_file_name' => 'HoaNam_Import_Package_v1.2.zip',
            'datasets' => [
                'AGENCY' => 'agency.csv',
                'SKU' => 'sku.csv',
                'PHYSICAL_CODE' => 'physical_code.csv',
                'CONTAINER' => 'container.csv',
                'ITEM' => 'item.csv',
                'OPENING_BALANCE' => 'opening_balance.csv',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Header-only: từ khi WWHN-222 thêm 'phone' vào audit.sensitive_keys, mọi
        // snapshot agencies (MasterStateJournal::assertNotRedacted) fail-closed với
        // MIGRATION_SNAPSHOT_REDACTED — kể cả phone rỗng, vì sanitize() redact theo
        // KEY. Đó là xung đột ở tầng app (cutover thật có agency sẽ bị chặn), nằm
        // ngoài phạm vi bộ test này; các bất biến ở đây (duplicate run-code, container
        // mapping, FK phân loại, verify-run) không cần dòng agency nào.
        $this->writeFile('agency.csv',
            "agency_code,agency_name,phone,address,status\n");

        $this->writeFile('sku.csv',
            "sku_code,sku_name,sku_type,unit,is_serial_tracked,version_code,status\n".
            "HN-MK-001-V1,Máy khoan mẫu,PRODUCT,cái,TRUE,V1,ACTIVE\n".
            "HN-PK-002-V1,Phụ kiện mẫu,COMPONENT,cái,FALSE,V1,ACTIVE\n");

        $this->writeFile('physical_code.csv',
            "code_value,code_type,carrier_type,hardware_uid,target_legacy_key,status\n".
            "HN-ITEM-000001,ITEM,BARCODE,,LEG-001,ACTIVE\n".
            "HN-ITEM-000002,ITEM,BARCODE,,LEG-002,ACTIVE\n".
            "HN-CTN-900,CONTAINER,QR,,,ACTIVE\n");

        $this->writeFile('container.csv',
            "container_code,type,carrier,physical_code,primary_sku,parent_tray,status\n".
            "CTN-900,CARTON,QR,HN-CTN-900,HN-MK-001-V1,,IN_STOCK\n");

        $this->writeFile('item.csv',
            "legacy_item_key,sku_code,serial_number,code_value,condition,import_batch\n".
            "LEG-001,HN-MK-001-V1,SN0001,HN-ITEM-000001,NORMAL,BATCH-900\n".
            "LEG-002,HN-MK-001-V1,SN0002,HN-ITEM-000002,NORMAL,BATCH-900\n");

        $this->writeFile('opening_balance.csv',
            "warehouse_code,sku_code,stock_status,quantity,source,owner_confirmation\n".
            "HN-WH-01,HN-MK-001-V1,AVAILABLE,2,CUTOVER_COUNT,QL_KHO\n".
            "HN-WH-01,HN-PK-002-V1,AVAILABLE,1,CUTOVER_COUNT,QL_KHO\n");
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (glob($path.'/*') ?: [] as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : @unlink($file);
        }
        @rmdir($path);
    }
}
