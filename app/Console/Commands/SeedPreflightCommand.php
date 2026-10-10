<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Support\SeedEnvironment;
use RuntimeException;

/** Configuration-only review. Never invokes a seeder or grants seed permission. */
final class SeedPreflightCommand extends Command
{
    protected $signature = 'wms:seed-preflight {--expected-mode=} {--expected-coverage-days=}';

    protected $description = 'Check the approved fixture mode and explicit runtime coverage without seeding';

    public function handle(): int
    {
        $mode = SeedEnvironment::mode();
        $expectedMode = $this->option('expected-mode');
        $modes = [SeedEnvironment::DEVELOPMENT, SeedEnvironment::DEMO, SeedEnvironment::PRODUCTION];
        $blockers = [];
        if (! in_array($expectedMode, $modes, true)) {
            $blockers[] = 'EXPECTED_FIXTURE_MODE_REQUIRED';
        } elseif ($expectedMode !== $mode) {
            $blockers[] = 'FIXTURE_MODE_MISMATCH';
        }

        $passwordReady = ! SeedEnvironment::allowsFixtureAccounts();
        if (! $passwordReady) {
            try {
                // Reuse the owner's policy; never print, hash or store the secret.
                SeedEnvironment::fixturePassword();
                $passwordReady = true;
            } catch (RuntimeException) {
                $blockers[] = 'FIXTURE_PASSWORD_MISSING_OR_INVALID';
            }
        }

        // The existing daily owner reads env() at execution time. Do NOT infer
        // its runtime input from a cached config preflight or silently use 0/14.
        $rawDays = env('HN_DAILY_COVERAGE_DAYS');
        $days = $this->days($rawDays);
        $expectedDays = $this->days($this->option('expected-coverage-days'));
        if ($days === null) {
            $blockers[] = 'EXPLICIT_RUNTIME_COVERAGE_REQUIRED';
        }
        if ($expectedDays === null) {
            $blockers[] = 'EXPECTED_COVERAGE_DAYS_REQUIRED';
        } elseif ($days !== null && $expectedDays !== $days) {
            $blockers[] = 'COVERAGE_DAYS_MISMATCH';
        }

        // Connection metadata is resolved without opening PDO or querying tables.
        $driver = DB::connection()->getDriverName();
        $report = [
            'status' => $blockers === [] ? 'CONFIG_VALID_NOT_SEED_AUTHORIZATION' : 'BLOCKED',
            'fixture_mode' => $mode,
            'expected_fixture_mode' => in_array($expectedMode, $modes, true) ? $expectedMode : null,
            'fixture_password_required' => SeedEnvironment::allowsFixtureAccounts(),
            'fixture_policy_valid' => $passwordReady,
            'super_admin_fixtures_allowed_by_policy' => SeedEnvironment::allowsSuperAdminFixtures(),
            'configuration_cached' => app()->configurationIsCached(),
            'database_driver' => $driver,
            'daily_coverage_input_source' => 'EXPLICIT_RUNTIME_ENV_NO_DEFAULT',
            'configured_daily_coverage_days' => $days,
            'expected_daily_coverage_days' => $expectedDays,
            'daily_phase_in_default_chain' => $driver === 'pgsql',
            'protected_database' => (bool) config('deployment_safety.protect_database'),
            'protected_seed_permission_enabled' => (bool) config('deployment_safety.allow_protected_database_seed'),
            'blockers' => $blockers,
            'database_queries_executed' => false,
            'seed_executed' => false,
            'full_seed_verified' => false,
            'live_verified' => false,
            'deployment_authorized' => false,
            'production_authorized' => false,
        ];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $blockers === [] ? self::SUCCESS : self::FAILURE;
    }

    private function days(mixed $value): ?int
    {
        if ((! is_string($value) && ! is_int($value))
            || preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $integer === false ? null : $integer;
    }
}
