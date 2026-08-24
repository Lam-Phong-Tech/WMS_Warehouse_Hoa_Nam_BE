<?php

namespace App\Providers;

use App\Support\Cache\CacheService;
use App\Support\RateLimitResponse;
use App\Swagger\Processors\MiniAppTagProcessor;
use App\Swagger\Processors\RouteSyncProcessor;
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

        // Swagger/OpenAPI source is authoritative on local/dev/test. Regenerate the
        // JSON consumed by Swagger UI on request so newly added runtime contracts
        // (notably /api/v1/mini-app/*) cannot remain hidden behind a stale
        // storage/api-docs/api-docs.json. Production stays static unless
        // L5_SWAGGER_GENERATE_ALWAYS is explicitly enabled by operations.
        if ($this->app->environment(['local', 'development', 'dev', 'testing'])) {
            config()->set('l5-swagger.defaults.generate_always', true);
        }

        $this->registerSwaggerProcessors();
        $this->registerRateLimiters();
    }

    /**
     * Keep a final presentation guard after RouteSyncProcessor for the dedicated
     * Warehouse Mini App API surface. RouteSyncProcessor already maps
     * /api/v1/mini-app/* to "22. Mini App"; this post-processor reasserts the same
     * invariant so later generic Swagger processor changes cannot silently move
     * Mini App operations into Platform or canonical Stock In.
     */
    private function registerSwaggerProcessors(): void
    {
        $key = 'l5-swagger.defaults.scanOptions.processors';
        $processors = (array) config($key, []);

        foreach ($processors as $processor) {
            $class = is_array($processor) ? ($processor['class'] ?? null) : $processor;
            if ($class === MiniAppTagProcessor::class) {
                return;
            }
        }

        $processors[] = [
            'class' => MiniAppTagProcessor::class,
            'after' => RouteSyncProcessor::class,
        ];

        config()->set($key, $processors);
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
