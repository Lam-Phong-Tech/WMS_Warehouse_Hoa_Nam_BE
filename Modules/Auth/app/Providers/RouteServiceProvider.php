<?php

namespace Modules\Auth\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\P10ProfileController;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Auth';

    public function boot(): void
    {
        // Resolve existing and route-cached auth actions without duplicate routes.
        // P10 overrides self-service only; login/refresh remain inherited.
        $this->app->bind(AuthController::class, P10ProfileController::class);
        parent::boot();
    }

    public function map(): void
    {
        $this->mapApiRoutes();
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
}
