<?php

namespace Modules\Auth\Providers;

use App\Support\Modules\HttpLeanModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Auth\Console\Commands\ClearLoginThrottle;
use Modules\Auth\Console\Commands\LockExpiredFirstLoginAccounts;
use Modules\Auth\Console\Commands\PruneRefreshTokens;
use Modules\Auth\Repositories\Contracts\PermissionRepositoryInterface;
use Modules\Auth\Repositories\Contracts\RoleRepositoryInterface;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use Modules\Auth\Repositories\PermissionRepository;
use Modules\Auth\Repositories\RoleRepository;
use Modules\Auth\Repositories\UserRepository;

class AuthServiceProvider extends HttpLeanModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Auth';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'auth';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ClearLoginThrottle::class,
        LockExpiredFirstLoginAccounts::class,
        PruneRefreshTokens::class,
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

    public function register(): void
    {
        parent::register();

        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
    }

    /**
     * Define module schedules.
     *
     * Cấp tài khoản nhân viên: quét mỗi giờ để tài khoản quá hạn 7 ngày chưa
     * đăng nhập lần đầu bị khoá sớm nhất có thể, không phải chờ ai đó chủ động
     * mở danh sách user hoặc thử đăng nhập mới lộ ra.
     *
     * @param  $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('auth:lock-expired-first-login')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // WWHN-SEC-09: dọn refresh token chết (hết hạn/bị thu hồi quá cửa sổ
        // lưu vết) — bảng chỉ tăng theo mỗi lượt đăng nhập nếu không có job này.
        $schedule->command('auth:prune-refresh-tokens')
            ->daily()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
    }
}
