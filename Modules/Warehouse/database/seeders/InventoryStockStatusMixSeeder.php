<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Support\SeedEnvironment;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;

/**
 * Fixture-only opening balances. A cohort is selected once, not from a changing
 * no-stock/ranking query on every replay. Existing legacy cohorts are preserved;
 * their presence is NOT proof that an interrupted historical seed was complete.
 */
class InventoryStockStatusMixSeeder extends Seeder
{
    private const WAREHOUSE_CODE = 'KHO-TONG-HN';

    private const BK_PREFIX = 'SEED-STKMIX';

    private const NON_AVAILABLE_STATUSES = ['DEFECTIVE', 'QUARANTINE', 'NON_SELLABLE'];

    private string $actorId;

    private string $warehouseId;

    public function run(): void
    {
        // This repair never enables fixture writes to a protected deployment DB,
        // even when its APP_ENV happens to be local/testing.
        if ((bool) config('deployment_safety.protect_database', false)) {
            throw new RuntimeException('Stock-mix fixtures require an isolated unprotected database.');
        }
        SeedEnvironment::assertFixtureAccountsAllowed(self::class);
        SeedEnvironment::fixturePassword();
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $warehouse = Warehouse::query()->where('code', self::WAREHOUSE_CODE)->first();
        $actorId = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
        if ($warehouse === null || $actorId === null) {
            $this->command?->warn('InventoryStockStatusMixSeeder: missing canonical fixture warehouse/actor; no fallback.');

            return;
        }
        $this->warehouseId = (string) $warehouse->id;
        $this->actorId = (string) $actorId;
        $added = $this->seedMatchedCohort();
        $this->command?->info('InventoryStockStatusMixSeeder: '.$added.' new matched opening buckets; not full-seed acceptance.');
    }

    private function seedMatchedCohort(): int
    {
        return DB::transaction(function (): int {
            $lock = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtext(?)) AS acquired', [self::BK_PREFIX.':'.$this->warehouseId]);
            if (! $lock->acquired) {
                throw new RuntimeException('Stock-mix fixture cohort is busy; no writes were performed.');
            }
            if (DB::table('stock_movements')->where('warehouse_id', $this->warehouseId)
                ->where('business_key', 'like', self::BK_PREFIX.':%')->exists()) {
                $this->command?->warn('Existing stock-mix cohort preserved; legacy completeness requires separate review.');

                return 0;
            }

            $added = 0;
            foreach ($this->buckets() as [$skuCode, $status, $qty]) {
                $added += $this->addMatchedBucket($skuCode, $status, $qty) ? 1 : 0;
            }

            return $added;
        });
    }

    private function buckets(): array
    {
        $withStock = DB::table('inventory_balances as ib')
            ->join('skus as s', 's.id', '=', 'ib.sku_id')
            ->where('ib.warehouse_id', $this->warehouseId)->where('ib.on_hand_qty', '>', 0)
            ->groupBy('s.sku_code')->orderByRaw('SUM(ib.on_hand_qty) DESC')->orderBy('s.sku_code')
            ->limit(12)->pluck('s.sku_code')->all();
        $withoutStock = DB::table('skus as s')->where('s.status', 'ACTIVE')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('inventory_balances as ib')
                ->where('ib.warehouse_id', $this->warehouseId)->whereColumn('ib.sku_id', 's.id'))
            ->orderBy('s.sku_code')->limit(6)->pluck('s.sku_code')->all();
        $buckets = [];
        foreach ($withStock as $i => $code) {
            $buckets[] = [$code, self::NON_AVAILABLE_STATUSES[$i % 3], 2 + $i % 7];
            if ($i % 3 === 0) {
                $buckets[] = [$code, self::NON_AVAILABLE_STATUSES[($i + 1) % 3], 2 + $i % 4];
            }
        }
        foreach ($withoutStock as $i => $code) {
            $buckets[] = [$code, 'AVAILABLE', 9 + $i * 3];
            $buckets[] = [$code, self::NON_AVAILABLE_STATUSES[$i % 3], 2 + $i % 4];
        }

        return $buckets;
    }

    /** Must execute inside the atomic, warehouse-locked cohort transaction. */
    private function addMatchedBucket(string $skuCode, string $status, int $qty): bool
    {
        $skuId = DB::table('skus')->where('sku_code', $skuCode)->value('id');
        if ($skuId === null) {
            throw new RuntimeException('Selected fixture SKU disappeared; roll back the whole cohort.');
        }
        $businessKey = self::BK_PREFIX.':'.$status.':'.$skuId;
        if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
            throw new RuntimeException('Stock-mix fixture key already exists outside this cohort; review without replay.');
        }
        $balance = DB::table('inventory_balances')->where('warehouse_id', $this->warehouseId)
            ->where('sku_id', $skuId)->where('stock_status', $status)->lockForUpdate()->first();
        $before = $balance === null ? 0 : (int) $balance->quantity;
        $after = $before + $qty;
        $available = $status === 'AVAILABLE' ? $qty : 0;
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'movement_no' => 'SM-STKMIX-'.Str::upper(Str::random(12)),
            'business_key' => $businessKey, 'movement_type' => 'OPENING',
            'warehouse_id' => $this->warehouseId, 'product_id' => $skuId, 'sku_id' => $skuId,
            'stock_status' => $status, 'qty_before' => $before, 'qty_delta' => $qty,
            'qty_after' => $after, 'quantity' => $qty, 'source_type' => 'SEEDER',
            'created_by' => $this->actorId, 'request_id' => 'seed-stkmix-'.strtolower($status),
            'occurred_at' => now(), 'created_at' => now(),
        ]);
        if ($balance === null) {
            DB::table('inventory_balances')->insert([
                'id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouseId,
                'product_id' => $skuId, 'sku_id' => $skuId, 'stock_status' => $status,
                'on_hand_qty' => $qty, 'available_qty' => $available, 'reserved_qty' => 0,
                'quantity' => $qty, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'on_hand_qty' => DB::raw('on_hand_qty + '.$qty),
                'available_qty' => DB::raw('available_qty + '.$available),
                'quantity' => $after, 'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
        }

        return true;
    }
}
