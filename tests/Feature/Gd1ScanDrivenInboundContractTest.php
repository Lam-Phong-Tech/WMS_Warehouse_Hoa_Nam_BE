<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * GĐ1-REQ-004/005/006/007/008/021 — hợp đồng inbound scan-driven end-to-end:
 * header-only, gom line theo SKU, unknown SKU register-at-Post, chặn mã máy đã
 * tồn tại, Post Receipt theo hàng thực nhận (không gate theo tham chiếu).
 */
class Gd1ScanDrivenInboundContractTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** TC-P0-01: tạo phiếu header-only thành công, không dòng nào. */
    public function test_tao_phieu_header_only_khong_can_dong_sku(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Chứng từ vận chuyển 123',
        ], $headers)->assertCreated();

        $documentId = (string) $create->json('data.id');
        $this->assertSame([], $create->json('data.lines'));
        $this->assertSame(0, DB::table('stock_document_lines')->where('stock_document_id', $documentId)->count());
    }

    /** Client cũ gửi lines phải bị chặn bằng lỗi nghiệp vụ rõ ràng. */
    public function test_gui_lines_thu_cong_bi_tu_choi_theo_contract_scan_driven(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('GD1-REJ-01');

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 5]],
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'INBOUND_SCAN_DRIVEN');
    }

    /** TC-P0-02 + TC-P0-03: scan 3 item cùng SKU → đúng MỘT dòng, scanned_qty = 3. */
    public function test_scan_cung_sku_tu_gom_mot_dong(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('GD1-GRP-01');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'GD1-GRP-01', 'qty' => 3],
        ]);

        $lines = DB::table('stock_document_lines')
            ->where('stock_document_id', $fixture['document_id'])
            ->get();
        $this->assertCount(1, $lines);
        $this->assertSame(3, (int) $lines->first()->scanned_qty);
    }

    /**
     * TC-P0-04: SKU chưa tồn tại — hệ thống vẫn nhận, tạo đúng MỘT SKU, tồn tăng.
     *
     * 38b51a6 (07/09/2026, cổng INBOUND_SKU_INFO_INCOMPLETE — hợp đồng đầy đủ ở
     * InboundNewSkuInfoGateTest): SKU tự tạo lúc quét chỉ có mã (tên tạm = mã, ĐVT rỗng) nên
     * Post Receipt bị 422 cho tới khi bổ sung tên + ĐVT qua PATCH
     * inbound-documents/{id}/lines/{lineId}/sku. "Vẫn nhận" giữ nguyên; Post chỉ thêm bước
     * bổ sung thông tin, và bị chặn TRƯỚC mọi hiệu ứng tồn.
     */
    public function test_scan_sku_chua_ton_tai_van_nhan_va_tao_dung_mot_sku(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'GD1-NEW-01')->count());

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'GD1-NEW-01', 'qty' => 2],
        ]);

        $this->assertSame(1, DB::table('sku')->where('sku_code', 'GD1-NEW-01')->count());
        $skuId = (string) DB::table('sku')->where('sku_code', 'GD1-NEW-01')->value('sku_id');

        $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INBOUND_SKU_INFO_INCOMPLETE')
            ->assertJsonPath('errors.skus.0.sku_code', 'GD1-NEW-01')
            ->assertJsonPath('errors.skus.0.missing_fields', ['sku_name', 'unit']);
        $this->assertFalse(DB::table('stock_movements')->where('product_id', $skuId)->exists());

        $lineId = (string) $fixture['lines']['GD1-NEW-01']['line_id'];
        $this->patchJson("/api/v1/inbound-documents/{$fixture['document_id']}/lines/{$lineId}/sku", [
            'sku_name' => 'Máy khoan GD1 NEW 01',
            'unit' => 'Cái',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.info_complete', true);

        $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'])->assertOk();
        $available = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
            ->where('stock_status', 'AVAILABLE')
            ->value('available_qty');
        $this->assertSame(2, $available);
        // Vẫn đúng một SKU sau Post (register-at-Post không nhân bản).
        $this->assertSame(1, DB::table('sku')->where('sku_code', 'GD1-NEW-01')->count());
    }

    /** TC-P0-05: mã máy đã tồn tại (kể cả ISSUED) không được nhận lại qua inbound thường. */
    public function test_ma_may_da_ton_tai_bi_chan_khoi_inbound_thuong(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('GD1-DUP-01');

        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SN-GD1-DUP-0001',
            'item_status' => 'ISSUED',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = $this->createInboundHeader($headers, (string) $warehouse->id);
        $this->postJson("/api/v1/inbound-documents/{$document['document_id']}/scans", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('GD1-DUP-01', 'SN-GD1-DUP-0001'),
        ], $headers + ['If-Match' => (string) $document['version']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_REGISTERED');

        $this->assertSame(
            0,
            DB::table('stock_document_lines')->where('stock_document_id', $document['document_id'])->sum('scanned_qty')
        );
    }

    /** TC-P0-06/07: thực nhận bao nhiêu ghi nhận bấy nhiêu — không gate theo chứng từ. */
    public function test_post_receipt_theo_thuc_nhan_khong_gate_tham_chieu(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('GD1-ACT-01');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'GD1-ACT-01', 'qty' => 2],
        ]);

        $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'])->assertOk();

        $skuId = (string) DB::table('sku')->where('sku_code', 'GD1-ACT-01')->value('sku_id');
        $available = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
            ->where('stock_status', 'AVAILABLE')
            ->value('available_qty');
        $this->assertSame(2, $available);
    }

    /** TC-P0-09: COMPONENT không serial — Box → materialize dòng → nhập số đếm. */
    public function test_component_khong_serial_nhap_theo_so_dem_thuc_te(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $component = $this->componentSkuFixture('GD1-CMP-01');

        $document = $this->createInboundHeader($headers, (string) $warehouse->id);
        $received = $this->receiveComponentQuantity(
            $headers,
            $document['document_id'],
            (string) $component->sku_id,
            7,
            $document['version']
        );

        $line = DB::table('stock_document_lines')->where('id', $received['line_id'])->first();
        $this->assertSame(7, (int) $line->scanned_qty);

        $this->postInboundReceipt($headers, $document['document_id'], $received['version'])->assertOk();
    }

    /** TC-P0-10: Post Receipt retry cùng Idempotency-Key không cộng tồn hai lần. */
    public function test_post_receipt_retry_khong_double_stock(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('GD1-IDEM-01');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'GD1-IDEM-01', 'qty' => 1],
        ]);

        $key = (string) Str::uuid();
        $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'], $key)->assertOk();
        $replay = $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'], $key);
        $this->assertContains($replay->getStatusCode(), [200, 201]);

        $skuId = (string) DB::table('sku')->where('sku_code', 'GD1-IDEM-01')->value('sku_id');
        $available = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('sku_id', $skuId)->orWhere('product_id', $skuId))
            ->where('stock_status', 'AVAILABLE')
            ->value('available_qty');
        $this->assertSame(1, $available);
    }
}
