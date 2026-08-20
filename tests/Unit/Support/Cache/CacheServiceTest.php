<?php

namespace Tests\Unit\Support\Cache;

use App\Support\Cache\CacheService;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_remember_calls_resolver_on_miss_and_caches_result(): void
    {
        $service = CacheService::make();
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return 'built-value';
        };

        $first = $service->remember('test:remember:key', 60, $resolver);
        $second = $service->remember('test:remember:key', 60, $resolver);

        $this->assertSame('built-value', $first);
        $this->assertSame('built-value', $second);
        // Resolver chỉ chạy 1 lần — lần 2 phải là cache hit, không rebuild.
        $this->assertSame(1, $calls);
    }

    public function test_remember_treats_cached_falsy_value_as_hit_not_miss(): void
    {
        // false/0/''/[] là falsy nhưng KHÔNG phải null — Illuminate\Cache\Repository
        // chỉ coi riêng `null` là "không có giá trị". Sentinel MISS phải phân biệt
        // đúng các giá trị falsy hợp lệ này với cache miss thật sự.
        $service = CacheService::make();
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return false;
        };

        $first = $service->remember('test:remember:false-key', 60, $resolver);
        $second = $service->remember('test:remember:false-key', 60, $resolver);

        $this->assertFalse($first);
        $this->assertFalse($second);
        $this->assertSame(1, $calls);
    }

    public function test_remember_with_null_resolver_result_always_rebuilds(): void
    {
        // Giới hạn đã biết của Laravel (Illuminate\Cache\Repository::get() coi null
        // đọc từ store là "miss"): cache một resolver trả về null sẽ luôn rebuild lại
        // ở lần gọi sau — an toàn (không sai dữ liệu), chỉ không tối ưu được.
        $service = CacheService::make();
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return null;
        };

        $first = $service->remember('test:remember:null-key', 60, $resolver);
        $second = $service->remember('test:remember:null-key', 60, $resolver);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(2, $calls);
    }

    public function test_forget_makes_next_remember_call_rebuild(): void
    {
        $service = CacheService::make();
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return "value-{$calls}";
        };

        $first = $service->remember('test:forget:key', 60, $resolver);
        $service->forget('test:forget:key');
        $second = $service->remember('test:forget:key', 60, $resolver);

        $this->assertSame('value-1', $first);
        $this->assertSame('value-2', $second);
    }

    public function test_remember_stampede_only_builds_once_and_serves_cached_value(): void
    {
        $service = CacheService::make();
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return 'stampede-value';
        };

        $first = $service->rememberStampede('test:stampede:key', 60, $resolver);
        $second = $service->rememberStampede('test:stampede:key', 60, $resolver);

        $this->assertSame('stampede-value', $first);
        $this->assertSame('stampede-value', $second);
        $this->assertSame(1, $calls);
    }

    public function test_fail_open_falls_back_to_resolver_when_cache_store_throws(): void
    {
        Log::spy();

        $throwingStore = new class implements Store
        {
            public function get($key)
            {
                throw new \RuntimeException('Valkey unreachable (simulated)');
            }

            public function many(array $keys)
            {
                return [];
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('Valkey unreachable (simulated)');
            }

            public function putMany(array $values, $seconds)
            {
                return false;
            }

            public function increment($key, $value = 1)
            {
                return false;
            }

            public function decrement($key, $value = 1)
            {
                return false;
            }

            public function forever($key, $value)
            {
                return false;
            }

            public function touch($key, $seconds)
            {
                return true;
            }

            public function forget($key)
            {
                return true;
            }

            public function flush()
            {
                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        };

        // CacheManager là singleton — extend() ngay trên instance đang sống rồi đổi
        // cache.default để lần gọi Cache:: kế tiếp route vào store giả lập lỗi này,
        // mô phỏng Valkey unreachable mà không cần Valkey thật (mục 18).
        config(['cache.stores.failing-valkey-simulation' => ['driver' => 'failing-valkey-simulation']]);
        app('cache')->extend('failing-valkey-simulation', fn () => new Repository($throwingStore));
        config(['cache.default' => 'failing-valkey-simulation']);

        $service = new CacheService(failOpen: true);
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return 'db-fallback-value';
        };

        $result = $service->remember('test:fail-open:key', 60, $resolver);

        $this->assertSame('db-fallback-value', $result);
        $this->assertSame(1, $calls);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'cache.unavailable')->atLeast()->once();
    }

    public function test_remember_self_heals_when_cached_payload_is_a_corrupted_object(): void
    {
        // Mô phỏng đúng sự cố thực tế: `config('cache.serializable_classes') = false`
        // (config/cache.php) khiến Laravel âm thầm trả `__PHP_Incomplete_Class` khi
        // unserialize() gặp 1 object bị chặn (vd. Eloquent Model từng bị cache nhầm
        // trực tiếp) — dựng chính object hỏng này bằng cách unserialize() 1 class ẩn
        // danh với allowed_classes=false, rồi ghi thẳng vào store (bỏ qua vòng
        // serialize thật của driver 'array' để giả lập payload đã hỏng sẵn).
        Log::spy();

        // PHP không cho serialize() class ẩn danh ("Serialization of 'class@anonymous'
        // is not allowed") nên dùng CacheServiceCorruptionProbe (khai báo cuối file) —
        // chỉ cần 1 class có tên thật, không liên quan gì đến domain nghiệp vụ.
        $corrupted = unserialize(serialize(new CacheServiceCorruptionProbe), ['allowed_classes' => false]);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $corrupted, 'Tiền đề của test: phải dựng được object hỏng thật sự.');

        Cache::put('test:corrupted:key', ['items' => [$corrupted], 'total' => 1], 60);

        $service = CacheService::make();
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return ['items' => ['clean'], 'total' => 1];
        };

        $result = $service->remember('test:corrupted:key', 60, $resolver);

        $this->assertSame(['items' => ['clean'], 'total' => 1], $result, 'Payload hỏng phải bị coi là miss và rebuild từ resolver, không được trả thẳng object hỏng.');
        $this->assertSame(1, $calls);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'cache.corrupted')->atLeast()->once();

        // Key hỏng phải được dọn/ghi đè bằng dữ liệu sạch — request kế tiếp không còn hỏng nữa.
        $this->assertSame(['items' => ['clean'], 'total' => 1], Cache::get('test:corrupted:key'));
    }

    public function test_bump_version_is_reflected_in_current_version(): void
    {
        $service = CacheService::make();

        $before = $service->currentVersion('test:version:namespace');
        $after = $service->bumpVersion('test:version:namespace');

        $this->assertSame($before + 1, $after);
        $this->assertSame($after, $service->currentVersion('test:version:namespace'));
    }
}

/** Class có tên thật dùng riêng để dựng __PHP_Incomplete_Class trong test ở trên (serialize() từ chối class ẩn danh). */
class CacheServiceCorruptionProbe
{
    public $foo = 'bar';
}
