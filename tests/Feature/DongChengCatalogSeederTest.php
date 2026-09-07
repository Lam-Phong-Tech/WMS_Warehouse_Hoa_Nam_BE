<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\DongChengAnchorSkus;
use Modules\Warehouse\Database\Seeders\DongChengCatalogSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder;
use Modules\Warehouse\Support\PvRelations;
use Tests\TestCase;

/**
 * Chốt hợp đồng của DANH MỤC THẬT: sau khi seed, kho phải là hàng DongCheng có
 * ảnh và thuộc tính đầy đủ, không còn mã demo bịa tên của bộ seeder đời trước.
 *
 * Chạy trên sqlite nên seeder chỉ nạp lát cắt mẫu (SQLITE_SAMPLE) — vẫn đủ để
 * bắt hồi quy về cấu trúc: thiếu ảnh, thiếu nhóm hàng/nguồn điện, mã neo lệch
 * cách theo dõi, hay mã demo quay trở lại.
 */
class DongChengCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): void
    {
        $this->seed(AuthDatabaseSeeder::class);
        $this->seed(WarehouseDatabaseSeeder::class);
        $this->seed(DongChengCatalogSeeder::class);
    }

    public function test_catalog_data_file_is_committed_and_well_formed(): void
    {
        $path = base_path('Modules/Warehouse/database/data/dongcheng_catalog.json');
        $this->assertFileExists($path, 'Thiếu file danh mục thật — clone về sẽ seed ra kho rỗng.');

        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('DONGCHENG', $catalog['brand']['code']);
        $this->assertGreaterThan(900, count($catalog['products']), 'Danh mục thật phải có ~972 mã hàng.');
        $this->assertNotEmpty($catalog['components']);

        foreach ($catalog['products'] as $row) {
            $this->assertNotEmpty($row['image_url'], "Mã {$row['sku_code']} thiếu link ảnh.");
            $this->assertSame(
                strtoupper((string) $row['sku_code']),
                (string) $row['sku_code'],
                'Mã hàng phải viết hoa — tầng quét chuẩn hoá về chữ hoa trước khi tra.'
            );
        }
    }

    public function test_seeded_catalog_is_real_dongcheng_stock_with_full_attributes(): void
    {
        $this->seedCatalog();

        $this->assertSame(
            'DongCheng',
            DB::table('brands')->where('brand_code', 'DONGCHENG')->value('brand_name')
        );

        $incomplete = DB::table('sku')
            ->where(function ($q): void {
                $q->whereNull('category_id')
                    ->orWhereNull('power_source_id')
                    ->orWhereNull('package_variant_id')
                    ->orWhereNull('family_name');
            })
            ->pluck('sku_code')
            ->all();
        $this->assertSame([], $incomplete, 'Mọi mã hàng phải đủ nhóm hàng / nguồn điện / quy cách / dòng máy.');
    }

    public function test_no_legacy_demo_sku_codes_remain(): void
    {
        $this->seedCatalog();

        foreach (DongChengAnchorSkus::LEGACY_DEMO_PREFIXES as $prefix) {
            $this->assertSame(
                0,
                DB::table('sku')->where('sku_code', 'like', $prefix.'%')->count(),
                "Mã demo cũ {$prefix}* đã quay lại danh mục."
            );
        }
    }

    public function test_anchor_skus_keep_the_tracking_mode_their_flows_need(): void
    {
        $this->seedCatalog();

        // Vai PRODUCT: máy phải theo serial thì luồng nhập mới sinh được hiện vật
        // và mở được hồ sơ bảo hành.
        foreach ([DongChengAnchorSkus::PRODUCT_1, DongChengAnchorSkus::PRODUCT_2, DongChengAnchorSkus::PRODUCT_REPORT] as $code) {
            $row = DB::table('sku')->where('sku_code', $code)->first();
            $this->assertNotNull($row, "Thiếu mã neo {$code}.");
            $this->assertSame('PRODUCT', $row->sku_type);
            $this->assertTrue((bool) $row->is_serial_tracked, "{$code} phải theo dõi serial.");
        }

        // Vai COMPONENT theo SỐ LƯỢNG: luồng nhập theo hộp + xuất linh kiện đếm
        // theo số lượng, gắn nhầm mã serial vào đây là vỡ phiếu.
        foreach ([DongChengAnchorSkus::COMPONENT_1, DongChengAnchorSkus::COMPONENT_2, DongChengAnchorSkus::COMPONENT_WARRANTY] as $code) {
            $row = DB::table('sku')->where('sku_code', $code)->first();
            $this->assertNotNull($row, "Thiếu mã neo {$code}.");
            $this->assertSame('COMPONENT', $row->sku_type);
            $this->assertFalse((bool) $row->is_serial_tracked, "{$code} phải theo dõi theo số lượng.");
        }

        // Vai COMPONENT theo SERIAL: pin có số máy, dùng cho xuất linh kiện truy vết.
        $battery = DB::table('sku')->where('sku_code', DongChengAnchorSkus::COMPONENT_SERIAL_1)->first();
        $this->assertNotNull($battery);
        $this->assertTrue((bool) $battery->is_serial_tracked);
    }

    public function test_published_products_have_a_primary_image_and_public_copy(): void
    {
        $this->seedCatalog();

        $profile = PvRelations::profile();
        $published = DB::table($profile['table'])->where('published', true)->get();
        $this->assertNotEmpty($published, 'Phải có sản phẩm lên App PV.');

        // `short_description` chỉ có ở bảng hồ sơ đầy đủ của PostgreSQL; bảng
        // tương ứng trên sqlite gọn hơn (xem DongChengCatalogSeeder::onlyExistingColumns).
        $hasShortDescription = Schema::hasColumn($profile['table'], 'short_description');

        foreach ($published as $row) {
            $profileId = $row->{$profile['key']};
            $this->assertNotEmpty($row->description, 'Hồ sơ công khai thiếu mô tả (Tính năng).');
            if ($hasShortDescription) {
                $this->assertNotEmpty($row->short_description, 'Hồ sơ công khai thiếu mô tả ngắn (Công dụng).');
            }
            $this->assertTrue(
                DB::table('pv_product_images')
                    ->where('profile_id', $profileId)
                    ->where('is_primary', true)
                    ->where('status', 'ACTIVE')
                    ->exists(),
                'Sản phẩm đã publish nhưng không có ảnh chính — App PV sẽ hiện ô trống.'
            );
        }
    }

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        $this->seedCatalog();
        $before = [
            'sku' => DB::table('sku')->count(),
            'images' => DB::table('pv_product_images')->count(),
            'industries' => DB::table('pv_product_industry')->count(),
        ];

        $this->seed(DongChengCatalogSeeder::class);

        $this->assertSame($before['sku'], DB::table('sku')->count());
        $this->assertSame($before['images'], DB::table('pv_product_images')->count());
        $this->assertSame($before['industries'], DB::table('pv_product_industry')->count());
    }
}
