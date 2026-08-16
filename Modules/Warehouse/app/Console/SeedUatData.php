<?php

namespace Modules\Warehouse\Console;

use Illuminate\Console\Command;
use Modules\Warehouse\Database\Seeders\UatScenarioSeeder;

class SeedUatData extends Command
{
    protected $signature = 'wms:seed-uat {--password= : Password assigned to UAT accounts}';

    protected $description = 'Seed idempotent UAT accounts, role assignments, and an import traceability scenario.';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: config('warehouse.uat_seed_password'));
        if ($password === '') {
            $this->error('Missing UAT password. Use --password or set UAT_SEED_PASSWORD.');

            return self::FAILURE;
        }

        config(['warehouse.uat_seed_password' => $password]);
        $this->call('db:seed');
        $this->call('db:seed', ['--class' => UatScenarioSeeder::class]);
        $this->info('UAT seed complete: 4 accounts and 1 import traceability scenario are ready.');

        return self::SUCCESS;
    }
}
