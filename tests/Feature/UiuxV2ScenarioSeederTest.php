<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\UiuxV2ScenarioSeeder;
use Modules\Warehouse\Support\UiuxV2OperationalScenario;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

final class UiuxV2ScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $name = $app['config']->get('database.default');
        $db = $app['config']->get('database.connections.'.$name);
        $safe = $app->environment('testing') && empty($db['url'])
            && (($db['driver'] === 'sqlite' && $db['database'] === ':memory:')
                || ($db['driver'] === 'pgsql' && $db['database'] === 'p12_contract_test' && $db['host'] === '127.0.0.1'));
        $app->flush();
        if (! $safe) {
            throw new RuntimeException('UIUX tests require an isolated database.');
        }
        parent::setUp();
        config(['uiux_v2_seed.enabled' => true, 'uiux_v2_seed.date' => '2026-10-29',
            'deployment_safety.protect_database' => false]);
        $this->app->bind(UiuxV2OperationalScenario::class, fn () => new class
        {
            public function run(Carbon $date, string $actorId): array
            {
                return ['fixture' => $date->toDateString()];
            }
        });
    }

    private function actor(): User
    {
        $actor = User::create(['username' => 'uiux-scenario-owner', 'display_name' => 'UIUX scenario owner',
            'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        config(['uiux_v2_seed.actor_id' => $actor->getKey()]);

        return $actor;
    }

    public function test_disabled_or_protected_seed_stops_before_database_queries(): void
    {
        foreach ([[false, false], [true, true]] as [$enabled, $protected]) {
            config(['uiux_v2_seed.enabled' => $enabled, 'deployment_safety.protect_database' => $protected,
                'deployment_safety.allow_protected_database_seed' => false]);
            $queries = [];
            DB::listen(static function (QueryExecuted $event) use (&$queries): void {
                $queries[] = $event->sql;
            });
            try {
                (new UiuxV2ScenarioSeeder)->run();
                $this->fail('Explicit authorization must be required.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('UIUX_V2_SEED_', $error->getMessage());
                $this->assertSame([], $queries);
            }
        }
    }

    public function test_production_is_rejected_even_with_explicit_flags(): void
    {
        $this->app['env'] = 'production';
        config(['deployment_safety.protect_database' => true, 'deployment_safety.allow_protected_database_seed' => true]);
        $this->expectExceptionMessage('UIUX_V2_SEED_ENVIRONMENT_NOT_ALLOWED');
        (new UiuxV2ScenarioSeeder)->run();
    }

    public function test_explicit_protected_scope_can_add_only_this_scenario(): void
    {
        $this->actor();
        config(['deployment_safety.protect_database' => true, 'deployment_safety.allow_protected_database_seed' => true]);
        (new UiuxV2ScenarioSeeder)->run();
        $this->assertDatabaseCount('import_reports', 4);
        $this->assertTrue(config('deployment_safety.protect_database'));
    }

    public function test_invalid_date_and_missing_or_inactive_actor_leave_no_imports(): void
    {
        foreach (['2026-02-30', '2026-1-2', 'not-a-date'] as $date) {
            config(['uiux_v2_seed.date' => $date]);
            try {
                (new UiuxV2ScenarioSeeder)->run();
                $this->fail('Invalid date accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('DATE', $error->getMessage());
            }
        }
        config(['uiux_v2_seed.date' => '2026-10-29', 'uiux_v2_seed.actor_id' => (string) Str::uuid()]);
        try {
            (new UiuxV2ScenarioSeeder)->run();
            $this->fail('Missing actor accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('ACTOR', $error->getMessage());
        }
        $this->assertDatabaseCount('import_reports', 0);
        $actor = $this->actor();
        $actor->update(['status' => 'INACTIVE']);
        $this->expectExceptionMessage('UIUX_V2_SEED_ACTIVE_ACTOR_REQUIRED');
        (new UiuxV2ScenarioSeeder)->run();
    }

    public function test_recent_import_scenarios_are_repeatable_and_preserve_existing_rows(): void
    {
        $actor = $this->actor();
        $seeder = new UiuxV2ScenarioSeeder;
        $seeder->run();
        $reports = DB::table('import_reports')->orderBy('request_id')->get();
        $this->assertCount(4, $reports);
        $this->assertSame(['COMMIT', 'DRY_RUN'], $reports->pluck('mode')->unique()->sort()->values()->all());
        $this->assertSame(['COMMITTED', 'REJECTED', 'VALID'], $reports->pluck('status')->unique()->sort()->values()->all());
        $large = $reports->firstWhere('error_rows', 125);
        $this->assertNotNull($large);
        $errors = json_decode($large->row_errors, true);
        $this->assertCount(125, $errors);
        $this->assertArrayHasKey('value', $errors[0]);
        $this->assertSame('2026-10-29', substr($large->created_at, 0, 10));
        $this->assertSame((string) $actor->getKey(), $large->actor_id);
        $before = $reports->toJson();
        $seeder->run();
        $this->assertSame($before, DB::table('import_reports')->orderBy('request_id')->get()->toJson());
        config(['uiux_v2_seed.date' => '2026-10-28']);
        $seeder->run();
        $this->assertDatabaseCount('import_reports', 8);
        $this->assertSame($before, DB::table('import_reports')->whereIn('import_report_id', $reports->pluck('import_report_id'))->orderBy('request_id')->get()->toJson());
    }

    public function test_foreign_natural_key_collision_is_rejected_without_modification(): void
    {
        $this->actor();
        (new UiuxV2ScenarioSeeder)->run();
        $report = DB::table('import_reports')->first();
        DB::table('import_reports')->where('import_report_id', $report->import_report_id)->update(['file_name' => 'foreign.xlsx']);
        $before = DB::table('import_reports')->orderBy('request_id')->get()->toJson();
        try {
            (new UiuxV2ScenarioSeeder)->run();
            $this->fail('Foreign row must not be adopted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('COLLISION', $error->getMessage());
        }
        $this->assertSame($before, DB::table('import_reports')->orderBy('request_id')->get()->toJson());
    }

    public function test_failed_operational_phase_rolls_back_imports_and_restores_clock(): void
    {
        $this->actor();
        $this->app->bind(UiuxV2OperationalScenario::class, fn () => new class
        {
            public function run(Carbon $date, string $actorId): array
            {
                throw new RuntimeException('operational failure');
            }
        });
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'Asia/Ho_Chi_Minh'));
        $before = Carbon::getTestNow();
        try {
            (new UiuxV2ScenarioSeeder)->run();
            $this->fail('Failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('operational failure', $error->getMessage());
        } finally {
            $this->assertEquals($before, Carbon::getTestNow());
            Carbon::setTestNow();
        }
        $this->assertDatabaseCount('import_reports', 0);
    }

    public function test_seeded_error_rows_are_available_on_second_api_page(): void
    {
        $this->seed(AuthDatabaseSeeder::class);
        $actor = User::where('email', 'test-super-admin@gmail.com')->firstOrFail();
        config(['uiux_v2_seed.actor_id' => $actor->getKey()]);
        (new UiuxV2ScenarioSeeder)->run();
        $id = DB::table('import_reports')->where('error_rows', 125)->value('import_report_id');
        $this->withToken(JWTAuth::fromUser($actor))->getJson('/api/v1/skus/import-reports/'.$id.'/rows?per_page=100&page=2')
            ->assertOk()->assertJsonPath('meta.total', 125)->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.row_no', 102);
        $this->withToken(JWTAuth::fromUser($actor))->getJson('/api/v1/skus/import-reports?mode=DRY_RUN&keyword=UIUXV2')
            ->assertOk()->assertJsonCount(3, 'data');
        $export = $this->withToken(JWTAuth::fromUser($actor))->get('/api/v1/skus/import-reports/export-excel?keyword=UIUXV2&result=FAILED');
        $export->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'uiux-seed-export-');
        try {
            file_put_contents($tmp, $export->streamedContent());
            $cells = implode(' ', array_map('strval', array_merge(...IOFactory::load($tmp)->getActiveSheet()->toArray())));
            $this->assertStringContainsString('UIUXV2-20261029-import-errors.xlsx', $cells);
            $this->assertStringNotContainsString('UIUXV2-20261029-import-duplicate.xlsx', $cells);
            $this->assertStringNotContainsString('UIUXV2-20261029-import-committed.xlsx', $cells);
        } finally {
            @unlink($tmp);
        }
    }
}
