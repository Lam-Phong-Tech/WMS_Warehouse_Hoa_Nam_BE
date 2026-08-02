<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Warehouse\Repositories\AgencyRepository;
use Modules\Warehouse\Repositories\Contracts\AgencyRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\DefectCatalogRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\SafeStockConfigRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;
use Modules\Warehouse\Repositories\DefectCatalogRepository;
use Modules\Warehouse\Repositories\ProductRepository;
use Modules\Warehouse\Repositories\SafeStockConfigRepository;
use Modules\Warehouse\Repositories\WarehouseRepository;
use Nwidart\Modules\Support\ModuleServiceProvider;

class WarehouseServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Warehouse';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'warehouse';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(WarehouseRepositoryInterface::class, WarehouseRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        $this->app->bind(SafeStockConfigRepositoryInterface::class, SafeStockConfigRepository::class);
        $this->app->bind(AgencyRepositoryInterface::class, AgencyRepository::class);
        $this->app->bind(DefectCatalogRepositoryInterface::class, DefectCatalogRepository::class);
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
