<?php

namespace Tests\Feature;

use App\Support\SqliteUnaccentFunction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Public projection của family/variant (Hand Tools DongCheng, P0). Không lặp
 * lại các test hiển thị/lộ dữ liệu chung đã có ở PublicStorefrontApiTest —
 * bộ này chỉ khoá riêng phần mới: `family` context + `variants` thật trên
 * GET /api/v1/public/products/{slug}, và power_source=null không bị biến
 * thành "N/A"/chuỗi rỗng.
 */
class HandToolsFamilyVariantPublicTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();
        SqliteUnaccentFunction::forget();

        config()->set('public_api.domains', [
            'POWER_TOOLS' => ['name' => 'Máy và thiết bị động lực', 'categories' => []],
            'HAND_TOOLS' => ['name' => 'Dụng cụ cầm tay', 'categories' => ['CLAMPING_TOOLS']],
            'ACCESSORIES' => ['name' => 'Phụ tùng và phụ kiện', 'categories' => []],
        ]);
    }

    public function test_detail_returns_family_context_and_only_visible_variants(): void
    {
        $warehouse = $this->warehouseFixture();
        $brandId = $this->brand('DONGCHENG', 'DongCheng');
        $categoryId = $this->category('CLAMPING_TOOLS', 'Dụng cụ kẹp giữ');

        $six = $this->handToolSku('DC-FAM-6IN', 'Kìm điện đa năng - 6 inch', $brandId, $categoryId, 'Kìm điện đa năng', '6 inch');
        $seven = $this->handToolSku('DC-FAM-7IN', 'Kìm điện đa năng - 7 inch', $brandId, $categoryId, 'Kìm điện đa năng', '7 inch');
        $eight = $this->handToolSku('DC-FAM-8IN', 'Kìm điện đa năng - 8 inch', $brandId, $categoryId, 'Kìm điện đa năng', '8 inch');

        $this->publish($six);
        $this->publish($seven);
        $this->publish($eight);
        $this->seedAvailableBalance($warehouse->id, $six->sku_id, 5);
        $this->seedAvailableBalance($warehouse->id, $seven->sku_id, 5);
        // Published variants remain visible when out of stock, for preorder.
        $this->seedAvailableBalance($warehouse->id, $eight->sku_id, 0);

        $body = $this->getJson(self::BASE.'/products/dc-fam-6in')->assertOk()->json('data');

        $this->assertSame('Kìm điện đa năng', $body['family']['name']);
        $this->assertSame(3, $body['family']['variant_count']);

        $variantCodes = collect($body['variants'])->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['DC-FAM-6IN', 'DC-FAM-7IN', 'DC-FAM-8IN'], $variantCodes);
        $this->getJson(self::BASE.'/products/dc-fam-8in')->assertOk()
            ->assertJsonPath('data.availability', 'PREORDER');

        $default = collect($body['variants'])->firstWhere('code', 'DC-FAM-6IN');
        $this->assertTrue($default['is_default']);
        $this->assertSame('6 inch', $default['variant_label']);

        $other = collect($body['variants'])->firstWhere('code', 'DC-FAM-7IN');
        $this->assertFalse($other['is_default']);
        $this->assertSame('7 inch', $other['variant_label']);
    }

    public function test_single_product_without_family_keeps_the_stub_default_variant(): void
    {
        $warehouse = $this->warehouseFixture();
        $categoryId = $this->category('CLAMPING_TOOLS', 'Dụng cụ kẹp giữ');

        $sku = $this->handToolSku('DC-SOLO-001', 'Kìm mũi dài', null, $categoryId, null, null);
        $this->publish($sku);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 3);

        $body = $this->getJson(self::BASE.'/products/dc-solo-001')->assertOk()->json('data');

        $this->assertNull($body['family']);
        $this->assertCount(1, $body['variants']);
        $this->assertTrue($body['variants'][0]['is_default']);
        $this->assertSame('DC-SOLO-001', $body['variants'][0]['code']);
    }

    public function test_null_power_source_renders_as_null_not_a_placeholder(): void
    {
        $warehouse = $this->warehouseFixture();
        $categoryId = $this->category('CLAMPING_TOOLS', 'Dụng cụ kẹp giữ');

        $sku = $this->handToolSku('DC-NOPWR-001', 'Kìm cắt chéo', null, $categoryId, null, null);
        $this->publish($sku);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);

        $body = $this->getJson(self::BASE.'/products/dc-nopwr-001')->assertOk()->json('data');

        $this->assertNull($body['power_source']);
        $raw = $this->getJson(self::BASE.'/products/dc-nopwr-001')->getContent();
        $this->assertStringNotContainsString('N/A', $raw);
    }

    private function handToolSku(string $code, string $name, ?string $brandId, ?string $categoryId, ?string $familyName, ?string $variantLabel): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => $name,
            'sku_type' => 'PRODUCT',
            'unit' => 'Cái',
            'is_serial_tracked' => false,
            'brand_id' => $brandId,
            'category_id' => $categoryId,
            'family_name' => $familyName,
            'variant_label' => $variantLabel,
            'status' => 'ACTIVE',
        ]);
    }

    private function publish(Sku $sku): void
    {
        $headers = $this->superAdminHeaders();
        unset($headers['__user_id']);

        $this->patchJson(
            "/api/v1/pv/products/{$sku->sku_id}/publish",
            ['published' => true],
            $headers + ['If-Match' => '0'],
        )->assertOk();
    }

    private function brand(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('brands')->insert([
            'id' => $id, 'brand_code' => $code, 'brand_name' => $name,
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                'master_id' => $id, 'master_type' => 'BRAND', 'code' => $code, 'name' => $name,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function category(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('product_categories')->insert([
            'id' => $id, 'category_code' => $code, 'category_name' => $name,
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                'master_id' => $id, 'master_type' => 'CATEGORY', 'code' => $code, 'name' => $name,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
