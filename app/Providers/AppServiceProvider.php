<?php

namespace App\Providers;

use App\Support\Cache\CacheService;
use App\Support\RateLimitResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CacheService::class, static fn () => CacheService::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->registerRateLimiters();
    }

    /**
     * WWHN-289 (DEV289-P1-04): SRS §8.1 bảng Security yêu cầu nhóm PV ẩn danh có
     * "rate-limit/anti-abuse controls" và Annex C khai 429 trong error set của
     * chúng. Trước đây codebase không có RateLimiter nào nên 429 không thể xảy ra.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('pv-public', static function (Request $request) {
            $perMinute = (int) config('rate_limit.pv_public_per_minute', 120);
            if ($perMinute <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($perMinute)
                ->by('pv-public:'.$request->ip())
                ->response(static fn (Request $throttled, array $headers) => RateLimitResponse::make(
                    $throttled,
                    $headers,
                    'Vượt quá giới hạn truy cập danh mục PV công khai. Vui lòng thử lại sau.',
                ));
        });

        RateLimiter::for('auth-refresh', static function (Request $request) {
            $perMinute = (int) config('rate_limit.auth_refresh_per_minute', 30);
            if ($perMinute <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($perMinute)
                ->by('auth-refresh:'.$request->ip())
                ->response(static fn (Request $throttled, array $headers) => RateLimitResponse::make(
                    $throttled,
                    $headers,
                    'Vượt quá giới hạn làm mới phiên đăng nhập. Vui lòng thử lại sau.',
                ));
        });
    }
}
