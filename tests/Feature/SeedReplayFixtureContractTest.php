<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\DailySkuCoverageSeeder;
use Modules\Warehouse\Database\Seeders\FinalFixtureLocationSeeder;
use Modules\Warehouse\Database\Seeders\InventoryStockStatusMixSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseOperationsCoverageSeeder;
use Modules\Warehouse\Support\SeedRunContext;
use Modules\Warehouse\Support\SeedStrictCommand;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Actual migrated isolated schema; no live fixture credentials or full seed. */
final class SeedReplayFixtureContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
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
            throw new RuntimeException('Seed replay tests require isolated memory or localhost p12_contract_test.');
        }
        parent::setUp();
        config(['golive.development_environments' => ['testing'], 'golive.seed.demo_accounts' => false,
            'golive.seed.fixture_password' => bin2hex(random_bytes(24))]);
    }

    public function test_real_seeded_fixture_logs_in_reads_identity_and_logs_out_without_exporting_secrets(): void
    {
        $this->seed(AuthDatabaseSeeder::class);
        $user = User::where('username', 'admin')->firstOrFail();
        $password = config('golive.seed.fixture_password');
        $this->assertTrue(Hash::check($password, $user->password_hash));
        $response = $this->browserLogin($user->email, $password)->assertOk()->assertJsonMissingPath('data.refresh_token');
        $this->assertStringNotContainsString($password, $response->getContent());
        $this->assertTrue($this->refreshCookie($response)->isHttpOnly());
        $token = $response->json('data.access_token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->getJson('/api/v1/auth/me', $this->sessionHeaders($token))->assertOk();
        $this->postJson('/api/v1/auth/logout', [], $this->sessionHeaders($token))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->sessionHeaders($token))->assertUnauthorized();
    }

    public function test_protected_seeders_fail_before_queries_even_with_a_local_fixture_password(): void
    {
        config(['deployment_safety.protect_database' => true, 'deployment_safety.allow_protected_database_seed' => true]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([new InventoryStockStatusMixSeeder, new WarehouseOperationsCoverageSeeder] as $seeder) {
            try {
                $seeder->run();
                $this->fail('Protected database must remain blocked.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('isolated', $exception->getMessage());
            }
        }
        $this->assertSame([], $queries);
    }

    public function test_stock_mix_is_postgresql_only_and_replay_preserves_the_actual_schema(): void
    {
        $before = $this->stockFingerprint();
        $seeder = new InventoryStockStatusMixSeeder;
        if (DB::getDriverName() !== 'pgsql') {
            $seeder->run();
            $this->assertSame($before, $this->stockFingerprint(), 'Unsupported driver must not seed balances.');

            return;
        }
        $this->seed(AuthDatabaseSeeder::class);
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated seed replay warehouse');
        for ($i = 0; $i < 20; $i++) {
            $sku = $this->productSkuFixture(sprintf('REPLAY-ACTUAL-%03d', $i));
            if ($i === 0) {
                $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 50);
            }
        }
        $seeder->run();
        $this->assertGreaterThan(0, DB::table('stock_movements')->where('business_key', 'like', 'SEED-STKMIX:%')->count());
        $after = $this->stockFingerprint();
        $seeder->run();
        $this->assertSame($after, $this->stockFingerprint());
        (new InventoryStockStatusMixSeeder)->run();
        $this->assertSame($after, $this->stockFingerprint(), 'Fresh PHP object cannot select the next no-stock cohort.');
    }

    public function test_protected_default_and_daily_entrypoints_stop_before_master_or_auth_queries(): void
    {
        config(['deployment_safety.protect_database' => true, 'deployment_safety.allow_protected_database_seed' => true]);
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        foreach ([new DatabaseSeeder, new DailySkuCoverageSeeder] as $seeder) {
            try {
                $seeder->run();
                $this->fail('Protected full/daily seed must fail before any child write.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('PROTECTED_DATABASE', $error->getMessage());
            }
        }
        $this->assertSame([], $queries);
    }

    public function test_missing_process_coverage_blocks_pgsql_chain_and_unsupported_daily_driver_is_noop(): void
    {
        config(['deployment_safety.protect_database' => false]);
        if (DB::getDriverName() !== 'pgsql') {
            $before = $this->stockFingerprint();
            (new DailySkuCoverageSeeder)->run();
            $this->assertSame($before, $this->stockFingerprint(), 'SQLite does not execute the PostgreSQL daily owner.');

            return;
        }
        $key = 'HN_DAILY_COVERAGE_DAYS';
        $old = getenv($key);
        putenv($key);
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        try {
            (new DatabaseSeeder)->run();
            $this->fail('Missing process coverage must not use an implicit default.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('EXPLICIT_RUNTIME_COVERAGE_REQUIRED', $error->getMessage());
            $this->assertSame([], $queries);
        } finally {
            putenv($old === false ? $key : $key.'='.$old);
        }
    }

    public function test_unclassified_warnings_and_errors_are_fatal_and_raw_text_is_redacted(): void
    {
        $command = new SeedStrictCommand;
        foreach (['warn' => 'WARNING', 'error' => 'ERROR'] as $method => $kind) {
            $privateText = 'Do-not-export-'.bin2hex(random_bytes(16));
            try {
                $command->{$method}($privateText);
                $this->fail('Unclassified seed output must not complete successfully.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('SEED_'.$kind.'_REQUIRES_REVIEW', $error->getMessage());
                $this->assertStringNotContainsString($privateText, $error->getMessage());
                $this->assertStringNotContainsString($privateText, $error->getTraceAsString());
                $this->assertStringContainsString('earlier writes may have committed', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_late_hints_preserve_baseline_and_repeat_while_unsupported_scope_is_refused(): void
    {
        config(['deployment_safety.protect_database' => false]);
        $scope = new SeedRunContext;
        app()->instance(SeedRunContext::class, $scope);
        if (DB::getDriverName() !== 'pgsql') {
            $scope->begin();
            $this->assertFalse($scope->active());
            try {
                (new FinalFixtureLocationSeeder)->run();
                $this->fail('A missing supported run scope must not enrich any rows.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('PROVENANCE_REQUIRED', $error->getMessage());
            } finally {
                $scope->end();
            }

            return;
        }
        $sku = $this->productSkuFixture('REPLAY-LATE-HINT');
        $baseline = $this->seedItemInStock($sku->sku_id);
        $baselineBefore = DB::table('item_instances')->where('item_id', $baseline)->first();
        $scope->begin();
        try {
            $early = $this->seedItemInStock($sku->sku_id);
            $late = $this->seedItemInStock($sku->sku_id);
            $located = $this->seedItemInStock($sku->sku_id);
            DB::table('item_instances')->where('item_id', $located)->update(['location_hint' => 'Actual assigned location']);
            (new FinalFixtureLocationSeeder)->run();
            $this->assertNotNull(DB::table('item_instances')->where('item_id', $early)->value('location_hint'));
            $this->assertNotNull(DB::table('item_instances')->where('item_id', $late)->value('location_hint'));
            $this->assertEquals($baselineBefore, DB::table('item_instances')->where('item_id', $baseline)->first());
            $this->assertSame('Actual assigned location', DB::table('item_instances')->where('item_id', $located)->value('location_hint'));
            $before = DB::table('item_instances')->orderBy('item_id')->get()->toJson();
            (new FinalFixtureLocationSeeder)->run();
            $this->assertSame($before, DB::table('item_instances')->orderBy('item_id')->get()->toJson());
        } finally {
            $scope->end();
        }
        $scope->begin();
        try {
            (new FinalFixtureLocationSeeder)->run();
            $this->assertSame($before, DB::table('item_instances')->orderBy('item_id')->get()->toJson());
        } finally {
            $scope->end();
        }
    }

    public function test_postgresql_run_lock_rejects_a_competing_connection_and_unsupported_scope_stays_inactive(): void
    {
        config(['deployment_safety.protect_database' => false]);
        $scope = new SeedRunContext;
        if (DB::getDriverName() !== 'pgsql') {
            $scope->begin();
            $this->assertFalse($scope->active());
            $scope->end();
            $this->assertSame([], $scope->freshIds('inventory_balances'));

            return;
        }
        $default = config('database.default');
        config(['database.connections.seed_lock_probe' => config('database.connections.'.$default)]);
        $other = DB::connection('seed_lock_probe');
        $key = 'hoa-nam:isolated-full-fixture-chain:v2';
        $other->selectOne('SELECT pg_advisory_lock(hashtext(?))', [$key]);
        $before = $this->stockFingerprint();
        try {
            $scope->begin();
            $this->fail('A competing PostgreSQL full-chain connection must not start phases.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('FULL_FIXTURE_SEED_BUSY', $error->getMessage());
            $this->assertSame($before, $this->stockFingerprint());
            $this->assertFalse($scope->active());
        } finally {
            $scope->end();
            $other->selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            DB::purge('seed_lock_probe');
        }
    }

    public function test_new_balance_boundary_excludes_preexisting_stock_and_unsupported_scope_claims_nothing(): void
    {
        config(['deployment_safety.protect_database' => false]);
        $scope = new SeedRunContext;
        if (DB::getDriverName() !== 'pgsql') {
            $scope->begin();
            $this->assertSame([], $scope->freshUnmixedBalanceIds());
            $scope->end();

            return;
        }
        $warehouse = $this->warehouseFixture();
        $old = $this->productSkuFixture('REPLAY-BOUNDARY-OLD');
        $new = $this->productSkuFixture('REPLAY-BOUNDARY-NEW');
        $this->seedAvailableBalance($warehouse->id, $old->sku_id, 5);
        $scope->begin();
        try {
            $foreign = (string) Str::uuid();
            DB::table('inventory_balances')->insert([
                'id' => $foreign, 'warehouse_id' => $warehouse->id, 'sku_id' => $old->sku_id,
                'product_id' => $old->sku_id, 'stock_status' => 'DEFECTIVE', 'quantity' => 2,
                'on_hand_qty' => 2, 'available_qty' => 0, 'reserved_qty' => 0, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->seedAvailableBalance($warehouse->id, $new->sku_id, 4);
            $eligible = $scope->freshUnmixedBalanceIds();
            $this->assertCount(1, $eligible);
            $this->assertNotContains($foreign, $eligible);
            $this->assertSame($new->sku_id, DB::table('inventory_balances')->where('id', $eligible[0])->value('sku_id'));
        } finally {
            $scope->end();
        }
    }

    private function stockFingerprint(): array
    {
        $result = [];
        foreach (['stock_movements', 'inventory_balances'] as $table) {
            $rows = DB::table($table)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', implode("\n", $rows));
        }

        return $result;
    }

    private function sessionHeaders(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
