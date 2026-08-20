<?php

namespace App\Support\Cache;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Log;

/**
 * Cổng vào DUY NHẤT cho application cache (mục 4) — không service/controller nào
 * được gọi thẳng `Cache::` cho dữ liệu nghiệp vụ. Đảm bảo thống nhất:
 *  - fail-open khi Valkey lỗi/không kết nối được (mục 18): log rõ, không nuốt lỗi
 *    im lặng, request vẫn chạy tiếp bằng cách gọi thẳng resolver (DB).
 *  - stampede protection cho hot key bằng Cache::lock() (mục 15/16) — lock chỉ để
 *    tránh nhiều request cùng rebuild 1 key, KHÔNG thay thế transaction/lock nghiệp vụ.
 *  - observability tối thiểu: log hit/miss/rebuild/error/invalidate/lock-timeout,
 *    không log payload nhạy cảm (chỉ log cache key + phase).
 *
 * Không áp dụng cho session/queue — hai driver đó có semantics khác, không đi qua
 * lớp này.
 */
class CacheService
{
    /**
     * Sentinel để phát hiện cache miss thật sự vs. bất kỳ giá trị falsy nào đã cache
     * (false/0/''/[]). LƯU Ý: `Illuminate\Cache\Repository::get()` tự coi giá trị
     * `null` đọc từ store là "không có" và trả về $default — đây là giới hạn của
     * Laravel, sentinel này không khắc phục được cho riêng trường hợp null. Hệ quả an
     * toàn: cache một resolver trả về null (vd. find() với id không tồn tại) sẽ không
     * bao giờ thực sự "hit" — mỗi lần gọi đều rebuild lại, không sai dữ liệu, chỉ mất
     * cơ hội tối ưu cho trường hợp hiếm này.
     */
    private const MISS = "\0__wms_cache_miss__\0";

    public function __construct(private readonly bool $failOpen = true) {}

    public static function make(): self
    {
        return new self((bool) config('wms_cache.fail_open', true));
    }

    /**
     * Cache đơn giản, không lock — dùng cho key có mức truy cập đồng thời thấp
     * (vd: theo user/id riêng lẻ, RBAC).
     */
    public function remember(string $key, int $ttlSeconds, \Closure $resolver): mixed
    {
        $cached = $this->safeGet($key, $resolver, $hadError);
        if ($hadError) {
            return $cached;
        }
        if ($cached !== self::MISS) {
            Log::debug('cache.hit', ['key' => $key]);

            return $cached;
        }

        Log::info('cache.miss', ['key' => $key]);
        $fresh = $resolver();
        $this->put($key, $fresh, $ttlSeconds);

        return $fresh;
    }

    /**
     * Cache có stampede protection — dùng cho "hot key" dùng chung nhiều request
     * (master-data list, report/dashboard aggregate). Khi miss, chỉ 1 request được
     * chạy resolver; các request khác chờ (có timeout) rồi đọc lại cache vừa build.
     */
    public function rememberStampede(
        string $key,
        int $ttlSeconds,
        \Closure $resolver,
        ?int $lockWaitSeconds = null,
        ?int $lockTtlSeconds = null,
    ): mixed {
        $cached = $this->safeGet($key, $resolver, $hadError);
        if ($hadError) {
            return $cached;
        }
        if ($cached !== self::MISS) {
            Log::debug('cache.hit', ['key' => $key]);

            return $cached;
        }

        Log::info('cache.miss', ['key' => $key]);

        $lockWaitSeconds ??= (int) config('wms_cache.lock.wait_seconds', 5);
        $lockTtlSeconds ??= (int) config('wms_cache.lock.ttl_seconds', 10);

        try {
            $lock = CacheFacade::lock('lock:'.$key, $lockTtlSeconds);

            return $lock->block($lockWaitSeconds, function () use ($key, $ttlSeconds, $resolver) {
                // Request khác có thể đã build xong trong lúc mình chờ lock.
                $reCheck = $this->safeGet($key, $resolver, $hadError);
                if (! $hadError && $reCheck !== self::MISS) {
                    Log::debug('cache.hit', ['key' => $key, 'after_lock_wait' => true]);

                    return $reCheck;
                }

                $fresh = $resolver();
                $this->put($key, $fresh, $ttlSeconds);

                return $fresh;
            });
        } catch (LockTimeoutException) {
            // Không được để request chờ vô hạn vì tranh chấp lock — chấp nhận rebuild
            // trùng lặp hiếm khi timeout, còn hơn làm sập cả request.
            Log::warning('cache.lock.timeout', ['key' => $key, 'lock_wait_seconds' => $lockWaitSeconds]);

            return $resolver();
        } catch (\Throwable $e) {
            return $this->handleFailure($key, 'lock', $e, $resolver);
        }
    }

