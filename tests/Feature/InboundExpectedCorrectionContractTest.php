<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * 2026-08-25 — hợp đồng GĐ1 scan-driven: khái niệm "điều chỉnh expected" đã
 * chết. Không còn nhập/sửa SKU + qty_planned dự kiến qua API; số lượng duy nhất
 * là số thực quét, và đường sửa sai là header-only (PATCH đầu phiếu) + unscan
 * (gỡ mã đã quét, stock-neutral). File này thay bản cũ vốn pin chuỗi nguồn
 * EXPECTED_QTY_BELOW_SCANNED / SCANNED_LINE_STRUCTURE_IMMUTABLE — các guard đó
 * không còn thuộc contract hiện hành.
 */
class InboundExpectedCorrectionContractTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** Client cũ gửi lines khi TẠO phiếu bị chặn bằng lỗi nghiệp vụ rõ ràng. */
    public function test_create_with_expected_lines_is_rejected_inbound_scan_driven(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('EXP-CORR-CREATE-01');

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 5]],
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'INBOUND_SCAN_DRIVEN');

        $this->assertSame(0, DB::table('stock_document_lines')->count());
    }

    /**
     * "Điều chỉnh expected" qua PATCH cũng không còn: gửi lines (kể cả sửa lại
     * qty của dòng đã quét) bị 422 INBOUND_SCAN_DRIVEN, dữ liệu quét giữ nguyên.
     */
    public function test_patch_with_expected_lines_is_rejected_and_scan_evidence_preserved(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('EXP-CORR-PATCH-01');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'EXP-CORR-PATCH-01', 'qty' => 2],
        ]);
        $lineId = (string) $fixture['lines']['EXP-CORR-PATCH-01']['line_id'];

        $this->patchJson("/api/v1/inbound-documents/{$fixture['document_id']}", [
            'lines' => [['id' => $lineId, 'qty_planned' => 99]],
        ], $headers + ['If-Match' => (string) $fixture['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INBOUND_SCAN_DRIVEN');

        // Bằng chứng quét và số thực nhận không bị chạm tới.
        $this->assertSame(2, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
        $this->assertSame(
            2,
            DB::table('inbound_scan_entries')->where('inbound_line_id', $lineId)->count()
        );
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'],
            'version' => $fixture['version'],
        ]);
    }

    /** Đường sửa sai hợp lệ #1: PATCH header-only (không đụng dòng/số lượng). */
    public function test_patch_header_fields_is_the_supported_correction_path(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('EXP-CORR-HDR-01');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'EXP-CORR-HDR-01', 'qty' => 1],
        ]);
        $lineId = (string) $fixture['lines']['EXP-CORR-HDR-01']['line_id'];

        $patch = $this->patchJson("/api/v1/inbound-documents/{$fixture['document_id']}", [
            'note' => 'Sửa ghi chú đầu phiếu — không đụng số lượng',
            'source_reference' => 'NCC đã xác nhận lại',
        ], $headers + ['If-Match' => (string) $fixture['version']]);

        $patch->assertOk()
            ->assertJsonPath('data.note', 'Sửa ghi chú đầu phiếu — không đụng số lượng')
            ->assertJsonPath('data.version', $fixture['version'] + 1);

        // Header update không sinh/xóa/sửa dòng và không đổi số thực quét.
        $this->assertSame(
            1,
            DB::table('stock_document_lines')->where('stock_document_id', $fixture['document_id'])->count()
        );
        $this->assertSame(1, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
    }

    /**
     * Đường sửa sai hợp lệ #2: unscan — endpoint gỡ mã đã quét phải tồn tại
     * trong route table (đối xứng outbound unmatch, stock-neutral trước Post).
     * Hành vi chi tiết của unscan được cover ở test khác; ở đây chốt rằng
     * contract sửa sai là header-only + unscan chứ không phải endpoint chỉnh
     * expected nào khác.
     */
    public function test_unscan_route_exists_as_the_scan_correction_surface(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('POST', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->all();

        $this->assertContains('api/v1/inbound-documents/{id}/unscan', $uris);
        // Không còn bề mặt "điều chỉnh expected" riêng nào cho inbound lines.
        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('inbound-documents/{id}/expected', (string) $uri);
            $this->assertStringNotContainsString('inbound-documents/{id}/lines', (string) $uri);
        }
    }

    /** Pin chuỗi mới đúng theo contract: controller header-only từ chối lines bằng INBOUND_SCAN_DRIVEN. */
    public function test_scan_driven_controller_pins_new_business_error(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php')
        );

        $this->assertStringContainsString("'INBOUND_SCAN_DRIVEN'", $source);
        $this->assertStringContainsString('rejectManualLines', $source);
        // Guard cũ của luồng expected không được quay lại controller mới.
        $this->assertStringNotContainsString('EXPECTED_QTY_BELOW_SCANNED', $source);
        $this->assertStringNotContainsString('SCANNED_LINE_STRUCTURE_IMMUTABLE', $source);
    }
}
