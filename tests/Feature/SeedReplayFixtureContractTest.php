<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\InventoryStockStatusMixSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseOperationsCoverageSeeder;
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
