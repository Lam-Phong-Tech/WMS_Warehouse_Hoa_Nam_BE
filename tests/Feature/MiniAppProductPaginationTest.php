<?php

namespace Tests\Feature;

use App\Support\Cache\CacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class MiniAppProductPaginationTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/products';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AuthDatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_canonical_sku_ids_are_distinct_and_stable_across_every_page(): void
    {
        $warehouse = $this->warehouseFixture();
        $headers = $this->superAdminHeaders();
        foreach (range(104, 1) as $number) {
            $this->sku($number, 'Same name');
        }
        $this->sku(105, 'Same name', status: 'INACTIVE');
        $expected = array_map($this->id(...), range(1, 104));

        foreach ([1, 2] as $read) {
            $all = [];
            $pageSets = [];
            $revisions = [];
            $etags = [];
            foreach ([1, 2, 3] as $page) {
                $response = $this->getJson($this->url(['page' => $page, 'per_page' => 50]), $headers)
                    ->assertOk()
                    ->assertJsonPath('meta.contract', 'mini-app-products-pagination-v2')
                    ->assertJsonPath('meta.revision', 'canonical-sku-id-r1')
                    ->assertJsonPath('meta.snapshot_consistent', false)
                    ->assertJsonPath('meta.distinct_key', 'sku_id')
                    ->assertJsonPath('meta.sort.0.field', 'sku_id')
                    ->assertJsonPath('meta.sort.0.direction', 'ASC')
                    ->assertJsonPath('meta.total', 104)
                    ->assertJsonPath('meta.last_page', 3)
                    ->assertJsonPath('meta.current_page', $page)
                    ->assertJsonPath('meta.warehouse_id', (string) $warehouse->id);
                $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                $ids = array_column($response->json('data'), 'sku_id');
                $this->assertCount($page < 3 ? 50 : 4, $ids);
                $this->assertCount(count($ids), array_unique($ids));
                $pageSets[] = $ids;
                $all = [...$all, ...$ids];
                $revisions[] = $response->json('meta.snapshot_revision');
                $etags[] = $response->headers->get('ETag');
            }

            $this->assertSame($expected, $all, "Canonical ordered catalogue, read {$read}");
            $this->assertSame(104, count(array_unique($all)));
            $this->assertSame([], array_values(array_intersect($pageSets[0], $pageSets[1])));
            $this->assertSame([], array_values(array_intersect($pageSets[1], $pageSets[2])));
            $this->assertCount(1, array_unique($revisions));
            $this->assertCount(3, array_unique($etags));
        }

        $this->getJson($this->url(['page' => 4, 'per_page' => 50]), $headers)
            ->assertOk()->assertJsonPath('meta.total', 104)->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('stock_documents', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_snapshot_revision_and_etag_change_after_catalog_cache_version_changes(): void
    {
        $this->warehouseFixture();
        $headers = $this->superAdminHeaders();
        $this->sku(1, 'Alpha');
        $url = $this->url(['page' => 1, 'per_page' => 50]);
        $first = $this->getJson($url, $headers)->assertOk();

        app(CacheService::class)->bumpVersion('stock:version');
        $second = $this->getJson($url, $headers)->assertOk();

        $this->assertNotSame($first->json('meta.snapshot_revision'), $second->json('meta.snapshot_revision'));
        $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, $second->json('meta.total'));
    }

    public function test_type_status_keyword_and_page_filters_keep_canonical_field_semantics(): void
    {
        $this->warehouseFixture();
        $headers = $this->superAdminHeaders();
        $this->sku(4, 'Same name');
        $this->sku(2, 'Same name');
        $this->sku(3, 'Same name', type: 'COMPONENT');
        $this->sku(1, 'Same name', status: 'INACTIVE');
        $brandId = $this->id(201);
        $categoryId = $this->id(202);
        DB::table('brands')->insert([
            'id' => $brandId, 'brand_code' => 'PAGINATION-BRAND', 'brand_name' => 'Pagination Brand',
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_categories')->insert([
            'id' => $categoryId, 'category_code' => 'PAGINATION-CATEGORY', 'category_name' => 'Pagination Category',
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                ['master_id' => $brandId, 'master_type' => 'BRAND', 'code' => 'PAGINATION-BRAND', 'name' => 'Pagination Brand', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['master_id' => $categoryId, 'master_type' => 'CATEGORY', 'code' => 'PAGINATION-CATEGORY', 'name' => 'Pagination Category', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
        Sku::query()->whereKey($this->id(2))->update(['brand_id' => $brandId, 'category_id' => $categoryId]);

        foreach ([1 => 2, 2 => 4] as $page => $number) {
            $response = $this->getJson($this->url(['page' => $page, 'per_page' => 1, 'sku_type' => 'PRODUCT']), $headers)
                ->assertOk()->assertJsonPath('meta.total', 2)
                ->assertJsonPath('meta.filters.sku_type', 'PRODUCT')
                ->assertJsonPath('data.0.sku_id', $this->id($number))
                ->assertJsonPath('data.0.sku_code', sprintf('PAGINATION-%03d', $number))
                ->assertJsonPath('data.0.sku_type', 'PRODUCT')
                ->assertJsonPath('data.0.status', 'ACTIVE');
            if ($number === 2) {
                $response->assertJsonPath('data.0.brand_name', 'Pagination Brand')
                    ->assertJsonPath('data.0.category_name', 'Pagination Category');
            }
        }
        $this->getJson($this->url(['status' => 'INACTIVE', 'sku_type' => 'PRODUCT']), $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku_id', $this->id(1));
        $this->getJson($this->url(['sku_type' => 'COMPONENT']), $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku_id', $this->id(3));
        $this->getJson($this->url(['keyword' => 'pagination-004']), $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku_id', $this->id(4));
    }

    public function test_authentication_page_limit_and_missing_canonical_warehouse_contract_are_unchanged(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $warehouse = $this->warehouseFixture();
        $headers = $this->superAdminHeaders();
        $this->getJson($this->url(['per_page' => 1000]), $headers)->assertUnprocessable();
        $warehouse->update(['status' => 'INACTIVE']);
        $this->getJson(self::BASE, $headers)->assertNotFound()->assertJsonPath('error.code', 'WAREHOUSE_NOT_FOUND');
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }

    private function sku(int $number, string $name, string $type = 'PRODUCT', string $status = 'ACTIVE'): void
    {
        Sku::forceCreate([
            'sku_id' => $this->id($number),
            'sku_code' => sprintf('PAGINATION-%03d', $number),
            'sku_name' => $name,
            'sku_type' => $type,
            'status' => $status,
            'unit' => 'cai',
            'is_serial_tracked' => true,
        ]);
    }

    /** @param array<string,mixed> $filters */
    private function url(array $filters): string
    {
        return self::BASE.'?'.http_build_query($filters);
    }
}
