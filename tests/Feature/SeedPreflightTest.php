<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use RuntimeException;
use Tests\TestCase;

/** Isolated negative-path/preflight evidence, never a full seed-chain safety claim. */
final class SeedPreflightTest extends TestCase
{
    use RefreshDatabase;

    private array $savedEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $key = 'HN_DAILY_COVERAGE_DAYS';
        $this->savedEnvironment = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        config([
            'golive.development_environments' => ['testing'],
            'golive.demo_environments' => ['testing'],
            'golive.seed.demo_accounts' => false,
            'golive.seed.fixture_password' => bin2hex(random_bytes(24)),
        ]);
    }

    protected function tearDown(): void
    {
        $key = 'HN_DAILY_COVERAGE_DAYS';
        [$env, $server, $process] = $this->savedEnvironment;
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        putenv($process === false ? $key : $key.'='.$process);
        parent::tearDown();
    }

    public function test_exact_full_seed_command_missing_password_fails_before_any_child_or_write(): void
    {
        config(['golive.seed.fixture_password' => null]);
        $this->assertEarlyRejection('WMS_FIXTURE_PASSWORD');
    }

    public function test_full_seed_invalid_demo_password_fails_before_permission_updates(): void
    {
        config([
            'golive.development_environments' => [],
            'golive.seed.demo_accounts' => true,
            'golive.seed.min_demo_password_length' => 12,
            'golive.seed.fixture_password' => 'BadDemoKey',
        ]);
        $this->assertEarlyRejection('WMS_FIXTURE_PASSWORD');
    }

    public function test_valid_credentials_preserve_default_child_sequence_without_executing_children(): void
    {
        $this->coverage('0');
        $batches = [];
        $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
        $seeder->shouldReceive('call')->andReturnUsing(function (array $classes) use (&$batches, $seeder) {
            $batches[] = array_map('class_basename', $classes);

            return $seeder;
        });
        $seeder->run();
        $this->assertSame([
            'AuthDatabaseSeeder', 'WarehouseDatabaseSeeder', 'DongChengCatalogSeeder',
            'WarehouseBusinessDataSeeder', 'WarehouseSupplementalDataSeeder',
            'AuditLogDatabaseSeeder', 'NotificationDatabaseSeeder', 'OperationalReportRangeSeeder',
        ], $batches[0]);
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame([
                'MasterCatalogCoverageSeeder', 'HoaNamBulkDemoSeeder', 'WarehouseOperationsCoverageSeeder',
                'WarrantyReportRangeSeeder', 'InventoryStockStatusMixSeeder', 'WarrantyCheckResultBackfillSeeder',
                'SkuAttributeBackfillSeeder', 'DailySkuCoverageSeeder', 'SkuStockThresholdSeeder',
                'DisplayFieldCoverageSeeder',
            ], $batches[1]);
            $this->assertSame(['FinalFixtureLocationSeeder'], $batches[2]);
            $this->assertCount(3, $batches);
        } else {
            $this->assertCount(1, $batches);
        }
    }

    public function test_readonly_preflight_does_not_query_seed_or_change_protected_database_flags(): void
    {
        $this->coverage('0');
        config([
            'deployment_safety.protect_database' => true,
            'deployment_safety.allow_protected_database_seed' => false,
        ]);
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        [$exit, $report, $output] = $this->preflight('development', '0');
        $this->assertSame(0, $exit);
        $this->assertSame([], $queries);
        $this->assertSame('CONFIG_VALID_NOT_SEED_AUTHORIZATION', $report['status']);
        $this->assertSame(0, $report['configured_daily_coverage_days']);
        $this->assertTrue($report['protected_database']);
        $this->assertFalse($report['protected_seed_permission_enabled']);
        $this->assertTrue(config('deployment_safety.protect_database'));
        $this->assertFalse(config('deployment_safety.allow_protected_database_seed'));
        foreach (['database_queries_executed', 'seed_executed', 'full_seed_verified', 'live_verified', 'deployment_authorized', 'production_authorized'] as $field) {
            $this->assertFalse($report[$field]);
        }
        $this->assertStringNotContainsString(config('golive.seed.fixture_password'), $output);
    }

    public function test_preflight_requires_reviewed_mode_and_does_not_change_actual_fixture_policy(): void
    {
        $this->coverage('0');
        foreach (['', 'unknown', 'demo', 'production'] as $expected) {
            [$exit, $report] = $this->preflight($expected, '0');
            $this->assertSame(1, $exit);
            $this->assertSame('development', $report['fixture_mode']);
            $this->assertContains(in_array($expected, ['', 'unknown'], true)
                ? 'EXPECTED_FIXTURE_MODE_REQUIRED' : 'FIXTURE_MODE_MISMATCH', $report['blockers']);
        }
    }

    public function test_missing_runtime_coverage_never_falls_back_to_zero_or_fourteen(): void
    {
        $this->coverage(null);
        foreach (['0', '14'] as $expected) {
            [$exit, $report] = $this->preflight('development', $expected);
            $this->assertSame(1, $exit);
            $this->assertNull($report['configured_daily_coverage_days']);
            $this->assertContains('EXPLICIT_RUNTIME_COVERAGE_REQUIRED', $report['blockers']);
        }
    }

    public function test_invalid_coverage_is_not_silently_coerced_into_zero(): void
    {
        foreach (['', 'abc', '-1', '1.5', '1e2', ' 0', '01', str_repeat('9', 30)] as $invalid) {
            $this->coverage($invalid);
            [$exit, $report] = $this->preflight('development', '0');
            $this->assertSame(1, $exit);
            $this->assertNull($report['configured_daily_coverage_days']);
            $this->assertContains('EXPLICIT_RUNTIME_COVERAGE_REQUIRED', $report['blockers']);
        }
    }

    public function test_expected_coverage_is_required_and_must_match_without_disabling_daily_phase(): void
    {
        $this->coverage('14');
        [$exit, $report] = $this->preflight('development', '');
        $this->assertSame(1, $exit);
        $this->assertContains('EXPECTED_COVERAGE_DAYS_REQUIRED', $report['blockers']);
        [$exit, $report] = $this->preflight('development', '0');
        $this->assertSame(1, $exit);
        $this->assertContains('COVERAGE_DAYS_MISMATCH', $report['blockers']);
        $this->assertSame(14, $report['configured_daily_coverage_days']);
        [$exit, $report] = $this->preflight('development', '14');
        $this->assertSame(0, $exit);
        $this->assertSame(14, $report['configured_daily_coverage_days']);
        $this->assertSame('14', env('HN_DAILY_COVERAGE_DAYS'));
    }

    public function test_production_account_policy_does_not_require_or_create_fixture_accounts(): void
    {
        $this->coverage('14');
        config(['golive.development_environments' => [], 'golive.seed.fixture_password' => null]);
        [$exit, $report] = $this->preflight('production', '14');
        $this->assertSame(0, $exit);
        $this->assertSame('production', $report['fixture_mode']);
        $this->assertFalse($report['fixture_password_required']);
        $this->assertFalse($report['super_admin_fixtures_allowed_by_policy']);
        $this->assertFalse($report['production_authorized']);
        $this->assertFalse($report['seed_executed']);
    }

    public function test_diagnostic_rejects_missing_or_invalid_secret_without_disclosing_it(): void
    {
        $this->coverage('0');
        config(['golive.seed.fixture_password' => null]);
        [$exit, $report] = $this->preflight('development', '0');
        $this->assertSame(1, $exit);
        $this->assertContains('FIXTURE_PASSWORD_MISSING_OR_INVALID', $report['blockers']);
        config([
            'golive.development_environments' => [],
            'golive.seed.demo_accounts' => true,
            'golive.seed.min_demo_password_length' => 12,
            'golive.seed.fixture_password' => 'BadDemoKey',
        ]);
        [$exit, $report, $output] = $this->preflight('demo', '0');
        $this->assertSame(1, $exit);
        $this->assertContains('FIXTURE_PASSWORD_MISSING_OR_INVALID', $report['blockers']);
        $this->assertStringNotContainsString('BadDemoKey', $output);
    }

    private function assertEarlyRejection(string $message): void
    {
        Permission::updateOrCreate(['permission_key' => 'warehouse.view'], [
            'permission_name' => 'Existing approved label must remain intact',
            'scope_type' => 'FUNCTION', 'status' => 'INACTIVE',
        ]);
        $before = $this->fingerprints();
        $queries = [];
        $capture = true;
        DB::listen(static function (QueryExecuted $event) use (&$queries, &$capture): void {
            if ($capture) {
                $queries[] = $event->sql;
            }
        });
        try {
            Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]);
            $this->fail('Missing or invalid fixture credential must stop the default full seed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        } finally {
            $capture = false;
        }
        $this->assertSame([], $queries, 'No child seeder may query or write before credential validation.');
        $this->assertSame($before, $this->fingerprints());
    }

    private function fingerprints(): array
    {
        $hashes = [];
        foreach ([Permission::class, Role::class, User::class] as $class) {
            $model = new $class;
            $rows = $model->newQuery()->orderBy($model->getKeyName())->get()
                ->map(static fn ($row): array => $row->getRawOriginal())->all();
            $hashes[$model->getTable()] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }

    private function preflight(string $mode, string $days): array
    {
        $exit = Artisan::call('wms:seed-preflight', [
            '--expected-mode' => $mode, '--expected-coverage-days' => $days,
        ]);
        $output = Artisan::output();

        return [$exit, json_decode($output, true, 512, JSON_THROW_ON_ERROR), $output];
    }

    private function coverage(?string $value): void
    {
        $key = 'HN_DAILY_COVERAGE_DAYS';
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}
