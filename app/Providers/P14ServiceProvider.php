<?php

namespace App\Providers;

use App\Http\Middleware\P14ShiftOwnerFence;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class P14ServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        // This middleware is a no-op until the explicit P14 integration gate is enabled.
        $router->pushMiddlewareToGroup('api', P14ShiftOwnerFence::class);
        $this->loadRoutesFrom(base_path('routes/p14.php'));
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('p14:recovery-dispatch')->everyMinute()->withoutOverlapping()
                ->when(fn (): bool => (bool) config('p14.recovery_enabled'));
            $schedule->command('p14:recovery-purge')->hourly()->withoutOverlapping();
        });
    }
}
