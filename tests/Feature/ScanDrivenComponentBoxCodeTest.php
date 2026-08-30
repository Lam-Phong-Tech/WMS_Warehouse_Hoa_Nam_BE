<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Luồng nhập kho scan-driven cho LINH KIỆN theo mã HỘP (BOX-<SKU>-<seq>).
 *
 * Nhân viên chỉ quét MỘT mã hộp (mang sẵn SKU linh kiện) rồi nhập số lượng đếm
 * tay — hệ thống tự nhận diện hộp, tự tạo COMPONENT SKU-candidate nếu chưa có,
 * tự tạo dòng nhập và đăng ký BOX; không cần chọn SKU / thêm dòng / đăng ký hộp
 * thủ công trước. Tương đương trải nghiệm quét PRODUCT có SKU mới.
 */
class ScanDrivenComponentBoxCodeTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /**
     * Quét một mã hộp trực tiếp (không kèm line_id/container_id) — server tự lo.
     *
     * @return TestResponse
     */
    private function scanBox(array $headers, string $documentId, string $boxCode, int $quantity, int $version)
    {
        return $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'quantity' => $quantity,
            'code_value' => $boxCode,
        ], $headers + ['If-Match' => (string) $version]);
    }

    /** Quét mã hộp với SKU chưa tồn tại: tự tạo COMPONENT SKU + dòng + BOX, tồn tăng đúng số đếm. */
    public function test_quet_ma_hop_tu_tao_sku_component_moi_va_ghi_so_luong(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $skuCode = 'HN-COMP-BASE-777';
        $boxCode = "BOX-{$skuCode}-0001";
        $this->assertSame(0, DB::table('sku')->where('sku_code', $skuCode)->count());

        $document = $this->createInboundHeader($headers, (string) $warehouse->id);
        $scan = $this->scanBox($headers, $document['document_id'], $boxCode, 5, $document['version'])->assertOk();

        // SKU tự tạo là COMPONENT, đếm theo hộp (không serial).
        $sku = DB::table('sku')->where('sku_code', $skuCode)->first();
        $this->assertNotNull($sku);
        $this->assertSame('COMPONENT', strtoupper((string) $sku->sku_type));
        $this->assertFalse((bool) $sku->is_serial_tracked);

        // Đúng một dòng cho SKU, scanned_qty = số đếm.
        $lines = DB::table('stock_document_lines')
            ->where('stock_document_id', $document['document_id'])
            ->where('product_id', $sku->sku_id)
            ->get();
        $this->assertCount(1, $lines);
        $this->assertSame(5, (int) $lines->first()->scanned_qty);

        // BOX được đăng ký, gắn đúng SKU của hộp, stock-neutral.
        $box = DB::table('containers')->where('container_code', $boxCode)->first();
        $this->assertNotNull($box);
        $this->assertSame('BOX', (string) $box->container_type);
        $this->assertSame((string) $sku->sku_id, (string) $box->primary_sku_id);

        // Post Receipt → tồn tăng đúng số đếm.
        $this->postInboundReceipt($headers, $document['document_id'], (int) $scan->json('data.version'))->assertOk();
        $available = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('sku_id', $sku->sku_id)->orWhere('product_id', $sku->sku_id))
            ->where('stock_status', 'AVAILABLE')
            ->value('available_qty');
        $this->assertSame(5, $available);
    }

    /** Quét mã hộp với COMPONENT SKU đã tồn tại: không tạo SKU trùng, vẫn ghi số lượng. */
    public function test_quet_ma_hop_voi_sku_component_da_co_khong_tao_trung(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('HN-COMP-EXIST-A');
        $boxCode = 'BOX-HN-COMP-EXIST-A-0007';

        $document = $this->createInboundHeader($headers, (string) $warehouse->id);
        $this->scanBox($headers, $document['document_id'], $boxCode, 3, $document['version'])->assertOk();

        $this->assertSame(1, DB::table('sku')->where('sku_code', 'HN-COMP-EXIST-A')->count());
        $line = DB::table('stock_document_lines')
            ->where('stock_document_id', $document['document_id'])
            ->where('product_id', $sku->sku_id)
            ->first();
        $this->assertNotNull($line);
        $this->assertSame(3, (int) $line->scanned_qty);
    }

    /** Mã hộp trỏ tới SKU đã tồn tại nhưng là PRODUCT → từ chối, không ghi nhận. */
    public function test_ma_hop_tro_toi_sku_product_bi_tu_choi(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('HN-PROD-BOXCONFLICT');
        $boxCode = 'BOX-HN-PROD-BOXCONFLICT-0001';

        $document = $this->createInboundHeader($headers, (string) $warehouse->id);
        $this->scanBox($headers, $document['document_id'], $boxCode, 2, $document['version'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOX_SKU_NOT_COMPONENT');

        $this->assertSame(
            0,
            (int) DB::table('stock_document_lines')->where('stock_document_id', $document['document_id'])->sum('scanned_qty')
        );
        $this->assertSame(0, DB::table('containers')->where('container_code', $boxCode)->count());
    }

    /** Quét cùng một hộp nhiều lần: tái sử dụng BOX EMPTY, cộng dồn số lượng. */
    public function test_quet_cung_hop_nhieu_lan_cong_don_so_luong(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $boxCode = 'BOX-HN-COMP-SUM-B-0003';

        $document = $this->createInboundHeader($headers, (string) $warehouse->id);
        $first = $this->scanBox($headers, $document['document_id'], $boxCode, 2, $document['version'])->assertOk();
        $this->scanBox($headers, $document['document_id'], $boxCode, 3, (int) $first->json('data.version'))->assertOk();

        $skuId = (string) DB::table('sku')->where('sku_code', 'HN-COMP-SUM-B')->value('sku_id');
        $lines = DB::table('stock_document_lines')
            ->where('stock_document_id', $document['document_id'])
            ->where('product_id', $skuId)
            ->get();
        $this->assertCount(1, $lines);
        $this->assertSame(5, (int) $lines->first()->scanned_qty);
        // Chỉ một BOX được dùng cho cả hai lần quét.
        $this->assertSame(1, DB::table('containers')->where('container_code', $boxCode)->count());
    }
}
