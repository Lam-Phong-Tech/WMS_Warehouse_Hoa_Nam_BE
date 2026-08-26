<?php

namespace Tests\Feature;

use App\Support\Migration\FinalDataLoadService;
use App\Support\Migration\ImportPackage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\Sku;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WWHN-299 — lần nạp dữ liệu cuối (REQ-SRS-021 / AC-SRS-021, SRS §13.12,
 * DEC-MIG-API-001, ERD bảng 58-59).
 *
 * Nhóm test được bổ sung sau đánh giá 60/100 phủ đúng ba defect đã đóng:
 *   - DEV299-P1-01 rollback có khả năng đảo ngược master (khôi phục bản ghi bị
 *     ghi đè, loại bỏ bản ghi mới, chứng minh không còn tồn dư);
 *   - DEV299-P1-02 thẩm quyền duyệt và separation of duty;
 *   - DEV299-P1-03 manifest nằm trong checksum nguồn.
 */
class MigrationFinalDataLoadTest extends TestCase
{
    use RefreshDatabase;

    /** Quyền tối thiểu của cutover operator theo config/final_data_load.php. */
    private const OPERATOR_PERMISSIONS = ['warehouse.view', 'inventory.view'];

    /** Quyền Data Owner được ký duyệt opening balance (SRS §13.12 "QL kho signs result"). */
    private const APPROVER_PERMISSIONS = ['warehouse.manage', 'inventory.view', 'warehouse.view'];

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureActiveWarehouse('HN-WH-01', 'Kho tổng Hoa Nam');

        $this->seedActor('cutover-operator', 'Cutover Operator', self::OPERATOR_PERMISSIONS);
        $this->seedActor('data-owner', 'Data Owner', self::APPROVER_PERMISSIONS);
        // NV kho: có quyền vận hành nhưng KHÔNG phải Data Owner của tồn đầu.
        $this->seedActor('warehouse-staff', 'Warehouse Staff', self::OPERATOR_PERMISSIONS);

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
        $this->commit('CUTOVER-NO-DRYRUN')->assertExitCode(1);

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

