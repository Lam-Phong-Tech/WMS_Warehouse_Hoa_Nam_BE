<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Support\SeedEnvironment;
use Modules\Auth\Support\SeedRuntimeCoverage;
use Modules\Notification\Database\Seeders\NotificationDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\DailySkuCoverageSeeder;
use Modules\Warehouse\Database\Seeders\DisplayFieldCoverageSeeder;
use Modules\Warehouse\Database\Seeders\DongChengCatalogSeeder;
use Modules\Warehouse\Database\Seeders\FinalFixtureLocationSeeder;
use Modules\Warehouse\Database\Seeders\HoaNamBulkDemoSeeder;
use Modules\Warehouse\Database\Seeders\InventoryStockStatusMixSeeder;
use Modules\Warehouse\Database\Seeders\MasterCatalogCoverageSeeder;
use Modules\Warehouse\Database\Seeders\OperationalReportRangeSeeder;
use Modules\Warehouse\Database\Seeders\SkuAttributeBackfillSeeder;
use Modules\Warehouse\Database\Seeders\SkuStockThresholdSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseBusinessDataSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseOperationsCoverageSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseSupplementalDataSeeder;
use Modules\Warehouse\Database\Seeders\WarrantyCheckResultBackfillSeeder;
use Modules\Warehouse\Database\Seeders\WarrantyReportRangeSeeder;
use Modules\Warehouse\Support\SeedRunContext;
use Modules\Warehouse\Support\SeedStrictCommand;
use RuntimeException;

/**
 * Preserve the existing complete phase order, then finalize new item hints.
 * This is a fixture chain, not a production master-data migration command.
 * A completed child/exit 0 is not full-chain idempotency or release approval.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Stop BEFORE Auth can update permissions/accounts or catalog can write.
        // The separate break-glass flag does not authorize this fixture chain.
        if ((bool) config('deployment_safety.protect_database', true)) {
            throw new RuntimeException('FULL_FIXTURE_SEED_PROTECTED_DATABASE: stopped before master/auth/business writes.');
        }
        if (SeedEnvironment::allowsFixtureAccounts()) {
            SeedEnvironment::fixturePassword();
        }

        $scope = null;
        $originalCommand = $this->command;
        try {
            if (DB::getDriverName() === 'pgsql') {
                SeedRuntimeCoverage::required();
                $scope = new SeedRunContext;
                app()->instance(SeedRunContext::class, $scope);
                $scope->begin();
            }
            // Standard warning/error paths must not silently complete the chain.
            // Unknown/legacy warnings require owner review, not an automatic waiver.
            $this->command = new SeedStrictCommand($originalCommand);

            $this->call([
                AuthDatabaseSeeder::class,
                WarehouseDatabaseSeeder::class,
                DongChengCatalogSeeder::class,
                WarehouseBusinessDataSeeder::class,
                WarehouseSupplementalDataSeeder::class,
                AuditLogDatabaseSeeder::class,
                NotificationDatabaseSeeder::class,
                OperationalReportRangeSeeder::class,
            ]);

            if (DB::getDriverName() === 'pgsql') {
                $this->call([
                    MasterCatalogCoverageSeeder::class,
                    HoaNamBulkDemoSeeder::class,
                    WarehouseOperationsCoverageSeeder::class,
                    WarrantyReportRangeSeeder::class,
                    InventoryStockStatusMixSeeder::class,
                    WarrantyCheckResultBackfillSeeder::class,
                    SkuAttributeBackfillSeeder::class,
                    // Keep daily coverage after stock mix and before final display.
                    DailySkuCoverageSeeder::class,
                    SkuStockThresholdSeeder::class,
                    DisplayFieldCoverageSeeder::class,
                ]);
                $this->call([FinalFixtureLocationSeeder::class]);
            }
        } finally {
            // No claim that earlier business phases or file writes rolled back.
            try {
                $scope?->end();
            } finally {
                if ($scope !== null) {
                    app()->forgetInstance(SeedRunContext::class);
                }
                $this->command = $originalCommand;
            }
        }
    }
}
