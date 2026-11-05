<?php

namespace App\Providers;

use App\Support\Cache\CacheAutoInvalidator;
use App\Support\Cache\CacheService;
use App\Support\Database\PostgresTimestampGrammar;
use App\Support\Database\SchemaCache;
use App\Support\RateLimitResponse;
use App\Swagger\Processors\MiniAppTagProcessor;
use App\Swagger\Processors\RouteSyncProcessor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Events\DatabaseRefreshed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Tymon\JWTAuth\Http\Parser\AuthHeaders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Applies to Eloquent and query-builder DateTime bindings, including
        // audit/outbox, sessions and workers, on every newly resolved connection.
        $this->app['events']->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            if ($event->connection instanceof PostgresConnection) {
                $event->connection->setQueryGrammar(new PostgresTimestampGrammar($event->connection));
            }
        });

        $this->app->singleton(CacheService::class, static fn () => CacheService::make());

        // WWHN-SEC-09: khoá kênh nhận JWT về đúng Authorization header. Dùng
        // extend trên binding parser (thay vì setChain qua facade lúc boot) vì
        // guard `api` và facade JWTAuth có thể giữ HAI instance Parser khác nhau
        // — mutate qua facade thì chuỗi của guard vẫn còn QueryString/InputSource
        // và `?token=` tiếp tục được chấp nhận (đã tái hiện bằng test).
        $this->app->extend('tymon.jwt.parser', static function ($parser) {
            $parser->setChain([new AuthHeaders]);

            return $parser;
        });
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
        // storage/api-docs/api-docs.json. An explicit operations policy overrides
        // this development default; production stays static unless enabled.
        if ($this->app->environment(['local', 'development', 'dev', 'testing'])
            && config('l5-swagger.defaults.generate_always_configured', false) === false) {
            config()->set('l5-swagger.defaults.generate_always', true);
        }

        $this->registerSwaggerProcessors();
        $this->registerRateLimiters();
        $this->registerDeploymentDatabaseSafety();
        $this->registerCacheAutoInvalidation();
        $this->registerSchemaCacheInvalidation();
    }

    /**
     * `SchemaCache` nhớ danh sách bảng/cột trong RAM của TIẾN TRÌNH. Schema chỉ
     * đổi thật khi migration chạy — trong artisan, và trong test khi bộ dựng lại
     * DB giữa các case. Xoá sạch đúng những thời điểm đó là đủ để bộ nhớ đệm
     * không bao giờ trả lời theo schema cũ.
     */
    private function registerSchemaCacheInvalidation(): void
    {
        foreach ([
            MigrationsStarted::class,
            MigrationsEnded::class,
            MigrationEnded::class,
            DatabaseRefreshed::class,
        ] as $event) {
            Event::listen($event, static fn () => SchemaCache::flush());
        }
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

        // Storefront công khai `/api/v1/public/*` — nhóm ĐỌC. Limiter riêng chứ
        // không dùng chung 'pv-public': hai mặt API có tải và đối tượng khác
        // nhau, gộp chung thì lưu lượng của app này làm cạn hạn mức của app kia.
        RateLimiter::for('public-api', static function (Request $request) {
            $perMinute = (int) config('rate_limit.public_api_per_minute', 180);
            if ($perMinute <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($perMinute)
                ->by('public-api:'.$request->ip())
                ->response(static fn (Request $throttled, array $headers) => RateLimitResponse::make(
                    $throttled,
                    $headers,
                    'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.',
                ));
        });

        // Nhóm GHI (POST /quote-requests): ngưỡng thấp hơn hẳn nhóm đọc. Đây là
        // form lead-gen ẩn danh — bề mặt spam kinh điển — nên khoá theo (IP + số
        // điện thoại): một IP dùng chung (quán net, 4G NAT) không tự khoá lẫn
        // nhau, nhưng một người spam nhiều lần với cùng số thì bị chặn.
        RateLimiter::for('public-quote-request', static function (Request $request) {
            $perMinute = (int) config('rate_limit.public_quote_request_per_minute', 5);
            if ($perMinute <= 0) {
                return Limit::none();
            }

            $phone = (string) $request->input('phone', '');
            $subject = $phone !== '' ? sha1($phone) : 'anonymous';

            return Limit::perMinute($perMinute)
                ->by('public-quote-request:'.$request->ip().':'.$subject)
                ->response(static fn (Request $throttled, array $headers) => RateLimitResponse::make(
                    $throttled,
                    $headers,
                    'Bạn đã gửi quá nhiều yêu cầu tư vấn. Vui lòng thử lại sau ít phút.',
                ));
        });

        // WWHN-SEC-08 — bước 2 của đăng nhập. Mã 6 chữ số chỉ có 10^6 khả năng,
        // nên đây là bề mặt dò rõ ràng nhất của cả luồng xác thực.
        //
        // Khoá theo (IP + mfa_token) chứ không chỉ IP: một mã phiên chờ chỉ nên
        // được thử vài lần, còn khoá thuần theo IP sẽ để cả văn phòng dùng chung
        // NAT tự khoá lẫn nhau khi một người gõ nhầm. Trần theo TÀI KHOẢN nằm ở
        // MfaService (failed_attempts + locked_until) — hai lớp bù cho nhau:
        // throttle IP bị vượt bằng botnet, còn trần tài khoản không chặn được kẻ
        // rải mã qua nhiều tài khoản khác nhau.
        RateLimiter::for('auth-mfa-verify', static function (Request $request) {
            $perMinute = (int) config('rate_limit.auth_mfa_verify_per_minute', 10);
            if ($perMinute <= 0) {
                return Limit::none();
            }

            $challenge = (string) $request->input('mfa_token', '');

            return Limit::perMinute($perMinute)
                ->by('auth-mfa-verify:'.$request->ip().':'.($challenge !== '' ? sha1($challenge) : 'anonymous'))
                ->response(static fn (Request $throttled, array $headers) => RateLimitResponse::make(
                    $throttled,
                    $headers,
                    'Bạn đã nhập mã xác thực quá nhiều lần. Vui lòng thử lại sau.',
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

    /**
     * Deployed databases are not disposable. A normal release may advance the
     * schema with `migrate --force`, but may never reset it or silently rerun the
     * full seed bundle, which also contains catalog-retirement behaviour.
     */
    private function registerDeploymentDatabaseSafety(): void
    {
        if (! (bool) config('deployment_safety.protect_database')) {
            return;
        }

        DB::prohibitDestructiveCommands();
        SeedCommand::prohibit(! (bool) config('deployment_safety.allow_protected_database_seed'));

        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command !== 'db:seed' || (bool) config('deployment_safety.allow_protected_database_seed')) {
                return;
            }

            throw new RuntimeException(
                'db:seed bị chặn trên CSDL được bảo vệ. Deploy chỉ được dùng migrate --force. '
                .'Seed dữ liệu phải được duyệt riêng và đặt WMS_ALLOW_PROTECTED_DATABASE_SEED=CONFIRM_SEED_WRITE cho đúng một lệnh.',
            );
        });
    }

    /**
     * Tắt trong môi trường testing: PHPUnit RefreshDatabase gọi `migrate:fresh`
     * lại nhiều lần mỗi test, tự động Artisan::call('wms:cache:clear') mỗi lần sẽ
     * chỉ tốn thời gian chạy suite mà không phản ánh tình huống thật (dev DB bị
     * seed lại ngoài luồng HTTP). Logic khớp-lệnh thật sự nằm ở CacheAutoInvalidator
     * (unit test riêng, không phụ thuộc guard môi trường này).
     */
    private function registerCacheAutoInvalidation(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        Event::listen(CommandFinished::class, CacheAutoInvalidator::handle(...));
    }
}
