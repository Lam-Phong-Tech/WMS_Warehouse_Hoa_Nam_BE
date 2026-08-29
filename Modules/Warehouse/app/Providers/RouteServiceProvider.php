<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Warehouse';

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapPublicApiRoutes();
        $this->mapMiniAppRoutes();
        $this->mapImportRoutes();
        $this->mapGovernanceRoutes();
        $this->mapWebRoutes();
    }

    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }

    /**
     * Storefront công khai của App PV (`/api/v1/public/*`). File route riêng vì
     * nhóm này chạy envelope riêng và ẩn danh hoàn toàn — xem
     * routes/public_api.php.
     */
    protected function mapPublicApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.public.')->group(module_path($this->name, '/routes/public_api.php'));
    }

    /**
     * Dedicated adapter routes for the Warehouse Mini App. They are loaded from a
     * separate file so the established Web WMS API surface remains unchanged.
     */
    protected function mapMiniAppRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.mini-app.')->group(module_path($this->name, '/routes/mini_app.php'));
    }

    protected function mapImportRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/import.php'));
    }

    protected function mapGovernanceRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/governance.php'));
    }
}
