<?php

namespace App\Console\Commands;

use App\Support\Cache\CacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan wms:runtime-profile` — soi xem CONTAINER ĐANG CHẠY có thật sự đang
 * ở hồ sơ chịu tải hay không.
 *
 * VẤN ĐỀ NÓ GIẢI: `docker-compose.perf.yml` là overlay opt-in. Gõ thiếu một cờ
 * `-f` là cả stack chạy bằng cấu hình dev — APP_DEBUG bật, opcache kiểm tra
 * timestamp từng request, predis thay phpredis, pool php-fpm mặc định — mà KHÔNG
 * có dấu hiệu nào nhìn thấy được. Hệ thống vẫn "chạy", chỉ là chậm hơn nhiều lần,
 * và không ai biết cho tới khi tải thật ập vào.
 *
 * Lệnh này biến thứ vô hình đó thành một bảng PASS/FAIL và một mã thoát. Chạy nó
 * sau mỗi lần deploy (hoặc trong smoke test của pipeline) thì không thể "vô tình
 * bỏ quên overlay" nữa.
 *
 * KHÔNG tự sửa gì cả — chỉ báo cáo. Việc quyết định vẫn thuộc về người vận hành.
 */
class RuntimeProfileCheck extends Command
{
    protected $signature = 'wms:runtime-profile
        {--expect=perf : perf|dev — bộ kỳ vọng dùng để chấm PASS/FAIL}';

    protected $description = 'Kiểm tra runtime đang chạy có đúng hồ sơ chịu tải không (APP_DEBUG, opcache, redis client, pool, DB connections)';

    public function handle(CacheService $cache): int
    {
        $expectPerf = $this->option('expect') !== 'dev';
        $rows = [];
        $failures = 0;

        $check = function (string $name, bool $ok, string $actual, string $want) use (&$rows, &$failures, $expectPerf): void {
            $pass = $ok || ! $expectPerf;
            if (! $pass) {
                $failures++;
            }
            $rows[] = [$pass ? 'PASS' : 'FAIL', $name, $actual, $want];
        };

        // 1. APP_DEBUG bật là khoản phí đắt nhất mỗi request: Laravel thu thập toàn
        //    bộ query + stack trace kể cả cho request thành công.
        $check('APP_DEBUG', config('app.debug') === false, var_export(config('app.debug'), true), 'false');

        // 2. Cache cấu hình/route/event. Thiếu là mỗi request phải nạp và hợp nhất
        //    lại toàn bộ file config.
        $check('config đã cache', app()->configurationIsCached(), app()->configurationIsCached() ? 'yes' : 'no', 'yes');
        $check('route đã cache', app()->routesAreCached(), app()->routesAreCached() ? 'yes' : 'no', 'yes');

        // 3. opcache: validate_timestamps=1 khiến PHP stat từng file ở MỌI request.
        $opcache = function_exists('opcache_get_configuration') ? opcache_get_configuration() : null;
        $opcacheOn = (bool) ($opcache['directives']['opcache.enable'] ?? false);
        $validateTs = $opcache['directives']['opcache.validate_timestamps'] ?? true;
        $check('opcache bật', $opcacheOn, $opcacheOn ? 'on' : 'off', 'on');
        $check('opcache.validate_timestamps', $validateTs === false || $validateTs === 0, var_export($validateTs, true), 'false');

        // 4. predis là client thuần PHP; phpredis là extension C. Ở vài trăm
        //    request/giây, khác biệt này đo được.
        $redisClient = (string) config('database.redis.client');
        $check('redis client', $redisClient === 'phpredis', $redisClient, 'phpredis');

        // 5. Driver hạ tầng. Ba cái này phải là redis thì mới scale ngang được:
        //    session dùng chung -> không cần sticky; queue dùng chung -> worker ở
        //    node nào cũng nhận được job.
        $check('CACHE_STORE', config('cache.default') === 'redis', (string) config('cache.default'), 'redis');
        $check('SESSION_DRIVER', config('session.driver') === 'redis', (string) config('session.driver'), 'redis');
        $check('QUEUE_CONNECTION', config('queue.default') === 'redis', (string) config('queue.default'), 'redis');

        // 6. Cache và queue/session phải nằm ở HAI logical DB khác nhau: khi cần
        //    dọn cache thì không được đụng tới hàng đợi job.
        $cacheDb = (int) config('database.redis.cache.database');
        $defaultDb = (int) config('database.redis.default.database');
        $check('redis: cache tách DB với queue/session', $cacheDb !== $defaultDb, "cache=DB{$cacheDb}, default=DB{$defaultDb}", 'khác nhau');

        // 7. Cache store có thật sự gọi được không. fail-open nghĩa là ứng dụng vẫn
        //    chạy khi Valkey chết — nhưng người vận hành phải BIẾT là nó đang chết.
        $cacheAlive = $this->probeCache($cache);
        $check('cache store trả lời', $cacheAlive, $cacheAlive ? 'ok' : 'không kết nối được', 'ok');

        // 8. Trần kết nối DB phải lớn hơn tổng tiến trình PHP có thể mở kết nối,
        //    nếu không sẽ gặp "sorry, too many clients already" trả thẳng 500.
        $maxChildren = $this->phpFpmMaxChildren();
        $maxConnections = $this->postgresMaxConnections();
        if ($maxChildren !== null && $maxConnections !== null) {
            $check(
                'pg max_connections đủ cho pool php-fpm',
                $maxConnections >= $maxChildren + 12,
                "max_connections={$maxConnections}, pm.max_children={$maxChildren}",
                'max_connections >= max_children + 12 (queue/scheduler/reverb/dự phòng)'
            );
        } else {
            $rows[] = ['SKIP', 'pg max_connections vs pool', 'không đọc được', '—'];
        }

        // 9. TTL của access token. Không chấm FAIL vì đây là quyết định bảo mật của
        //    chủ hệ thống, nhưng phải hiện ra: giá trị dài bất thường thường là cấu
        //    hình tạm cho việc quét bằng máy scanner rồi quên hoàn tác.
        $jwtTtl = (int) config('jwt.ttl');
        $rows[] = [
            $jwtTtl > 240 ? 'WARN' : 'INFO',
            'JWT_TTL (phút)',
            (string) $jwtTtl,
            '<= 240 cho môi trường thật',
        ];

        $this->table(['Kết quả', 'Hạng mục', 'Đang là', 'Kỳ vọng'], $rows);

        if ($failures > 0) {
            $this->error("{$failures} hạng mục KHÔNG đạt hồ sơ chịu tải.");
            $this->line('Nhiều khả năng stack đang chạy thiếu overlay. Khởi động lại bằng:');
            $this->line('  docker compose -f docker-compose.yml -f docker-compose.override.yml \\');
            $this->line('                 -f docker-compose.perf.yml up -d');

            return self::FAILURE;
        }

        $this->info('Runtime đúng hồ sơ chịu tải.');

        return self::SUCCESS;
    }

    private function probeCache(CacheService $cache): bool
    {
        try {
            $probe = 'wms:runtime-profile:probe';
            $value = $cache->remember($probe, 5, static fn (): string => 'ok');

            return $value === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Đọc pool php-fpm mà perf-entrypoint.sh sinh ra lúc container khởi động. */
    private function phpFpmMaxChildren(): ?int
    {
        foreach (['/usr/local/etc/php-fpm.d/zz-perf.conf', '/usr/local/etc/php-fpm.d/www.conf'] as $path) {
            if (! is_readable($path)) {
                continue;
            }
            if (preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/m', (string) file_get_contents($path), $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function postgresMaxConnections(): ?int
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                return null;
            }
            $row = DB::selectOne('SHOW max_connections');

            return $row === null ? null : (int) ((array) $row)['max_connections'];
        } catch (\Throwable) {
            return null;
        }
    }
}
