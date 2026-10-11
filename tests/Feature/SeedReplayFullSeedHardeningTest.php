<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Database\Seeders\DailySkuCoverageSeeder;
use Modules\Warehouse\Database\Seeders\FinalFixtureLocationSeeder;
use Modules\Warehouse\Support\SeedRunContext;
use RuntimeException;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Selected real-schema regressions; does not execute the complete seed chain. */
final class SeedReplayFullSeedHardeningTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL regression database.');
        }
        config(['deployment_safety.protect_database' => false]);
    }

    public function test_protected_full_chain_stops_before_master_auth_or_database_reads(): void
    {
        config(['deployment_safety.protect_database' => true]);
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        foreach ([new DatabaseSeeder, new DailySkuCoverageSeeder] as $seeder) {
            try {
                $seeder->run();
                $this->fail('Protected fixture seed must fail.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('PROTECTED_DATABASE', $error->getMessage());
            }
        }
        $this->assertSame([], $queries);
    }

    public function test_missing_process_coverage_stops_full_chain_before_database_queries(): void
    {
        $this->pgsql();
        $key = 'HN_DAILY_COVERAGE_DAYS';
        $old = getenv($key);
        putenv($key);
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        try {
            (new DatabaseSeeder)->run();
            $this->fail('Missing process coverage must not use a default.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('EXPLICIT_RUNTIME_COVERAGE_REQUIRED', $error->getMessage());
            $this->assertSame([], $queries);
        } finally {
            putenv($old === false ? $key : $key.'='.$old);
        }
    }

    public function test_late_location_phase_covers_new_items_without_touching_existing_items(): void
    {
        $this->pgsql();
        $sku = $this->productSkuFixture('REPLAY-LOCATION');
        $baseline = $this->seedItemInStock($sku->sku_id);
        $baselineBefore = DB::table('item_instances')->where('item_id', $baseline)->first();
        $scope = new SeedRunContext;
        app()->instance(SeedRunContext::class, $scope);
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

    public function test_new_balance_scope_never_claims_existing_operational_stock_of_same_sku(): void
    {
        $this->pgsql();
        $warehouse = $this->warehouseFixture();
        $old = $this->productSkuFixture('REPLAY-BALANCE-OLD');
        $new = $this->productSkuFixture('REPLAY-BALANCE-NEW');
        $this->seedAvailableBalance($warehouse->id, $old->sku_id, 5);
        $scope = new SeedRunContext;
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
}