    /** DEV299-P1-02: user ACTIVE nhưng không phải Data Owner thì không mở được COMMIT. */
    public function test_commit_is_blocked_when_approver_is_not_a_data_owner(): void
    {
        $this->dryRun('CUTOVER-WEAK-APPROVER');

        $this->artisan('migration:final-load', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-WEAK-APPROVER',
            '--executed-by' => 'cutover-operator',
            '--approved-by' => 'warehouse-staff',
            '--approval-ref' => 'WWHN-298#SIGNOFF-1',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('migration_runs', ['migration_run_code' => 'CUTOVER-WEAK-APPROVER#COMMIT']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** DEV299-P1-02: separation of duty — operator không tự duyệt được. */
    public function test_commit_is_blocked_when_approver_is_the_operator(): void
    {
        $this->dryRun('CUTOVER-SELF-APPROVE');

        $this->artisan('migration:final-load', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-SELF-APPROVE',
            '--executed-by' => 'data-owner',
            '--approved-by' => 'data-owner',
            '--approval-ref' => 'WWHN-298#SIGNOFF-1',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('migration_runs', ['migration_run_code' => 'CUTOVER-SELF-APPROVE#COMMIT']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /** DEV299-P1-02: phải pin được "duyệt theo văn bản nào", không chỉ "ai duyệt". */
    public function test_commit_is_blocked_without_approval_reference(): void
    {
        $this->dryRun('CUTOVER-NO-REF');

        $this->artisan('migration:final-load', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-NO-REF',
            '--executed-by' => 'cutover-operator',
            '--approved-by' => 'data-owner',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('migration_runs', ['migration_run_code' => 'CUTOVER-NO-REF#COMMIT']);
    }

    /** DEV299-P1-02: operator không đủ quyền bị chặn trước khi tạo bất kỳ run nào. */
    public function test_operator_without_required_permission_is_rejected(): void
    {
        $this->seedActor('outsider', 'Outsider', []);

        $this->artisan('migration:final-load', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => 'CUTOVER-OUTSIDER',
            '--executed-by' => 'outsider',
        ])->assertExitCode(1);

        $this->assertSame(0, DB::table('migration_runs')->count());
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

        // Mã quyết định duyệt phải đi vào evidence của run, không chỉ nằm ở console.
        $note = (string) DB::table('migration_runs')
            ->where('migration_run_code', 'CUTOVER-2026#COMMIT')
            ->value('note');
        $this->assertStringContainsString('APPROVAL_REF=WWHN-298#SIGNOFF-1', $note);
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

    /**
     * WWHN-234/235 G-02 (`TC-P1-039`, SRS §13.3 MIGRATION_COMMIT × CONCURRENCY):
     * "Two parallel requests on same ... session scope → one 200; other
     * 409/412/422 ... Tối đa một request commit; request thua trả lỗi rõ ràng;
     * request thua không đổi state/stock/movement."
     *
     * `commit()` (app/Support/Migration/FinalDataLoadService.php) tự bảo vệ bằng
     * `findRun($runCode, 'COMMIT')` (SELECT, không khoá) TRƯỚC KHI `startRun()`
     * (INSERT) — đây là cửa sổ TOCTOU thật: nếu hai request chạm `findRun()` gần
     * như cùng lúc, CẢ HAI đều thấy null và cả hai đều tiến tới `startRun()`.
     * Không thể tái tạo thời điểm "cùng lúc" thật bằng một tiến trình PHP đơn
     * luồng (driver test mặc định là sqlite `:memory:`, mỗi tiến trình một DB
     * riêng nên không dùng được hạ tầng đa tiến trình kiểu
     * Wwhn219RuntimeConcurrencyTest — xem docblock lớp đó). Test này gọi thẳng
     * `startRun()` (private, qua Reflection — cùng kỹ thuật với
     * Wwhn266IfMatchContractTruthTest) hai lần liên tiếp cho CÙNG run_code để
     * dựng đúng cái mà race đó tạo ra ở tầng DB: hai INSERT cạnh tranh trên cùng
     * `migration_run_code`. Đây là code SẢN XUẤT thật (không phải SQL viết tay
     * mô phỏng lại), nên nó chứng minh đúng cơ chế chặn thật sự tồn tại: chỉ
     * mục UNIQUE `migration_runs.migration_run_code` (migration
     * 2026_08_09_000400_align_latest_erd_baseline.php dòng 360).
     */
    public function test_g02_two_commits_racing_on_the_same_run_code_before_either_row_exists_allow_only_one_winner(): void
    {
        $this->dryRun('CUTOVER-RACE-INSERT');
        $package = ImportPackage::load($this->packagePath);
        $service = app(FinalDataLoadService::class);

        $this->assertTrue(
            DB::table('migration_runs')->where('migration_run_code', 'CUTOVER-RACE-INSERT#COMMIT')->doesntExist(),
            'Tiền đề của race: chưa racer nào insert run COMMIT — cả hai findRun() đều phải thấy null.'
        );

        $reflection = new ReflectionMethod(FinalDataLoadService::class, 'startRun');
        $reflection->setAccessible(true);

        // Racer A: findRun() của nó đã thấy null (đúng tiền đề ở trên) và tiến
        // tới startRun() trước.
        $winnerRunId = $reflection->invoke(
            $service, 'CUTOVER-RACE-INSERT', 'COMMIT', $package, 'cutover-operator', null, 'data-owner'
        );
        $this->assertIsString($winnerRunId);

        // Racer B: findRun() của NÓ cũng chạy trong đúng cửa sổ đó (trước khi
        // insert của A kịp hiện diện) nên cũng thấy null và cũng tiến tới
        // startRun() — đúng cái một race thật sẽ tạo ra. Phải bị DB chặn.
        $threw = false;
        try {
            $reflection->invoke(
                $service, 'CUTOVER-RACE-INSERT', 'COMMIT', $package, 'cutover-operator', null, 'data-owner'
            );
        } catch (QueryException $exception) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Racer thua phải bị chặn bằng lỗi UNIQUE constraint của migration_run_code, không được insert thành công lần hai.');

        $this->assertSame(
            1,
            DB::table('migration_runs')->where('migration_run_code', 'CUTOVER-RACE-INSERT#COMMIT')->count(),
            'Tối đa một run COMMIT cho cùng run_code — đúng "one 200; other 409/412/422" của TC-P1-039.'
        );
        // startRun() của racer thua ném exception TRƯỚC writeMasters()/writeOpeningBalance()
        // (những bước đó chỉ chạy sau khi commit() có được $run id) — nên nó
        // không thể đã ghi bất kỳ movement/balance nào.
        $this->assertSame(0, DB::table('stock_movements')->count(), 'Request thua không được ghi movement.');
        $this->assertSame(0, DB::table('inventory_balances')->count(), 'Request thua không được đổi tồn.');
    }

    /**
     * WWHN-234/235 G-02 phần bù — nhánh còn lại của cùng race: racer B tới NGAY
     * SAU khi racer A đã insert xong migration_runs (RUNNING) nhưng transaction
     * ghi tồn của A CHƯA chạy (`writeMasters`/`writeOpeningBalance` nằm sau
     * `startRun()` trong `commit()`). Đây là nhánh mà `findRun()` của B THẤY được
     * row RUNNING của A — code sản xuất đã có sẵn guard sạch cho ca này
     * (`replayGuard()` → `RUN_IN_PROGRESS`), khác nhánh test phía trên (B không
     * thấy gì, phải rơi xuống UNIQUE constraint). Cả hai nhánh cộng lại mới phủ
     * hết "hai request chạm cùng run_code, timing khác nhau" của TC-P1-039.
     */
    public function test_g02_second_commit_arriving_while_the_first_is_still_running_is_blocked_without_writing_stock(): void
    {
        $this->dryRun('CUTOVER-RACE-INFLIGHT');
        $package = ImportPackage::load($this->packagePath);
        $service = app(FinalDataLoadService::class);

        $reflection = new ReflectionMethod(FinalDataLoadService::class, 'startRun');
        $reflection->setAccessible(true);
        // Racer A: đã qua findRun()/startRun() (row RUNNING tồn tại) nhưng chưa
        // chạy tới DB::transaction() ghi movement/balance của chính commit().
        $reflection->invoke(
            $service, 'CUTOVER-RACE-INFLIGHT', 'COMMIT', $package, 'cutover-operator', null, 'data-owner'
        );

        $this->assertDatabaseHas('migration_runs', [
            'migration_run_code' => 'CUTOVER-RACE-INFLIGHT#COMMIT',
            'status' => 'RUNNING',
        ]);

        // Racer B: tới ngay sau, cùng run_code, qua đúng đường console command thật.
        $this->commit('CUTOVER-RACE-INFLIGHT')->assertExitCode(1);

        $this->assertSame(
            1,
            DB::table('migration_runs')->where('migration_run_code', 'CUTOVER-RACE-INFLIGHT#COMMIT')->count(),
            'Racer thua không được tạo thêm run COMMIT nào.'
        );
        $this->assertSame(
            'RUNNING',
            DB::table('migration_runs')->where('migration_run_code', 'CUTOVER-RACE-INFLIGHT#COMMIT')->value('status'),
            'State của racer A không được racer B đổi.'
        );
        $this->assertSame(0, DB::table('stock_movements')->count(), 'Racer thua không được ghi movement.');
        $this->assertSame(0, DB::table('inventory_balances')->count(), 'Racer thua không được đổi tồn.');
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

    /**
     * DEV299-P1-03: đổi metadata manifest (không đụng byte nào của CSV) vẫn phải
     * làm checksum khác và chặn COMMIT.
     */
    public function test_changed_manifest_metadata_after_dry_run_blocks_commit(): void
    {
        $this->dryRun('CUTOVER-MANIFEST');

        $manifest = json_decode((string) file_get_contents($this->packagePath.'/manifest.json'), true);
        $manifest['source_file_name'] = 'HoaNam_Import_Package_v1.2_KHAC.zip';
        file_put_contents(
            $this->packagePath.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->commit('CUTOVER-MANIFEST')->assertExitCode(1);

        $this->assertDatabaseMissing('migration_runs', ['migration_run_code' => 'CUTOVER-MANIFEST#COMMIT']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * DEV299-P1-03: format lại manifest (thụt lề, đổi thứ tự khoá) không đổi nội
     * dung normative nên KHÔNG được coi là package khác.
     */
    public function test_reformatting_manifest_does_not_change_checksum(): void
    {
        $this->dryRun('CUTOVER-REFORMAT');

        $manifest = json_decode((string) file_get_contents($this->packagePath.'/manifest.json'), true);
        $manifest = array_reverse($manifest, true);
        file_put_contents($this->packagePath.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $this->commit('CUTOVER-REFORMAT')->assertExitCode(0);

        $this->assertDatabaseHas('migration_runs', [
            'migration_run_code' => 'CUTOVER-REFORMAT#COMMIT',
            'status' => 'COMMITTED',
        ]);
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

        $this->rollback('CUTOVER-2026')->assertExitCode(0);

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
    }

    /**
     * DEV299-P1-01: SRS §13.12 đòi "no subset master/opening/movement remains".
     * Master do run tạo mới phải biến mất hẳn khi khoá ngoại cho phép.
     */
    public function test_rollback_removes_masters_created_by_the_run(): void
    {
        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);

        $this->assertDatabaseHas('agencies', ['agency_code' => 'AG-900']);
        $this->assertDatabaseHas('sku', ['sku_code' => 'HN-MK-001-V1']);
        $this->assertDatabaseHas('containers', ['container_code' => 'CTN-900']);

        $this->rollback('CUTOVER-2026')->assertExitCode(0);

        $this->assertDatabaseMissing('agencies', ['agency_code' => 'AG-900']);
        $this->assertDatabaseMissing('physical_codes', ['code_value' => 'HN-CTN-900']);
        $this->assertDatabaseMissing('containers', ['container_code' => 'CTN-900']);
        $this->assertDatabaseMissing('item_instances', ['serial_number' => 'SN0001']);
        $this->assertDatabaseMissing('import_batches', ['import_batch_code' => 'BATCH-900']);
        $this->assertSame(0, DB::table('inventory_balances')->count());

        $result = json_decode((string) DB::table('migration_runs')
            ->where('migration_run_code', 'CUTOVER-2026#ROLLBACK')
            ->value('reconciliation_result'), true);

        $this->assertSame(0, $result['residual_master_rows']);
        $this->assertSame([], $result['master_rollback']['retained_rows']);
        $this->assertSame([], $result['master_rollback']['missing_rows']);
    }

    /**
     * DEV299-P1-01: `stock_movements` là append-only (DEC-REV-001) nên OPENING và
     * REVERSAL của run vẫn giữ khoá ngoại tới SKU — SKU mới không xoá cứng được.
     * Rollback phải disposition nó về INACTIVE và nói rõ lý do trong evidence,
     * chứ không được im lặng để lại một SKU ACTIVE do cutover sinh ra.
     */
    public function test_rollback_disposes_ledger_pinned_masters_instead_of_leaving_them_active(): void
    {
        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);
        $this->rollback('CUTOVER-2026')->assertExitCode(0);

        $this->assertDatabaseHas('sku', ['sku_code' => 'HN-MK-001-V1', 'status' => 'INACTIVE']);
        $this->assertDatabaseHas('sku', ['sku_code' => 'HN-PK-002-V1', 'status' => 'INACTIVE']);
        $this->assertSame(0, DB::table('sku')->where('status', 'ACTIVE')->count());

        $result = json_decode((string) DB::table('migration_runs')
            ->where('migration_run_code', 'CUTOVER-2026#ROLLBACK')
            ->value('reconciliation_result'), true);

        $this->assertSame('PASS', $result['status']);
        $this->assertCount(2, $result['master_rollback']['disposed_rows']);
        foreach ($result['master_rollback']['disposed_rows'] as $row) {
            $this->assertSame('sku', $row['table']);
            $this->assertSame('LEDGER_PINNED', $row['reason']);
            $this->assertSame('INACTIVE', $row['status_value']);
        }
    }

    /**
     * DEV299-P1-01: master đã tồn tại trước cutover bị package ghi đè phải trở
     * lại đúng giá trị cũ, không phải giữ giá trị của package.
     */
    public function test_rollback_restores_masters_overwritten_by_the_package(): void
    {
        Sku::query()->create([
            'sku_code' => 'HN-MK-001-V1',
            'sku_name' => 'Tên cũ',
            'sku_type' => 'PRODUCT',
            'unit' => 'bộ',
            'is_serial_tracked' => true,
            'version_code' => 'V0',
            'status' => 'INACTIVE',
        ]);
        Agency::query()->create([
            'agency_code' => 'AG-900',
            'agency_name' => 'Đại lý cũ',
            'phone' => '0900000000',
            'address' => 'Địa chỉ cũ',
            'status' => 'INACTIVE',
        ]);

        $this->dryRun('CUTOVER-2026');
        $this->commit('CUTOVER-2026')->assertExitCode(0);

        $this->assertSame('Máy khoan mẫu', Sku::query()->where('sku_code', 'HN-MK-001-V1')->value('sku_name'));

        $this->rollback('CUTOVER-2026')->assertExitCode(0);

        $sku = Sku::query()->where('sku_code', 'HN-MK-001-V1')->first();
        $this->assertNotNull($sku, 'SKU có trước cutover không được biến mất khi rollback.');
        $this->assertSame('Tên cũ', $sku->sku_name);
        $this->assertSame('bộ', $sku->unit);
        $this->assertSame('V0', $sku->version_code);
        $this->assertSame('INACTIVE', $sku->status);

        $agency = Agency::query()->where('agency_code', 'AG-900')->first();
        $this->assertNotNull($agency);
        $this->assertSame('Đại lý cũ', $agency->agency_name);
        $this->assertSame('INACTIVE', $agency->status);

        $result = json_decode((string) DB::table('migration_runs')
            ->where('migration_run_code', 'CUTOVER-2026#ROLLBACK')
            ->value('reconciliation_result'), true);

        $this->assertSame('PASS', $result['status']);
        $this->assertSame(0, $result['residual_master_rows']);
        $this->assertGreaterThanOrEqual(2, $result['master_rollback']['restored_rows']);
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
            '--approval-ref' => 'WWHN-298#SIGNOFF-1',
        ]);
    }

    private function rollback(string $runCode)
    {
        return $this->artisan('migration:final-load', [
            '--mode' => 'ROLLBACK',
            '--run-code' => $runCode,
            '--executed-by' => 'cutover-operator',
        ]);
    }

    /**
     * Tạo user + role + permission thật thay vì user trần: cổng thẩm quyền của
     * job đọc `permissionCodes()`, nên fixture phải đi qua đúng đường RBAC.
     *
     * @param  array<int, string>  $permissions
     */
    private function seedActor(string $username, string $displayName, array $permissions): User
    {
        $user = User::query()->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $username.'@hoanam.com',
            'password_hash' => bcrypt('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $role = Role::query()->create([
            'role_code' => strtoupper(str_replace('-', '_', $username)),
            'role_name' => $displayName,
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE'],
            );
            $role->permissions()->attach($permission->permission_id, [
                'role_permission_id' => (string) Str::uuid(),
            ]);
        }

        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $user;
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
