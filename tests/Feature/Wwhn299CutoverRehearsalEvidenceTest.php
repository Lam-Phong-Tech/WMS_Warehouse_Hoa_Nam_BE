<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * WWHN-299 — diễn tập cutover sinh evidence chạy được (DEV299-P1-05, P1-06).
 *
 * Đánh giá 60/100 ghi nhận: các file evidence được *tuyên bố* nhưng không truy
 * xuất được, và không có raw log của lần chạy thật. Test này biến evidence thành
 * sản phẩm của một lần chạy có thật thay vì một tài liệu viết tay:
 *
 *   - chạy đúng ba pha DRY_RUN → COMMIT → ROLLBACK trên Import Package template,
 *   - ghi nguyên văn báo cáo JSON của job vào `build/evidence/wwhn-299/`,
 *   - kèm `MANIFEST.json` có driver DB, run code, checksum nguồn và sha256 của
 *     từng file để reviewer đối chiếu được tại exact SHA.
 *
 * Nó **không** thay cho lần nạp dữ liệu production (WWHN-298/301/302): đó vẫn là
 * gate của Data Owner/DevOps/QA1 và phải chạy sau freeze + backup.
 */
class Wwhn299CutoverRehearsalEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const RUN_CODE = 'REHEARSAL-WWHN-299';

    private string $packagePath;

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');
        $this->seedActor('rehearsal-operator', 'Rehearsal Operator', ['warehouse.view', 'inventory.view']);
        $this->seedActor('rehearsal-owner', 'Rehearsal Data Owner', ['warehouse.view', 'warehouse.manage', 'inventory.view']);

        $this->packagePath = storage_path('framework/testing/wwhn299-rehearsal-'.uniqid());
        $this->copyTemplatePackage();

        $this->evidencePath = base_path('build/evidence/wwhn-299');
        if (! is_dir($this->evidencePath)) {
            mkdir($this->evidencePath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->packagePath);
        parent::tearDown();
    }

    public function test_cutover_rehearsal_produces_retrievable_evidence(): void
    {
        $dryRun = $this->runPhase('01-dry-run.json', [
            '--mode' => 'DRY_RUN',
            '--package' => $this->packagePath,
            '--run-code' => self::RUN_CODE,
            '--executed-by' => 'rehearsal-operator',
        ], 0);
        $this->assertSame('PASSED', $dryRun['status']);
        $this->assertSame('PASS', $dryRun['reconciliation']['status']);

        $commit = $this->runPhase('02-commit.json', [
            '--mode' => 'COMMIT',
            '--package' => $this->packagePath,
            '--run-code' => self::RUN_CODE,
            '--executed-by' => 'rehearsal-operator',
            '--approved-by' => 'rehearsal-owner',
            '--approval-ref' => 'WWHN-298#OPENING-BALANCE-SIGNOFF',
        ], 0);
        $this->assertSame('COMMITTED', $commit['status']);
        $this->assertSame('PASS', $commit['reconciliation']['status']);
        $this->assertSame($dryRun['source_checksum'], $commit['source_checksum']);
        $this->assertSame($dryRun['manifest_checksum'], $commit['manifest_checksum']);

        $rollback = $this->runPhase('03-rollback.json', [
            '--mode' => 'ROLLBACK',
            '--run-code' => self::RUN_CODE,
            '--executed-by' => 'rehearsal-operator',
        ], 0);
        $this->assertSame('ROLLED_BACK', $rollback['status']);
        $this->assertSame('PASS', $rollback['reconciliation']['status']);
        $this->assertSame(0, $rollback['reconciliation']['residual_opening_qty']);
        $this->assertSame(0, $rollback['reconciliation']['residual_master_rows']);
        $this->assertSame([], $rollback['reconciliation']['master_rollback']['retained_rows']);
        $this->assertSame([], $rollback['reconciliation']['master_rollback']['missing_rows']);

        // Hiệu ứng tồn kho ròng bằng 0 và ledger vẫn append-only.
        $this->assertSame(0, (int) DB::table('stock_movements')->sum('qty_delta'));
        $this->assertSame(0, (int) DB::table('inventory_balances')->sum('on_hand_qty'));

        $this->writeManifest($dryRun, $commit, $rollback);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runPhase(string $file, array $options, int $expectedExitCode): array
    {
        $output = $this->evidencePath.'/'.$file;
        $exitCode = Artisan::call('migration:final-load', $options + ['--output' => $output]);

        $this->assertSame($expectedExitCode, $exitCode, "Pha {$file} trả exit code không mong đợi.");
        $this->assertFileExists($output);

        $report = json_decode((string) file_get_contents($output), true);
        $this->assertIsArray($report, "Evidence {$file} không phải JSON hợp lệ.");

        return $report;
    }

    /**
     * @param  array<string, mixed>  $dryRun
     * @param  array<string, mixed>  $commit
     * @param  array<string, mixed>  $rollback
     */
    private function writeManifest(array $dryRun, array $commit, array $rollback): void
    {
        $files = ['01-dry-run.json', '02-commit.json', '03-rollback.json'];
        $digests = [];
        foreach ($files as $file) {
            $digests[$file] = hash_file('sha256', $this->evidencePath.'/'.$file);
        }

        $manifest = [
            'jira' => 'WWHN-299',
            'parent' => 'WWHN-80',
            'scope' => 'CUTOVER_REHEARSAL_ONLY',
            'not_claimed' => 'Không phải lần nạp dữ liệu production; WWHN-298/301/302 vẫn là gate của DO/QA1/BA.',
            'db_driver' => DB::getDriverName(),
            'php_version' => PHP_VERSION,
            'git_sha' => (string) (getenv('GITHUB_SHA') ?: 'LOCAL_RUN'),
            'branch' => (string) (getenv('GITHUB_REF_NAME') ?: 'm1'),
            'package_version' => $dryRun['package_version'] ?? null,
            'source_checksum' => $dryRun['source_checksum'] ?? null,
            'manifest_checksum' => $dryRun['manifest_checksum'] ?? null,
            'runs' => [
                'dry_run' => [
                    'migration_run_id' => $dryRun['migration_run_id'] ?? null,
                    'migration_run_code' => $dryRun['migration_run_code'] ?? null,
                    'status' => $dryRun['status'] ?? null,
                ],
                'commit' => [
                    'migration_run_id' => $commit['migration_run_id'] ?? null,
                    'migration_run_code' => $commit['migration_run_code'] ?? null,
                    'status' => $commit['status'] ?? null,
                ],
                'rollback' => [
                    'migration_run_id' => $rollback['migration_run_id'] ?? null,
                    'migration_run_code' => $rollback['migration_run_code'] ?? null,
                    'status' => $rollback['status'] ?? null,
                ],
            ],
            'residual' => [
                'opening_qty' => $rollback['reconciliation']['residual_opening_qty'] ?? null,
                'master_rows' => $rollback['reconciliation']['residual_master_rows'] ?? null,
            ],
            'sha256' => $digests,
        ];

        file_put_contents(
            $this->evidencePath.'/MANIFEST.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    private function copyTemplatePackage(): void
    {
        @mkdir($this->packagePath, 0755, true);
        $template = base_path('docs/migration/import-package-v1.2-template');
        $this->assertDirectoryExists($template, 'Thiếu Import Package template — evidence không tái lập được.');

        foreach (glob($template.'/*') ?: [] as $file) {
            copy($file, $this->packagePath.'/'.basename($file));
        }
    }

    /** @param  array<int, string>  $permissions */
    private function seedActor(string $username, string $displayName, array $permissions): void
    {
        $user = User::query()->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $username.'@gmail.com',
            'password_hash' => bcrypt(TestCredentials::PASSWORD),
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
