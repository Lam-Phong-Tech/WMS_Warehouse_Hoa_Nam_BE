<?php

namespace Tests\Unit;

use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\ProductQrPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Change Pack QR/SKU 20/08/2026 (DEC-QR-001) — BE-1: parser thuần cho composite/
 * legacy/invalid payload, không chạm DB. Khởi tạo tường minh prefix + version để
 * unit test không phụ thuộc Laravel config container.
 */
class ProductQrPayloadParserTest extends TestCase
{
    private function parser(): ProductQrPayloadParser
    {
        return new ProductQrPayloadParser('HN', [1]);
    }

    public function test_composite_v1_hop_le_parse_du_sku_va_item(): void
    {
        $parsed = $this->parser()->parse('HN1|SKU=DCH-DRILL-DJZ10A-20V-V1|ITEM=SN000012345');

        $this->assertSame(ProductQrPayload::FORMAT_COMPOSITE_V1, $parsed->format);
        $this->assertSame(ProductQrPayload::STATUS_VALID, $parsed->parseStatus);
        $this->assertSame(1, $parsed->payloadVersion);
        $this->assertSame('DCH-DRILL-DJZ10A-20V-V1', $parsed->skuCode);
        $this->assertSame('SN000012345', $parsed->itemUnique);
        $this->assertNull($parsed->serialNumber);
        $this->assertSame('SN000012345', $parsed->normalizedCodeValue);
    }

    public function test_composite_chuan_hoa_trim_va_uppercase_nhu_physical_codes(): void
    {
        $parsed = $this->parser()->parse('  hn1|sku=dch-drill|item=sn0001  ');

        $this->assertTrue($parsed->isComposite());
        $this->assertSame('DCH-DRILL', $parsed->skuCode);
        $this->assertSame('SN0001', $parsed->itemUnique);
    }

    public function test_composite_cho_phep_serial_rieng_qua_ser(): void
    {
        $parsed = $this->parser()->parse('HN1|SKU=DCH-DRILL|ITEM=HNITEM01|SER=NSX-9988');

        $this->assertTrue($parsed->isComposite());
        $this->assertSame('NSX-9988', $parsed->serialNumber);
    }

    public function test_thu_tu_segment_khong_bat_buoc(): void
    {
        $parsed = $this->parser()->parse('HN1|ITEM=SN01|SKU=ABC');

        $this->assertTrue($parsed->isComposite());
        $this->assertSame('ABC', $parsed->skuCode);
        $this->assertSame('SN01', $parsed->itemUnique);
    }

    public function test_version_khong_ho_tro_tra_unsupported_khong_doan_cau_truc(): void
    {
        $parsed = $this->parser()->parse('HN2|SKU=ABC|ITEM=SN01');

        $this->assertSame(ProductQrPayload::STATUS_UNSUPPORTED_VERSION, $parsed->parseStatus);
        $this->assertSame(2, $parsed->payloadVersion);
        $this->assertFalse($parsed->isValid());
    }

    /** @return array<string, array{0: string}> */
    public static function invalidCompositeProvider(): array
    {
        return [
            'thieu ITEM' => ['HN1|SKU=ABC'],
            'thieu SKU' => ['HN1|ITEM=SN01'],
            'sku rong' => ['HN1|SKU=|ITEM=SN01'],
            'item rong' => ['HN1|SKU=ABC|ITEM='],
            'segment khong co =' => ['HN1|SKU=ABC|SN01'],
            'segment la' => ['HN1|SKU=ABC|ITEM=SN01|FOO=BAR'],
            'segment trung' => ['HN1|SKU=ABC|SKU=DEF|ITEM=SN01'],
            'segment rong do | thua' => ['HN1|SKU=ABC|ITEM=SN01|'],
            'item qua dai' => ['HN1|SKU=ABC|ITEM='.str_repeat('A', 101)],
        ];
    }

    #[DataProvider('invalidCompositeProvider')]
    public function test_composite_sai_cau_truc_tra_invalid_format(string $raw): void
    {
        $parsed = $this->parser()->parse($raw);

        $this->assertSame(ProductQrPayload::STATUS_INVALID_FORMAT, $parsed->parseStatus);
        $this->assertFalse($parsed->isValid());
    }

    public function test_payload_co_prefix_composite_khong_roi_tham_ve_legacy(): void
    {
        // Đã "có ý định composite" (HN1|...) thì lỗi cấu trúc phải là INVALID,
        // tuyệt đối không được hiểu nguyên chuỗi thành plain code_value.
        $parsed = $this->parser()->parse('HN1|GARBAGE');

        $this->assertSame(ProductQrPayload::FORMAT_COMPOSITE_V1, $parsed->format);
        $this->assertFalse($parsed->isValid());
    }

    public function test_plain_code_tra_legacy_voi_normalized_uppercase(): void
    {
        $parsed = $this->parser()->parse('  carton-hn-0007 ');

        $this->assertTrue($parsed->isLegacy());
        $this->assertTrue($parsed->isValid());
        $this->assertSame('CARTON-HN-0007', $parsed->normalizedCodeValue);
        $this->assertNull($parsed->skuCode);
    }

    public function test_chuoi_rong_la_invalid(): void
    {
        $parsed = $this->parser()->parse('   ');

        $this->assertFalse($parsed->isValid());
    }

    public function test_gia_tri_chua_dau_bang_trong_item_van_parse_dung(): void
    {
        // explode('=', 2): phần sau dấu '=' đầu tiên giữ nguyên văn.
        $parsed = $this->parser()->parse('HN1|SKU=ABC|ITEM=SN=01');

        $this->assertTrue($parsed->isComposite());
        $this->assertSame('SN=01', $parsed->itemUnique);
    }
}
