<?php

namespace Modules\Notification\Providers;

use App\Support\Modules\HttpLeanModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Notification\Console\Commands\BackfillNotificationActorsCommand;
use Modules\Notification\Console\Commands\DrainNotificationDeliveries;
use Modules\Notification\Console\Commands\MonitorNotificationBurst;
use Modules\Notification\Console\Commands\PruneNotificationDeliveryStateCommand;
use Modules\Notification\Console\Commands\PruneNotificationRecipientsCommand;
use Modules\Notification\Delivery\FcmAccessTokenSource;
use Modules\Notification\Delivery\GoogleFcmAccessTokenSource;

class NotificationServiceProvider extends HttpLeanModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notification';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notification';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        BackfillNotificationActorsCommand::class,
        DrainNotificationDeliveries::class,
        PruneNotificationDeliveryStateCommand::class,
        PruneNotificationRecipientsCommand::class,
        MonitorNotificationBurst::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * routes/channels.php đã được nạp qua bootstrap/app.php (withRouting(channels:
     * ...)) — chính điều này cũng tự thêm 1 route mặc định GET|POST /broadcasting/auth
     * dùng guard `web`. Route đó không nguy hiểm (JWT/api guard mới là cái FE thật
     * sự dùng) nhưng KHÔNG phải route channel-auth mà FE gọi: channel auth thật cho
     * SPA JWT nằm ở POST /api/v1/broadcasting/auth (routes/api.php module này, bọc
     * auth:api) vì app không có session/Sanctum để route mặc định resolve đúng user.
     */

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // DB leases and admission locks already serialize sends; recovery must not need Redis mutexes.
        $schedule->command('notification-delivery:drain --limit=100')->everyMinute();
        $schedule->command('notification-delivery:prune --limit=250')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('notification-retention:prune --limit=5000')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
        $schedule->command('notifications:monitor --json')->everyMinute();
    }

    public function register(): void
    {
        parent::register();
        $this->app->bind(FcmAccessTokenSource::class, GoogleFcmAccessTokenSource::class);
    }
}
