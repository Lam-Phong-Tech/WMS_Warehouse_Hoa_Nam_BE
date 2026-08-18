<?php

namespace Tests\Feature\Cache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Repositories\Contracts\AgencyRepositoryInterface;
use Tests\TestCase;

/**
 * Mục 6/7/29: master-data (Agency dùng làm đại diện — cùng cơ chế opt-in cache của
 * BaseRepository áp cho DefectCatalog/ProductMaster/Product) phải cache đúng, isolate
 * theo filter/page, và invalidate ngay sau mutation.
 */
class MasterDataCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function repo(): AgencyRepositoryInterface
    {
        return app(AgencyRepositoryInterface::class);
    }

    public function test_second_paginate_call_with_same_filters_is_a_cache_hit(): void
    {
        $repo = $this->repo();
        $repo->create(['agency_code' => 'AG-CACHE-01', 'agency_name' => 'Agency Cache 01', 'status' => 'ACTIVE']);

        DB::enableQueryLog();
        $repo->paginate([], 15);
        $firstCallQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $repo->paginate([], 15);
        $secondCallQueries = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $firstCallQueries, 'Lần gọi đầu (cache miss) phải chạy query.');
        $this->assertSame(0, $secondCallQueries, 'Lần gọi thứ 2 với cùng filter/page phải là cache hit, không query DB.');
    }

    public function test_different_filters_do_not_collide_cache_keys(): void
    {
        $repo = $this->repo();
        $repo->create(['agency_code' => 'AG-ACTIVE-01', 'agency_name' => 'Active Agency', 'status' => 'ACTIVE']);
        $repo->create(['agency_code' => 'AG-INACTIVE-01', 'agency_name' => 'Inactive Agency', 'status' => 'INACTIVE']);

        $active = $repo->paginate(['status' => 'ACTIVE'], 15);
        $inactive = $repo->paginate(['status' => 'INACTIVE'], 15);

        $activeCodes = collect($active->items())->pluck('agency_code')->all();
        $inactiveCodes = collect($inactive->items())->pluck('agency_code')->all();

        $this->assertContains('AG-ACTIVE-01', $activeCodes);
        $this->assertNotContains('AG-INACTIVE-01', $activeCodes);
        $this->assertContains('AG-INACTIVE-01', $inactiveCodes);
        $this->assertNotContains('AG-ACTIVE-01', $inactiveCodes);
    }

    public function test_different_pages_do_not_collide_cache_keys(): void
    {
        $repo = $this->repo();
        for ($i = 1; $i <= 3; $i++) {
            $repo->create(['agency_code' => "AG-PAGE-{$i}", 'agency_name' => "Agency Page {$i}", 'status' => 'ACTIVE']);
        }

        request()->merge(['page' => 1]);
        $page1 = $repo->paginate([], 2);
        request()->merge(['page' => 2]);
        $page2 = $repo->paginate([], 2);

        $page1Codes = collect($page1->items())->pluck('agency_code')->all();
        $page2Codes = collect($page2->items())->pluck('agency_code')->all();

        $this->assertNotEmpty($page1Codes);
        $this->assertNotEmpty($page2Codes);
        $this->assertEmpty(array_intersect($page1Codes, $page2Codes), 'Page 1 và page 2 không được trùng dữ liệu do đụng cache key.');
    }

    public function test_create_invalidates_list_cache_immediately(): void
    {
        $repo = $this->repo();
        $before = $repo->paginate([], 15);
        $this->assertSame(0, $before->total());

        $repo->create(['agency_code' => 'AG-NEW-01', 'agency_name' => 'New Agency', 'status' => 'ACTIVE']);

        $after = $repo->paginate([], 15);
        $this->assertSame(1, $after->total(), 'List cache phải phản ánh bản ghi mới ngay lập tức, không chờ TTL.');
    }

    public function test_update_invalidates_detail_cache_immediately(): void
    {
        $repo = $this->repo();
        $agency = $repo->create(['agency_code' => 'AG-UPD-01', 'agency_name' => 'Before Update', 'status' => 'ACTIVE']);

        $cachedBefore = $repo->find($agency->agency_id);
        $this->assertSame('Before Update', $cachedBefore->agency_name);

        $repo->update($agency->agency_id, ['agency_name' => 'After Update']);

        $cachedAfter = $repo->find($agency->agency_id);
        $this->assertSame('After Update', $cachedAfter->agency_name, 'Detail cache phải phản ánh dữ liệu mới ngay sau update.');
    }

    public function test_paginator_is_rebuilt_fresh_on_every_call_not_cached_as_an_object(): void
    {
        // Bug đã sửa: cache thẳng LengthAwarePaginator object sẽ đóng băng path/query
        // của request lúc build cache — request khác dùng lại y hệt object đó sẽ ra
        // link phân trang sai. Chỉ items()+total() được cache; paginator luôn được
        // `new` lại. Với cache store 'array' (test), nếu implementation SAI (cache
        // thẳng object), 2 lần gọi sẽ trả về CÙNG 1 object reference vì array store
        // giữ nguyên object trong bộ nhớ, không serialize.
        $repo = $this->repo();
        $repo->create(['agency_code' => 'AG-LINK-01', 'agency_name' => 'Link Agency', 'status' => 'ACTIVE']);

        $first = $repo->paginate([], 15);
        $second = $repo->paginate([], 15);

        $this->assertNotSame($first, $second, 'Mỗi lần gọi paginate() phải trả về 1 LengthAwarePaginator instance mới, không phải cùng object đã cache.');
        $this->assertSame($first->total(), $second->total());
        $this->assertSame(
            collect($first->items())->pluck('agency_code')->all(),
            collect($second->items())->pluck('agency_code')->all()
        );
    }
}
