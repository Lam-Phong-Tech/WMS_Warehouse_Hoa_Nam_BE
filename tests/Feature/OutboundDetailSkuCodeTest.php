<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Chi tiết phiếu xuất phải trả kèm sku_code + sku_name trên mỗi dòng — dòng chỉ
 * lưu product_id (UUID), thiếu enrich sẽ khiến UI hiển thị UUID thay vì mã SKU.
 */
class OutboundDetailSkuCodeTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_show_tra_kem_sku_code_va_sku_name_tren_moi_dong(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('OUT-DETAIL-SKU-01');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'REQ-OUT-DETAIL-01');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        $response = $this
            ->getJson("/api/v1/outbound-documents/{$created['document_id']}", $headers)
            ->assertOk();

        $response->assertJsonPath('data.lines.0.product_id', (string) $sku->sku_id);
        $response->assertJsonPath('data.lines.0.sku_code', 'OUT-DETAIL-SKU-01');
        $this->assertNotEmpty($response->json('data.lines.0.sku_name'));
    }
}
