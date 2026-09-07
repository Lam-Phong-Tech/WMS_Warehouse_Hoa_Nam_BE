<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * SKU MỚI phải đủ thông tin trước khi được Ghi nhận nhập kho.
 *
 * Luồng scan-driven tự tạo SKU khi quét phải mã lạ (materializeSkuIfMissing):
 * SKU đó chỉ có mã, tên tạm bằng chính mã, ĐVT rỗng. Nếu cho Post luôn thì hàng
 * vào kho dưới một dòng không ai đọc được, và sau khi có movement thì BE khoá
 * luôn mã/tracking (SKU_IDENTITY_IMMUTABLE_AFTER_STOCK) — sửa lại rất đắt.
 *
 * Bộ này khoá bốn mặt của luật:
 *  - chi tiết phiếu trả cờ thiếu thông tin trên từng dòng (FE dựa vào để khoá nút);
 *  - Post Receipt bị 422 khi còn SKU thiếu thông tin — chặn ở BE nên MỌI kênh
 *    (kể cả Mini App, vốn post qua đúng endpoint này) đều chịu chung luật;
 *  - Nhân viên kho hoàn thiện được thông tin bằng endpoint hẹp trong phiếu, dù
 *    KHÔNG có quyền sku.manage để sửa SKU ở màn Danh mục;
 *  - endpoint hẹp đó không phải cửa sau: SKU đã vào kho thì từ chối.
 */
class InboundNewSkuInfoGateTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** @return array{headers: array, document_id: string, version: int, line_id: string, sku_code: string} */
    private function scanUnknownSkuIntoDraft(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouseFixture();
        $draft = $this->createInboundHeader($headers, (string) $warehouse->id);
        $scan = $this->scanProductItems(
            $headers,
            $draft['document_id'],
            $skuCode,
            1,
            $draft['version'],
            'SN-'.Str::upper(Str::random(6))
        );

        return [
            'headers' => $headers,
            'document_id' => $draft['document_id'],
            'version' => $scan['version'],
            'line_id' => (string) $scan['line_id'],
            'sku_code' => $skuCode,
        ];
    }

    private function postReceipt(array $ctx)
    {
        return $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt",
            [],
            $ctx['headers'] + [
                'If-Match' => (string) $ctx['version'],
                'Idempotency-Key' => 'sku-info-'.Str::lower(Str::random(10)),
            ]
        );
    }

    public function test_document_detail_flags_line_with_incomplete_sku(): void
    {
        $ctx = $this->scanUnknownSkuIntoDraft($this->superAdminHeaders(), 'HN-NEWSKU-GATE-001');

        $detail = $this->getJson("/api/v1/inbound-documents/{$ctx['document_id']}", $ctx['headers'])
            ->assertOk();

        $detail->assertJsonPath('data.lines.0.product_info_complete', false);
        // Tên tạm bằng mã VÀ ĐVT rỗng — cả hai đều phải được nêu tên, để FE nói
        // đúng thứ còn thiếu thay vì một câu chung chung.
        $this->assertSame(
            ['sku_name', 'unit'],
            $detail->json('data.lines.0.product_missing_info')
        );
    }

    public function test_post_receipt_blocked_while_new_sku_info_incomplete(): void
    {
        $ctx = $this->scanUnknownSkuIntoDraft($this->superAdminHeaders(), 'HN-NEWSKU-GATE-002');

        $this->postReceipt($ctx)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INBOUND_SKU_INFO_INCOMPLETE')
            // Chi tiết lỗi nằm ở `errors` theo envelope chuẩn của ApiError::abort,
            // không phải `data` (chỉ response thành công mới có `data`).
            ->assertJsonPath('errors.skus.0.sku_code', 'HN-NEWSKU-GATE-002')
            ->assertJsonPath('errors.skus.0.missing_fields', ['sku_name', 'unit']);

        // Chặn TRƯỚC mọi hiệu ứng tồn kho: không movement, phiếu vẫn SCANNING.
        $this->assertSame(
            'SCANNING',
            DB::table('stock_documents')->where('id', $ctx['document_id'])->value('status')
        );
        $skuId = DB::table('sku')->where('sku_code', 'HN-NEWSKU-GATE-002')->value('sku_id');
        $this->assertFalse(DB::table('stock_movements')->where('product_id', $skuId)->exists());
    }

    public function test_warehouse_staff_completes_info_then_posts_receipt(): void
    {
        // Chính vai đứng quét và Post — vai này KHÔNG có sku.manage.
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $ctx = $this->scanUnknownSkuIntoDraft($headers, 'HN-NEWSKU-GATE-003');

        $this->patchJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/lines/{$ctx['line_id']}/sku",
            ['sku_name' => 'Máy khoan búa DCPB16', 'unit' => 'Cái'],
            $headers
        )
            ->assertOk()
            ->assertJsonPath('data.info_complete', true)
            ->assertJsonPath('data.sku_name', 'Máy khoan búa DCPB16');

        $this->postReceipt($ctx)
            ->assertOk()
            ->assertJsonPath('data.status', 'POSTED');
    }

    public function test_warehouse_staff_still_cannot_edit_sku_through_master_endpoint(): void
    {
        // Lý do tồn tại của endpoint hẹp: nếu vai kho sửa được SKU ở màn Danh mục
        // thì đã không cần route riêng. Ca này khoá đúng ranh giới đó.
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $ctx = $this->scanUnknownSkuIntoDraft($headers, 'HN-NEWSKU-GATE-004');
        $skuId = DB::table('sku')->where('sku_code', 'HN-NEWSKU-GATE-004')->value('sku_id');

        $this->patchJson(
            "/api/v1/skus/{$skuId}",
            ['sku_name' => 'Sửa thẳng ở màn danh mục'],
            $headers + ['If-Match' => '1', 'Idempotency-Key' => 'sku-direct-'.Str::lower(Str::random(8))]
        )->assertForbidden();
    }

    public function test_complete_endpoint_rejects_name_equal_to_code(): void
    {
        $headers = $this->superAdminHeaders();
        $ctx = $this->scanUnknownSkuIntoDraft($headers, 'HN-NEWSKU-GATE-005');

        $this->patchJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/lines/{$ctx['line_id']}/sku",
            ['sku_name' => 'hn-newsku-gate-005', 'unit' => 'Cái'],
            $headers
        )
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SKU_INFO_STILL_INCOMPLETE');
    }

    public function test_complete_endpoint_refuses_sku_that_already_entered_stock(): void
    {
        $headers = $this->superAdminHeaders();
        $first = $this->scanUnknownSkuIntoDraft($headers, 'HN-NEWSKU-GATE-006');
        $this->patchJson(
            "/api/v1/inbound-documents/{$first['document_id']}/lines/{$first['line_id']}/sku",
            ['sku_name' => 'Máy mài góc DCSM100', 'unit' => 'Cái'],
            $headers
        )->assertOk();
        $this->postReceipt($first)->assertOk();

        // Phiếu thứ hai quét lại chính SKU đó: nay nó đã lưu hành, endpoint hẹp
        // phải từ chối thay vì trở thành đường đổi tên SKU đang dùng.
        $warehouse = $this->warehouseFixture();
        $second = $this->createInboundHeader($headers, (string) $warehouse->id);
        $scan = $this->scanProductItems(
            $headers,
            $second['document_id'],
            'HN-NEWSKU-GATE-006',
            1,
            $second['version'],
            'SN-'.Str::upper(Str::random(6))
        );

        $this->patchJson(
            "/api/v1/inbound-documents/{$second['document_id']}/lines/{$scan['line_id']}/sku",
            ['sku_name' => 'Đổi tên trộm', 'unit' => 'Cái'],
            $headers
        )
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SKU_ALREADY_IN_STOCK');
    }
}
