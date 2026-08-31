<?php

namespace Tests\Unit;

use Modules\Warehouse\Services\HandToolsCatalogExcelMapper;
use PHPUnit\Framework\TestCase;

/**
 * Khoá đúng những sự thật dữ liệu đã kiểm tra thủ công trên file nguồn thật
 * (docs/Phan_loai_du_lieu_Handtool_DongCheng_2026_Cloudflare.xlsx, "Chú giải"
 * sheet): 541 dòng / 539 mã duy nhất / 2 mã trùng nguồn (D094101, D156601) /
 * 12 category. Test này KHÔNG dùng DB/HTTP — thuần đọc file, nên đứng ở
 * tests/Unit thay vì Feature.
 *
 * Nếu file nguồn được BA cập nhật, test này đỏ ngay lập tức thay vì âm thầm
 * import sai — đúng tinh thần "evidence bắt buộc" của P0.
 */
class HandToolsCatalogExcelMapperTest extends TestCase
{
    private const SOURCE_FILE = __DIR__.'/../../docs/Phan_loai_du_lieu_Handtool_DongCheng_2026_Cloudflare.xlsx';

    public function test_maps_all_541_rows_with_two_known_conflicting_codes_and_537_clean(): void
    {
        if (! is_file(self::SOURCE_FILE)) {
            $this->markTestSkipped('File nguồn Hand Tools DongCheng không có trong working tree này.');
        }

        $mapped = (new HandToolsCatalogExcelMapper)->map(self::SOURCE_FILE);

        $this->assertCount(541, $mapped['items']);
        $this->assertCount(541, $mapped['excel_rows']);
        $this->assertCount(537, $mapped['clean_items']);
        $this->assertSame([], $mapped['unmapped_categories'], 'Cả 12 category nguồn phải map được — không được rơi rớt dòng nào.');

        $this->assertEqualsCanonicalizing(['D094101', 'D156601'], array_keys($mapped['conflicted_codes']));
        $this->assertCount(2, $mapped['conflicted_codes']['D094101']);
        $this->assertCount(2, $mapped['conflicted_codes']['D156601']);

        foreach ($mapped['clean_items'] as $item) {
            $this->assertNotSame('D094101', $item['product_code']);
            $this->assertNotSame('D156601', $item['product_code']);
        }
    }

    public function test_every_item_is_hand_tools_dongcheng_with_null_power_source(): void
    {
        if (! is_file(self::SOURCE_FILE)) {
            $this->markTestSkipped('File nguồn Hand Tools DongCheng không có trong working tree này.');
        }

        $mapped = (new HandToolsCatalogExcelMapper)->map(self::SOURCE_FILE);
        $knownCategoryCodes = [
            'CLAMPING_TOOLS', 'WRENCHES', 'SOCKETS', 'FASTENING_TOOLS', 'CUTTING_TOOLS',
            'MEASURING_TOOLS', 'ELECTRICAL_TOOLS', 'PAINTING_WALL_TOOLS', 'MECHANICAL_TOOLS',
            'HYDRAULIC_TOOLS', 'STORAGE_TOOLS', 'GARDEN_HAND_TOOLS',
        ];

        foreach ($mapped['items'] as $item) {
            $this->assertSame('DONGCHENG', $item['brand_code']);
            $this->assertSame('HAND_TOOLS', $item['domain']);
            $this->assertNull($item['power_source']);
            $this->assertContains($item['category_code'], $knownCategoryCodes);
            $this->assertNotEmpty($item['media'], "product_code {$item['product_code']} phải có ít nhất 1 ảnh.");
            $this->assertSame('PRIMARY', $item['media'][0]['role']);
        }
    }

    public function test_family_and_variant_label_are_derived_from_name_minus_family_prefix(): void
    {
        if (! is_file(self::SOURCE_FILE)) {
            $this->markTestSkipped('File nguồn Hand Tools DongCheng không có trong working tree này.');
        }

        $mapped = (new HandToolsCatalogExcelMapper)->map(self::SOURCE_FILE);
        $byCode = collect($mapped['items'])->keyBy('product_code');

        $six = $byCode['D070106'];
        $this->assertSame('Kìm điện đa năng', $six['family_name']);
        $this->assertSame('6"', $six['variant_label']);

        $seven = $byCode['D070107'];
        $this->assertSame('Kìm điện đa năng', $seven['family_name']);
        $this->assertSame('7"', $seven['variant_label']);

        // Ảnh sản phẩm chia sẻ đúng URL trong cùng family, gallery lấy từ sheet
        // "Ảnh tổng quan" (p006_y0389_l0_overview.jpg cho nhóm "Kìm điện đa năng").
        $this->assertSame($six['media'][0]['url'], $seven['media'][0]['url']);
        $galleryUrls = collect($six['media'])->firstWhere('role', 'GALLERY');
        $this->assertNotNull($galleryUrls);
        $this->assertStringContainsString('overview', $galleryUrls['url']);
    }

    public function test_barcode_text_is_passed_through_raw_for_the_importer_to_sanitize(): void
    {
        if (! is_file(self::SOURCE_FILE)) {
            $this->markTestSkipped('File nguồn Hand Tools DongCheng không có trong working tree này.');
        }

        $mapped = (new HandToolsCatalogExcelMapper)->map(self::SOURCE_FILE);
        $byCode = collect($mapped['items'])->keyBy('product_code');

        $six = $byCode['D070106'];
        $this->assertStringContainsString('Mã vạch', (string) $six['specifications']['package']);
        $this->assertStringContainsString('6927073993418', (string) $six['specifications']['package']);
    }

    public function test_single_variant_product_has_no_variant_label(): void
    {
        if (! is_file(self::SOURCE_FILE)) {
            $this->markTestSkipped('File nguồn Hand Tools DongCheng không có trong working tree này.');
        }

        $mapped = (new HandToolsCatalogExcelMapper)->map(self::SOURCE_FILE);
        $byCode = collect($mapped['items'])->keyBy('product_code');

        $solo = $byCode['D070101'];
        $this->assertSame('Kìm mũi dài', $solo['family_name']);
        $this->assertNull($solo['variant_label']);
    }
}
