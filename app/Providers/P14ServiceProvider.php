<?php

namespace App\Providers;

use App\Http\Middleware\P14ShiftOwnerFence;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Modules\Auth\Http\Middleware\CheckPermission;

final class P14ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The HTTP kernel re-synchronizes middleware groups to the router.
        // Register on the resolved kernel, not only on the router during boot.
        // The fence remains a no-op unless the explicit integration gate is enabled.
        $this->callAfterResolving(HttpKernelContract::class, function (Kernel $kernel): void {
            $kernel->appendMiddlewareToGroup('api', P14ShiftOwnerFence::class);
            $kernel->addToMiddlewarePriorityAfter(CheckPermission::class, P14ShiftOwnerFence::class);
        });
        $this->loadRoutesFrom(base_path('routes/p14.php'));
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('p14:recovery-dispatch')->everyMinute()->withoutOverlapping()
                ->when(fn (): bool => (bool) config('p14.recovery_enabled'));
            $schedule->command('p14:recovery-purge')->hourly()->withoutOverlapping();
        });
    }
}
