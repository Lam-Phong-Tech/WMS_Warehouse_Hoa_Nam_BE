<?php

namespace Modules\Notification\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class NotificationServiceProvider extends ModuleServiceProvider
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
     *
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
