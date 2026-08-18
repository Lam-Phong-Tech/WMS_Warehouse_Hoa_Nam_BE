<?php

namespace Tests\Unit\Support\Cache;

use App\Support\Cache\CacheKey;
use Tests\TestCase;

class CacheKeyTest extends TestCase
{
    public function test_of_includes_env_and_namespace_prefix(): void
    {
        $key = CacheKey::of('warehouse.sku:detail', ['id' => 'abc']);

        $this->assertStringStartsWith('wms:'.app()->environment().':warehouse.sku:detail:', $key);
        $this->assertStringContainsString('id-abc', $key);
    }

    public function test_of_appends_version_segment(): void
    {
        $key = CacheKey::of('warehouse.sku:list', [], 7);

        $this->assertStringEndsWith(':v7', $key);
    }

    public function test_hash_filters_is_order_independent(): void
    {
        // ?a=1&b=2 và ?b=2&a=1 phải cho cùng 1 key khi ngữ nghĩa giống nhau (mục 9).
        $hashA = CacheKey::hashFilters(['a' => '1', 'b' => '2']);
        $hashB = CacheKey::hashFilters(['b' => '2', 'a' => '1']);

        $this->assertSame($hashA, $hashB);
    }

    public function test_hash_filters_trims_string_values(): void
    {
        $hashA = CacheKey::hashFilters(['keyword' => 'abc']);
        $hashB = CacheKey::hashFilters(['keyword' => '  abc  ']);

        $this->assertSame($hashA, $hashB);
    }

    public function test_hash_filters_differs_for_different_values(): void
    {
        $hashA = CacheKey::hashFilters(['status' => 'ACTIVE']);
        $hashB = CacheKey::hashFilters(['status' => 'INACTIVE']);

        $this->assertNotSame($hashA, $hashB);
    }

    public function test_hash_filters_is_recursive(): void
    {
        $hashA = CacheKey::hashFilters(['nested' => ['x' => 1, 'y' => 2]]);
        $hashB = CacheKey::hashFilters(['nested' => ['y' => 2, 'x' => 1]]);

        $this->assertSame($hashA, $hashB);
    }

    public function test_scope_signature_null_means_all(): void
    {
        $this->assertSame('all', CacheKey::scopeSignature(null));
    }

    public function test_scope_signature_empty_array_means_none(): void
    {
        $this->assertSame('none', CacheKey::scopeSignature([]));
    }

    public function test_scope_signature_is_order_independent_and_distinct_per_set(): void
    {
        $sigA = CacheKey::scopeSignature(['wh-1', 'wh-2']);
        $sigB = CacheKey::scopeSignature(['wh-2', 'wh-1']);
        $sigC = CacheKey::scopeSignature(['wh-3']);

        $this->assertSame($sigA, $sigB);
        $this->assertNotSame($sigA, $sigC);
        $this->assertNotSame('all', $sigA);
        $this->assertNotSame('none', $sigA);
    }

    public function test_normalize_part_strips_colon_and_space(): void
    {
        $this->assertSame('a_b_c', CacheKey::normalizePart('a:b c'));
    }
}
