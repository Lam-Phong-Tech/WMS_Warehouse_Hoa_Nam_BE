<?php

namespace App\Http\Middleware;

use App\Support\Cache\CacheService;
use Closure;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ghi lại request CHẬM kèm phân rã thời gian — để lần sau người dùng báo "màn
 * này lag" thì trả lời được request nào, tốn ở đâu, mà không phải đoán.
 *
 * ĐO ĐƯỢC 07/09/2026 (docs/perf/LOAD_TEST_2026-09-07.md): ở 150 req/s, slowlog
 * của php-fpm chỉ cho thấy "GET /index.php?page=1 chạy 12–60 s" — không biết
 * route nào, không biết chờ SQL hay chờ lock cache. Middleware này bổ sung đúng
 * phần thiếu: route, mã trạng thái, tổng thời gian, số câu SQL + thời gian SQL,
 * hit/miss/put của cache, thời gian chờ lock stampede, bộ nhớ đỉnh, request_id.
 *
 * Nguyên tắc:
 *  - Chỉ ghi khi vượt ngưỡng `wms_perf.slow_request_ms` (mặc định 1000; 0 = tắt),
 *    ở mức WARNING để lọt qua LOG_LEVEL=warning của hồ sơ chịu tải.
 *  - KHÔNG ghi body, query string, header hay tham số — chỉ tên route/đường dẫn
 *    (đường dẫn có thể chứa id, không chứa PII).
 *  - Chi phí khi request nhanh: một listener SQL (đếm + cộng ms) và ba listener
 *    sự kiện cache — vài micro-giây mỗi câu. Không có I/O.
 *
 * Đặt NGOÀI CÙNG (prepend cuối cùng) để đo trọn cả middleware khác.
 */
class SlowRequestLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $threshold = (int) config('wms_perf.slow_request_ms', 1000);
        if ($threshold <= 0) {
            return $next($request);
        }

        $sqlCount = 0;
        $sqlMs = 0.0;
        DB::listen(function ($query) use (&$sqlCount, &$sqlMs): void {
            $sqlCount++;
            $sqlMs += (float) $query->time;
        });

        $hits = 0;
        $misses = 0;
        $puts = 0;
        Event::listen(CacheHit::class, function () use (&$hits): void {
            $hits++;
        });
        Event::listen(CacheMissed::class, function () use (&$misses): void {
            $misses++;
        });
        Event::listen(KeyWritten::class, function () use (&$puts): void {
            $puts++;
        });

        CacheService::resetLockWait();
        $started = hrtime(true);

        $response = $next($request);

        $durationMs = (hrtime(true) - $started) / 1e6;
        if ($durationMs < $threshold) {
            return $response;
        }

        $route = $request->route();
        Log::warning('slow_request', [
            'method' => $request->getMethod(),
            // Route không đặt tên thì Laravel sinh tên 'generated::…' vô nghĩa — ghi
            // mẫu URI (vd api/v1/reports/{slug}) để nhìn là biết endpoint nào.
            'route' => ($route && $route->getName() && ! str_contains($route->getName(), 'generated::'))
                ? $route->getName()
                : ($route?->uri() ?? $request->path()),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round($durationMs),
            'db_queries' => $sqlCount,
            'db_ms' => (int) round($sqlMs),
            'cache_hits' => $hits,
            'cache_misses' => $misses,
            'cache_puts' => $puts,
            'cache_lock_wait_ms' => (int) round(CacheService::lockWaitMs()),
            'peak_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
            'request_id' => is_string($request->attributes->get('request_id'))
                ? $request->attributes->get('request_id')
                : $response->headers->get('X-Request-ID'),
        ]);

        return $response;
    }
}
