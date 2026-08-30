<?php

namespace Tests\Unit;

use Modules\Warehouse\Services\BoxCodeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parser thuần cho mã HỘP linh kiện BOX-<SKU>-<seq> — không chạm DB.
 * Khởi tạo tường minh prefix để không phụ thuộc Laravel config container.
 */
class BoxCodeParserTest extends TestCase
{
    private function parser(): BoxCodeParser
    {
        return new BoxCodeParser('BOX');
    }

    /** Ca thật của nghiệp vụ: SKU kết thúc bằng chữ số phải tách đúng khỏi box_seq. */
    public function test_tach_dung_khi_sku_ket_thuc_bang_chu_so(): void
    {
        $parsed = $this->parser()->parse('BOX-HN-COMP-BASE-003-0001');

        $this->assertNotNull($parsed);
        $this->assertSame('BOX-HN-COMP-BASE-003-0001', $parsed['box_code']);
        $this->assertSame('HN-COMP-BASE-003', $parsed['sku_code']);
        $this->assertSame('0001', $parsed['box_seq']);
    }

    public function test_trim_va_uppercase_theo_quy_uoc_chuan_hoa(): void
    {
        $parsed = $this->parser()->parse('  box-hn-comp-base-003-0001  ');

        $this->assertNotNull($parsed);
        $this->assertSame('BOX-HN-COMP-BASE-003-0001', $parsed['box_code']);
        $this->assertSame('HN-COMP-BASE-003', $parsed['sku_code']);
    }

    #[DataProvider('nonBoxCodes')]
    public function test_ma_khong_phai_hop_tra_null(string $raw): void
    {
        $this->assertNull($this->parser()->parse($raw));
        $this->assertFalse($this->parser()->looksLikeBox($raw));
    }

    /** @return array<string, array{0: string}> */
    public static function nonBoxCodes(): array
    {
        return [
            'rong' => [''],
            'product_qr_composite' => ['HN1|SKU=HN-COMP-BASE-003|ITEM=SN0001'],
            'thieu_sku' => ['BOX-0001'],
            'seq_khong_phai_so' => ['BOX-HN-COMP-BASE-003-ABCD'],
            'prefix_khac' => ['CARTON-HN-PROD-01-0001'],
        ];
    }
}
