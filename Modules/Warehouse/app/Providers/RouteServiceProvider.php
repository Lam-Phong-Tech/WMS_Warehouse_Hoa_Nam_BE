<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Middleware\MiniAppDraftHandoff;
use Modules\Warehouse\Services\MiniAppDraftService;

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
        $this->mapWorkShiftRoutes();
        $this->mapImportRoutes();
        $this->mapGovernanceRoutes();
        $this->mapWebRoutes();
        Route::middleware('api')->prefix('api')->name('api.p03.')->group(module_path($this->name, '/routes/p03_drafts.php'));
        Route::middleware('api')->prefix('api')->name('api.p04.')->group(module_path($this->name, '/routes/p04_inbound.php'));
        foreach (Route::getRoutes() as $route) {
            if (isset(MiniAppDraftService::RECORD_ROUTES[$route->uri()]) && in_array('POST', $route->methods(), true)) {
                $route->middleware(MiniAppDraftHandoff::class);
            }
        }
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

    protected function mapWorkShiftRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/work_shifts.php'));
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
