<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Support\SeedRuntimeCoverage;
use Modules\Warehouse\Support\SeedStrictCommand;
use RuntimeException;

/**
 * Daily owner stays WarehouseOperationsCoverageSeeder and retains its services.
 * Run after InventoryStockStatusMixSeeder, before final display enrichment.
 * Validate the same process input as preflight before delegating to the owner.
 * No .env/config-cache inference or automatic 0/14-day acceptance shortcut.
 */
class DailySkuCoverageSeeder extends Seeder
{
    public function run(): void
    {
        if ((bool) config('deployment_safety.protect_database', true)) {
            throw new RuntimeException('DAILY_FIXTURE_SEED_PROTECTED_DATABASE');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        SeedRuntimeCoverage::required();

        $seeder = new WarehouseOperationsCoverageSeeder;
        $seeder->setCommand(new SeedStrictCommand($this->command));
        $seeder->runDailyCoverageOnly();
    }
}
