<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Warehouse\Console\Commands\ImportHandToolsDongChengCommand;
use Modules\Warehouse\Console\SeedUatData;
use Modules\Warehouse\Repositories\AgencyRepository;
use Modules\Warehouse\Repositories\ContainerItemRepository;
use Modules\Warehouse\Repositories\ContainerRepository;
use Modules\Warehouse\Repositories\Contracts\AgencyRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ContainerItemRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ContainerRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\DefectCatalogRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ImportBatchRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ItemInstanceRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\PhysicalCodeRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ProductMasterRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;
use Modules\Warehouse\Repositories\DefectCatalogRepository;
use Modules\Warehouse\Repositories\ImportBatchRepository;
use Modules\Warehouse\Repositories\ItemInstanceRepository;
use Modules\Warehouse\Repositories\PhysicalCodeRepository;
use Modules\Warehouse\Repositories\ProductMasterRepository;
use Modules\Warehouse\Repositories\ProductRepository;
use Modules\Warehouse\Repositories\WarehouseRepository;
use Modules\Warehouse\Services\CanonicalScanClassificationService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\DiagnosticCurrentBaselineStockService;
use Modules\Warehouse\Services\ScanClassificationService;
use Nwidart\Modules\Support\ModuleServiceProvider;

class WarehouseServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Warehouse';

    protected string $nameLower = 'warehouse';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SeedUatData::class,
        ImportHandToolsDongChengCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
        Flow06AlignmentServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(WarehouseRepositoryInterface::class, WarehouseRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        // REQ-SRS-037: MVP không có định mức tồn an toàn/kiểm kê — bảng safe_stock_configs
        // đã bị 2026_08_10_000600 drop nên repository tương ứng cũng được gỡ.
        $this->app->bind(AgencyRepositoryInterface::class, AgencyRepository::class);
        $this->app->bind(DefectCatalogRepositoryInterface::class, DefectCatalogRepository::class);
        $this->app->bind(ProductMasterRepositoryInterface::class, ProductMasterRepository::class);
        $this->app->bind(PhysicalCodeRepositoryInterface::class, PhysicalCodeRepository::class);
        $this->app->bind(ImportBatchRepositoryInterface::class, ImportBatchRepository::class);
        $this->app->bind(ContainerRepositoryInterface::class, ContainerRepository::class);
        $this->app->bind(ItemInstanceRepositoryInterface::class, ItemInstanceRepository::class);
        $this->app->bind(ContainerItemRepositoryInterface::class, ContainerItemRepository::class);

        // Keep all canonical stock rules/mutations in CurrentBaselineStockService,
        // but enrich outbound ITEM_ALREADY_MATCHED conflicts with the exact active
        // document holding the item so FE/QA can resolve the reservation directly.
        $this->app->bind(CurrentBaselineStockService::class, DiagnosticCurrentBaselineStockService::class);

        // SQLite does not expose compatibility VIEWs through Schema::hasTable()
        // consistently. Bind the canonical fallback globally so scan/classify always
        // resolves SKU identity from the single source of truth (`sku`).
        $this->app->bind(ScanClassificationService::class, CanonicalScanClassificationService::class);
    }

    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
