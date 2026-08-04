<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Database\Seeders\WmsPermissionCompatibilitySeeder;
use Modules\Warehouse\Database\Seeders\StocktakeGrainBackfillSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuthDatabaseSeeder::class,
            WmsPermissionCompatibilitySeeder::class,
            WarehouseDatabaseSeeder::class,
            StocktakeGrainBackfillSeeder::class,
            AuditLogDatabaseSeeder::class,
        ]);
    }
}
