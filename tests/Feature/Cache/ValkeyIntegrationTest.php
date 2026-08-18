<?php

namespace Tests\Feature\Cache;

use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Mục 30: test với Valkey THẬT, không chỉ fake Cache::store('array'). Bộ test mặc
 * định (phpunit.xml) ép CACHE_STORE=array nên lớp này tự chuyển sang store 'redis'
 * và tự PING trước — nếu không có Valkey thật đang chạy (vd. chưa `docker compose up
 * valkey`), test tự skip với thông báo rõ ràng thay vì fail giả.
 *
 * Chạy: docker compose up -d valkey (hoặc toàn bộ stack), rồi
 *   REDIS_HOST=127.0.0.1 php artisan test --filter=ValkeyIntegrationTest
 * (REDIS_HOST mặc định .env.example là 127.0.0.1:6379, khớp cổng expose của service
 * valkey trong docker-compose.yml).
 */
class ValkeyIntegrationTest extends TestCase
{
    private bool $valkeyReachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'redis']);
        app()->forgetInstance('cache');

        try {
            $pong = Redis::connection('cache')->ping();
            $this->valkeyReachable = true;
            // Predis trả về Status object hoặc string tuỳ version — chỉ cần không throw.
            unset($pong);
        } catch (\Throwable $e) {
            $this->valkeyReachable = false;
        }

        if (! $this->valkeyReachable) {
            $this->markTestSkipped(
                'Valkey thật không kết nối được (REDIS_HOST='.config('database.redis.default.host').':'
                .config('database.redis.default.port').'). Chạy `docker compose up -d valkey` trước khi test lớp này.'
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->valkeyReachable) {
            // Chỉ xoá đúng key test tạo ra — không FLUSHDB/FLUSHALL (mục 27/38).
            Redis::connection('cache')->del($this->probeRedisKey());
        }
        parent::tearDown();
    }

    private function probeKey(): string
    {
        return CacheKey::of('test:valkey-integration', ['run' => 'probe']);
    }

    /**
     * Key dùng để đọc thẳng qua Redis::connection('cache') cho mục đích verify.
     * CHỈ cộng thêm `cache.prefix` (Illuminate\Cache\RedisStore tự cộng prefix này ở
     * tầng logic trước khi gọi xuống connection) — KHÔNG cộng thêm
     * `database.redis.options.prefix` thủ công vì predis/phpredis đã tự động áp
     * prefix đó cho MỌI lệnh trên connection này rồi (cộng 2 lần sẽ đọc sai key).
     */
    private function probeRedisKey(): string
    {
        return (string) config('cache.prefix').$this->probeKey();
    }

    public function test_ping_returns_pong(): void
    {
        $pong = Redis::connection('cache')->ping();

        // Predis: Predis\Response\Status ("PONG"); phpredis: chuỗi hoặc true tuỳ version.
        $this->assertTrue(
            (string) $pong === 'PONG' || $pong === true || $pong === '+PONG',
            'PING phải trả PONG.'
        );
    }

    public function test_cache_service_remember_writes_a_real_key_into_valkey(): void
    {
        $service = new CacheService(failOpen: false);
        $key = $this->probeKey();

        $value = $service->remember($key, 30, fn () => ['probe' => true, 'ts' => 'fixed-for-test']);

        $this->assertSame(['probe' => true, 'ts' => 'fixed-for-test'], $value);

        // Xác nhận key THẬT sự tồn tại trong Valkey (không chỉ qua lại Cache facade).
        $raw = Redis::connection('cache')->get($this->probeRedisKey());
        $this->assertNotNull($raw, 'Key phải thực sự được ghi vào Valkey, không chỉ nằm trong Cache facade abstraction.');

        $ttl = Redis::connection('cache')->ttl($this->probeRedisKey());
        $this->assertGreaterThan(0, $ttl, 'Key phải có TTL dương (không phải persist vĩnh viễn).');
        $this->assertLessThanOrEqual(30, $ttl);
    }

    public function test_forget_actually_removes_the_key_from_valkey(): void
    {
        $service = new CacheService(failOpen: false);
        $key = $this->probeKey();

        $service->remember($key, 30, fn () => 'to-be-forgotten');
        $this->assertNotNull(Redis::connection('cache')->get($this->probeRedisKey()));

        $service->forget($key);

        $this->assertNull(Redis::connection('cache')->get($this->probeRedisKey()), 'forget() phải xoá đúng key khỏi Valkey.');
    }

    public function test_bump_version_increments_atomically_in_valkey(): void
    {
        $service = new CacheService(failOpen: false);
        $namespace = 'test:valkey-integration:version';

        $v1 = $service->bumpVersion($namespace);
        $v2 = $service->bumpVersion($namespace);

        $this->assertSame($v1 + 1, $v2);

        Redis::connection('cache')->del(
            (string) config('cache.prefix').CacheKey::of('cache-version', [$namespace])
        );
    }
}
