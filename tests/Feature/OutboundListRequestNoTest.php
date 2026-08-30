<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Danh sách phiếu xuất (cột "Yêu cầu xuất") phải trả kèm request_no — dòng chỉ
 * lưu outbound_request_id (UUID), thiếu enrich sẽ khiến UI hiển thị UUID thô
 * thay vì mã toa đọc được (cùng lớp lỗi với sku_code ở chi tiết phiếu xuất).
 */
class OutboundListRequestNoTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_index_tra_kem_request_no_cho_moi_phieu_xuat(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('OUT-LIST-REQNO-01');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'YCX-LIST-REQNO-01');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        $response = $this
            ->getJson('/api/v1/outbound-documents', $headers)
            ->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere('id', $created['document_id']);
        $this->assertNotNull($row, 'Phải tìm thấy đúng phiếu xuất vừa tạo trong danh sách.');
        $this->assertSame($requestId, $row['outbound_request_id']);
        $this->assertSame('YCX-LIST-REQNO-01', $row['request_no']);
    }
}
