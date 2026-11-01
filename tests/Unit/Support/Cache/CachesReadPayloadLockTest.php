<?php

namespace Tests\Unit\Support\Cache;

use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\Concerns\CachesReadPayload;
use Mockery;
use Tests\TestCase;

class CachesReadPayloadLockTest extends TestCase
{
    public function test_actor_scoped_read_skips_stampede_lock_and_keeps_actor_in_key(): void
    {
        $cache = Mockery::mock(CacheService::class);
        $cache->shouldReceive('currentVersion')->once()->with('stock:version')->andReturn(7);
        $cache->shouldNotReceive('rememberStampede');
        $cache->shouldReceive('remember')->once()->andReturnUsing(function ($key, $ttl, $resolver) {
            $this->assertSame(CacheKey::of('probe', [
                'f' => CacheKey::hashFilters(['page' => 1]),
                'scope' => 'global',
                'actor' => 'user-42',
            ], 7), $key);
            $this->assertGreaterThan(0, $ttl);

            return $resolver();
        });
        $this->app->instance(CacheService::class, $cache);

        $this->assertSame(['value' => 42], $this->reader()->read('user-42'));
    }

    public function test_shared_read_retains_stampede_lock(): void
    {
        $cache = Mockery::mock(CacheService::class);
        $cache->shouldReceive('currentVersion')->once()->with('stock:version')->andReturn(7);
        $cache->shouldNotReceive('remember');
        $cache->shouldReceive('rememberStampede')->once()->andReturnUsing(function ($key, $ttl, $resolver) {
            $this->assertSame(CacheKey::of('probe', [
                'f' => CacheKey::hashFilters(['page' => 1]),
                'scope' => 'global',
            ], 7), $key);
            $this->assertGreaterThan(0, $ttl);

            return $resolver();
        });
        $this->app->instance(CacheService::class, $cache);

        $this->assertSame(['value' => 42], $this->reader()->read());
    }

    private function reader(): object
    {
        return new class
        {
            use CachesReadPayload;

            protected function readCacheScopeIds(): ?array
            {
                return null;
            }

            public function read(?string $actorId = null): array
            {
                return $this->cachedReadPayload('probe', ['page' => 1], fn (): array => ['value' => 42], actorId: $actorId);
            }
        };
    }
}
