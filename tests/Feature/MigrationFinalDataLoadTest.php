<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

/**
 * WWHN-299 — lần nạp dữ liệu cuối (REQ-SRS-021 / AC-SRS-021, SRS §13.12,
 * DEC-MIG-API-001, ERD bảng 58-59).
 */
class MigrationFinalDataLoadTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureActiveWarehouse('HN-WH-01', 'Kho tổng Hòa Nam');
        User::query()->create([
            'username' => 'cutover-operator',
            'display_name' => 'Cutover Operator',
            'email' => 'cutover-operator@hoanam.com',
            'password_hash' => bcrypt('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        User::query()->create([
            'username' => 'data-owner',
            'display_name' => 'Data Owner',
            'email' => 'data-owner@hoanam.com',
            'password_hash' => bcrypt('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $this->packagePath = storage_path('framework/testing/import-package-'.uniqid());
        $this->writePackage();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->packagePath);
        parent::tearDown();
    }

    public function test_dry_run_passes_and_writes_no_business_data(): void
    {
        $this->artisan('migration:final-load', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-2026',
            '--executed-by' => 'cutover-operator',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('migration_runs', [
            'migration_run_code' => 'CUTOVER-2026#DRY_RUN',
            'mode' => 'DRY_RUN',
            'status' => 'PASSED',
            'reconciliation_status' => 'PASS',
        ]);
        // AC-SRS-021: dry-run không đổi dữ liệu nghiệp vụ.
        $this->assertDatabaseMissing('sku', ['sku_code' => 'HN-MK-001-V1']);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('inventory_balances')->count());
    }

    public function test_commit_is_blocked_without_passed_dry_run(): void
    {
        $this->artisan('migration:final-load', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-NO-DRYRUN',
            '--executed-by' => 'cutover-operator',
            '--approved-by' => 'data-owner',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('migration_runs', ['migration_run_code' => 'CUTOVER-NO-DRYRUN#COMMIT']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_commit_is_blocked_without_approver(): void
    {
        $this->dryRun('CUTOVER-NO-APPROVER');

        $this->artisan('migration:final-load', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-NO-APPROVER',
            '--executed-by' => 'cutover-operator',
        ])->assertExitCode(1);

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_commit_writes_opening_balance_and_reconciles(): void
    {
        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);

        $this->assertDatabaseHas('migration_runs', [
            'migration_run_code' => 'CUTOVER-2026#COMMIT',
            'status' => 'COMMITTED',
            'reconciliation_status' => 'PASS',
        ]);
        $this->assertDatabaseHas('sku', ['sku_code' => 'HN-MK-001-V1', 'is_serial_tracked' => true]);
        $this->assertDatabaseHas('agencies', ['agency_code' => 'AG-900']);
        $this->assertDatabaseHas('containers', ['container_code' => 'CTN-900']);

        $movements = DB::table('stock_movements')->where('movement_type', 'OPENING')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(3, (int) DB::table('stock_movements')->where('stock_status', 'AVAILABLE')->sum('qty_delta'));

        $balances = DB::table('inventory_balances')->where('stock_status', 'AVAILABLE');
        $this->assertSame(2, (clone $balances)->count());
        $this->assertSame(3, (int) (clone $balances)->sum('on_hand_qty'));
        $this->assertSame(3, (int) (clone $balances)->sum('available_qty'));
    }

    public function test_commit_rerun_with_same_run_code_is_idempotent(): void
    {
        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);
        $this->commit('CUTOVER-2026')->assertExitCode(0);

        $this->assertSame(1, DB::table('migration_runs')->where('mode', 'COMMIT')->count());
        $this->assertSame(2, DB::table('stock_movements')->where('movement_type', 'OPENING')->count());
        $this->assertSame(3, (int) DB::table('inventory_balances')->where('stock_status', 'AVAILABLE')->sum('on_hand_qty'));
    }

    public function test_changed_package_after_dry_run_blocks_commit(): void
    {
        $this->dryRun('CUTOVER-2026');
        file_put_contents(
            $this->packagePath.'/opening_balance.csv',
            "warehouse_code,sku_code,stock_status,quantity,source,owner_confirmation\n".
            "HN-WH-01,HN-MK-001-V1,AVAILABLE,99,CUTOVER_COUNT,QL_KHO\n"
        );

        $this->commit('CUTOVER-2026')->assertExitCode(1);

        $this->assertDatabaseMissing('migration_runs', ['migration_run_code' => 'CUTOVER-2026#COMMIT']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_dry_run_reports_row_level_errors_with_masked_pii(): void
    {
        file_put_contents(
            $this->packagePath.'/agency.csv',
            "agency_code,agency_name,phone,address,status\n".
            "AG-900,Đại lý 900,0909009900,Hà Nội,ACTIVE\n".
            "AG-900,Đại lý trùng,0912345678,Hải Phòng,UNKNOWN\n"
        );

        $this->artisan('migration:final-load', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-ERR',
            '--executed-by' => 'cutover-operator',
        ])->assertExitCode(1);

        $run = DB::table('migration_runs')->where('migration_run_code', 'CUTOVER-ERR#DRY_RUN')->first();
        $this->assertSame('FAILED', $run->status);

        $errors = DB::table('migration_errors')->where('migration_run_id', $run->id)->get();
        $this->assertContains('DUPLICATE_CODE', $errors->pluck('error_code')->all());
        $this->assertContains('INVALID_ENUM', $errors->pluck('error_code')->all());

        $masked = $errors->firstWhere('field_name', 'phone');
        if ($masked !== null) {
            $this->assertStringNotContainsString('0912345678', (string) $masked->raw_value_masked);
        }
    }

    public function test_serial_tracked_quantity_must_match_item_count(): void
    {
        file_put_contents(
            $this->packagePath.'/opening_balance.csv',
            "warehouse_code,sku_code,stock_status,quantity,source,owner_confirmation\n".
            "HN-WH-01,HN-MK-001-V1,AVAILABLE,5,CUTOVER_COUNT,QL_KHO\n"
        );

        $this->artisan('migration:final-load', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-RECON',
            '--executed-by' => 'cutover-operator',
        ])->assertExitCode(1);

        $run = DB::table('migration_runs')->where('migration_run_code', 'CUTOVER-RECON#DRY_RUN')->first();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame('FAIL', $run->reconciliation_status);
        $this->assertContains('RECON_MISMATCH',
            DB::table('migration_errors')->where('migration_run_id', $run->id)->pluck('error_code')->all());
    }

    public function test_rollback_leaves_no_residual_opening_stock(): void
    {
        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);

        $this->artisan('migration:final-load', [
            '--mode' => 'ROLLBACK',
            '--run-code' => 'CUTOVER-2026',
            '--executed-by' => 'cutover-operator',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('migration_runs', [
            'migration_run_code' => 'CUTOVER-2026#ROLLBACK',
            'status' => 'ROLLED_BACK',
            'reconciliation_status' => 'PASS',
        ]);
        $this->assertDatabaseHas('migration_runs', [
            'migration_run_code' => 'CUTOVER-2026#COMMIT',
            'status' => 'ROLLED_BACK',
        ]);
        $this->assertSame(0, (int) DB::table('inventory_balances')->sum('on_hand_qty'));
        // Ledger append-only: movement gốc vẫn còn, hiệu ứng ròng bằng 0.
        $this->assertSame(0, (int) DB::table('stock_movements')->sum('qty_delta'));
        $this->assertSame(2, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->count());
        $this->assertDatabaseHas('containers', ['container_code' => 'CTN-900', 'status' => 'CLOSED']);
    }

    public function test_unknown_operator_is_rejected_before_any_run_is_created(): void
    {
        $this->artisan('migration:final-load', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-NOBODY',
            '--executed-by' => 'khong-ton-tai',
        ])->assertExitCode(1);

        $this->assertSame(0, DB::table('migration_runs')->count());
    }

    public function test_sku_master_is_upserted_without_duplicating_existing_baseline(): void
    {
        Sku::query()->create([
            'sku_code' => 'HN-MK-001-V1',
            'sku_name' => 'Tên cũ',
            'sku_type' => 'PRODUCT',
            'unit' => 'cái',
            'is_serial_tracked' => true,
            'version_code' => 'V1',
            'status' => 'ACTIVE',
        ]);

        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);

        $this->assertSame(1, Sku::query()->where('sku_code', 'HN-MK-001-V1')->count());
        $this->assertSame('Máy khoan mẫu', Sku::query()->where('sku_code', 'HN-MK-001-V1')->value('sku_name'));
    }

    private function dryRun(string $runCode): void
    {
        $this->artisan('migration:final-load', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => $runCode,
            '--executed-by' => 'cutover-operator',
        ])->assertExitCode(0);
    }

    private function commit(string $runCode)
    {
        return $this->artisan('migration:final-load', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => $runCode,
            '--executed-by' => 'cutover-operator',
            '--approved-by' => 'data-owner',
        ]);
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

        file_put_contents($this->packagePath.'/agency.csv',
            "agency_code,agency_name,phone,address,status\n".
            "AG-900,Đại lý 900,0909009900,Hà Nội,ACTIVE\n");

        file_put_contents($this->packagePath.'/sku.csv',
            "sku_code,sku_name,sku_type,unit,is_serial_tracked,version_code,status\n".
            "HN-MK-001-V1,Máy khoan mẫu,PRODUCT,cái,TRUE,V1,ACTIVE\n".
            "HN-PK-002-V1,Phụ kiện mẫu,COMPONENT,cái,FALSE,V1,ACTIVE\n");

        file_put_contents($this->packagePath.'/physical_code.csv',
            "code_value,code_type,carrier_type,hardware_uid,target_legacy_key,status\n".
            "HN-ITEM-000001,ITEM,BARCODE,,LEG-001,ACTIVE\n".
            "HN-ITEM-000002,ITEM,BARCODE,,LEG-002,ACTIVE\n".
            "HN-CTN-900,CONTAINER,QR,,,ACTIVE\n");

        file_put_contents($this->packagePath.'/container.csv',
            "container_code,type,carrier,physical_code,primary_sku,parent_tray,status\n".
            "CTN-900,CARTON,QR,HN-CTN-900,HN-MK-001-V1,,IN_STOCK\n");

        file_put_contents($this->packagePath.'/item.csv',
            "legacy_item_key,sku_code,serial_number,code_value,condition,import_batch\n".
            "LEG-001,HN-MK-001-V1,SN0001,HN-ITEM-000001,NORMAL,BATCH-900\n".
            "LEG-002,HN-MK-001-V1,SN0002,HN-ITEM-000002,NORMAL,BATCH-900\n");

        file_put_contents($this->packagePath.'/opening_balance.csv',
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
