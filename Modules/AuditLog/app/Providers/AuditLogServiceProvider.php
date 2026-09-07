<?php

namespace Modules\AuditLog\Providers;

use App\Support\Modules\HttpLeanModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\AuditLog\Repositories\AuditLogRepository;
use Modules\AuditLog\Repositories\Contracts\AuditLogRepositoryInterface;
use Modules\AuditLog\Services\AuditLogger;

class AuditLogServiceProvider extends HttpLeanModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'AuditLog';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'auditlog';

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

        $this->app->singleton(AuditLogger::class);
        $this->app->bind(AuditLogRepositoryInterface::class, AuditLogRepository::class);
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