    public function forget(string $key): void
    {
        try {
            CacheFacade::forget($key);
            Log::info('cache.invalidate', ['key' => $key]);
        } catch (\Throwable $e) {
            Log::warning('cache.invalidate_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<int, string>  $keys */
    public function forgetMany(array $keys): void
    {
        foreach ($keys as $key) {
            $this->forget($key);
        }
    }

    public function bumpVersion(string $namespace): int
    {
        return CacheVersion::bump($namespace);
    }

    public function currentVersion(string $namespace): int
    {
        return CacheVersion::current($namespace);
    }

    private function safeGet(string $key, \Closure $resolver, ?bool &$hadError): mixed
    {
        $hadError = false;
        try {
            $value = CacheFacade::get($key, self::MISS);
        } catch (\Throwable $e) {
            $hadError = true;

            return $this->handleFailure($key, 'read', $e, $resolver);
        }

        // Phòng thủ ở cổng vào DUY NHẤT của cache: nếu payload đọc lên bị hỏng (xem
        // isCorrupted()), KHÔNG được trả thẳng ra caller — coi như miss để tự rebuild
        // từ resolver (DB) và tự dọn key hỏng, thay vì để dữ liệu hỏng lọt ra response
        // API trong suốt phần đời còn lại của TTL.
        if ($value !== self::MISS && self::isCorrupted($value)) {
            Log::warning('cache.corrupted', ['key' => $key]);
            $this->forget($key);

            return self::MISS;
        }

        return $value;
    }

    /**
     * Phát hiện payload hỏng do `config('cache.serializable_classes') = false` (mục
     * an ninh chống gadget-chain unserialize, xem config/cache.php) chặn một PHP
     * object (thường là Eloquent Model bị cache nhầm thay vì mảng thuần — dù đường
     * ghi hiện tại của BaseRepository/AuthCache đã tránh việc này, đây vẫn là lưới an
     * toàn cuối cùng cho MỌI domain cache). Laravel không ném lỗi ở bước đọc, chỉ âm
     * thầm trả về `__PHP_Incomplete_Class` rỗng — nếu không tự kiểm tra ở đây, payload
     * hỏng sẽ lọt thẳng ra response API. Quét đệ quy vì giá trị cache thường là mảng
     * lồng nhau (vd. ['items' => [...], 'total' => N]).
     */
    private static function isCorrupted(mixed $value): bool
    {
        if (is_object($value)) {
            return $value instanceof \__PHP_Incomplete_Class;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::isCorrupted($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function put(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            CacheFacade::put($key, $value, $ttlSeconds);
            Log::debug('cache.rebuild', ['key' => $key, 'ttl_seconds' => $ttlSeconds]);
        } catch (\Throwable $e) {
            // Valkey lỗi lúc ghi: response vẫn trả dữ liệu đúng (đã có $value), chỉ log.
            Log::warning('cache.write_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Valkey lỗi/không kết nối được. `fail_open=true` (mặc định, mục 18): log rõ và
     * gọi thẳng resolver để API vẫn hoạt động từ PostgreSQL. `fail_open=false`: ném
     * lại lỗi — dành cho trường hợp muốn coi cache-store outage là sự cố cứng.
     */
    private function handleFailure(string $key, string $phase, \Throwable $e, \Closure $resolver): mixed
    {
        Log::warning('cache.unavailable', [
            'key' => $key,
            'phase' => $phase,
            'error' => $e->getMessage(),
        ]);

        if (! $this->failOpen) {
            throw $e;
        }

        return $resolver();
    }
}
