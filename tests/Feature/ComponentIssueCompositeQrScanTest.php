<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Bug thật QA gặp: quét mã QR composite thật của một linh kiện serial-tracked
 * ĐÃ CÓ trong kho (HN1|SKU=...|ITEM=...|SER=...) vào Component Issue vẫn báo
 * PHYSICAL_CODE_NOT_FOUND ("Mã vạch/QR/RFID không tồn tại hoặc không hoạt
 * động"), dù item hoàn toàn tồn tại và ACTIVE.
 *
 * Nguyên nhân: `CurrentBaselineComponentFlowController::resolveTarget()` tra
 * thẳng CHUỖI COMPOSITE THÔ vào `physical_codes.code_value` — cột đó chỉ lưu
 * phần ITEM (vd "HN-COMP-SEED-0022-025"), không lưu nguyên chuỗi
 * "HN1|SKU=...|ITEM=...|SER=...". Khác với luồng inbound/outbound (đã parse
 * composite QR qua ProductQrPayloadParser từ lâu — DEC-QR-001), Component
 * Issue chưa từng được cập nhật nên MỌI lần quét QR thật (không phải mã hộp/
 * legacy trần) đều thất bại — luồng `code_value` này trước đây không có test
 * nào bao phủ (chỉ có test cho quantity-mode qua `container_id` trực tiếp).
 */
class ComponentIssueCompositeQrScanTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_quet_qr_composite_cua_linh_kien_serial_tracked_da_co_trong_kho(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('HN-COMP-SEED-0022', true);

        // Item đã có sẵn trong kho, gắn đúng mã vật lý = phần ITEM (không phải
        // nguyên chuỗi composite) — khớp thật với dữ liệu QA đã gặp.
        $itemUnique = 'HN-COMP-SEED-0022-025';
        $itemId = $this->seedItemInStock($sku->sku_id);
        DB::table('item_instances')->where('item_id', $itemId)->update([
            'serial_number' => $itemUnique,
            'item_code' => $itemUnique,
        ]);
        $this->seedScanCode($itemId, $itemUnique);

        $case = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => (string) $warehouse->id,
            'customer_name' => 'Khách test composite QR',
            'customer_phone' => '0911111111',
            'description' => 'Máy không lên nguồn',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'ciq-case-'.Str::random(10)])->assertCreated();
        $caseId = $this->moveCaseToChecking($headers, (string) $case->json('data.id'));

        $document = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouse->id,
            'warranty_case_id' => $caseId,
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $headers)->assertCreated();
        $documentId = (string) $document->json('data.document.id');
        $lineId = (string) $document->json('data.lines.0.id');

        // Đúng mã QR composite thật QA đã quét.
        $rawQr = $this->compositeProductQr((string) $sku->sku_code, $itemUnique, $itemUnique);

        $response = $this->postJson(
            "/api/v1/component-issue-documents/{$documentId}/scan",
            ['line_id' => $lineId, 'code_value' => $rawQr, 'quantity' => 1],
            $headers + ['If-Match' => '1'],
        );

        $response->assertOk();
        $this->assertSame($itemId, $response->json('data.item_id'));
        $this->assertSame(1, (int) $response->json('data.scanned_qty'));
        $this->assertSame(
            1,
            (int) DB::table('component_issue_items')
                ->where('component_issue_line_id', $lineId)
                ->where('item_id', $itemId)
                ->where('status', 'ACTIVE')
                ->count(),
        );
    }

    /** Mã hộp/legacy trần (không phải QR composite) vẫn phải hoạt động như cũ. */
    public function test_ma_vat_ly_tran_khong_phai_composite_van_hoat_dong_binh_thuong(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('HN-COMP-SEED-0023', true);

        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedScanCode($itemId, 'SN-PLAIN-0001');

        $case = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => (string) $warehouse->id,
            'customer_name' => 'Khách test mã trần',
            'customer_phone' => '0922222222',
            'description' => 'Lỗi cảm biến',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'ciq-case2-'.Str::random(10)])->assertCreated();
        $caseId = $this->moveCaseToChecking($headers, (string) $case->json('data.id'));

        $document = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouse->id,
            'warranty_case_id' => $caseId,
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $headers)->assertCreated();
        $documentId = (string) $document->json('data.document.id');
        $lineId = (string) $document->json('data.lines.0.id');

        $response = $this->postJson(
            "/api/v1/component-issue-documents/{$documentId}/scan",
            ['line_id' => $lineId, 'code_value' => 'sn-plain-0001', 'quantity' => 1],
            $headers + ['If-Match' => '1'],
        );

        $response->assertOk();
        $this->assertSame($itemId, $response->json('data.item_id'));
    }

    public function test_ma_khong_ton_tai_van_bao_dung_loi_khong_tim_thay(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('HN-COMP-SEED-0024', true);

        $case = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => (string) $warehouse->id,
            'customer_name' => 'Khách test mã sai',
            'customer_phone' => '0933333333',
            'description' => 'Lỗi nguồn',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'ciq-case3-'.Str::random(10)])->assertCreated();
        $caseId = $this->moveCaseToChecking($headers, (string) $case->json('data.id'));

        $document = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouse->id,
            'warranty_case_id' => $caseId,
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $headers)->assertCreated();
        $documentId = (string) $document->json('data.document.id');
        $lineId = (string) $document->json('data.lines.0.id');

        $rawQr = $this->compositeProductQr(
            (string) $sku->sku_code,
            'KHONG-TON-TAI-0001',
        );

        $this->postJson(
            "/api/v1/component-issue-documents/{$documentId}/scan",
            ['line_id' => $lineId, 'code_value' => $rawQr, 'quantity' => 1],
            $headers + ['If-Match' => '1'],
        )->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
    }

    /**
     * Bug thật QA gặp: 1 item đã được scan ACTIVE ở phiếu Component Issue A
     * (còn SCANNING, chưa Cancel/Post) thì scan tiếp SANG phiếu B khác phải bị
     * chặn với lý do nghiệp vụ rõ ràng — không được để lọt xuống DB rồi vỡ
     * unique index `uk_component_issue_active_item` (migration
     * 2026_08_10_000690_complete_component_issue_storage_contract.php) thành
     * lỗi kỹ thuật khó hiểu "Dữ liệu đã được ghi bởi thao tác khác...".
     *
     * Nguyên nhân cũ: check trùng ở `scan()` chỉ
     * `->where('cl.component_issue_document_id', $id)` — tức chỉ nhìn trong
     * phiếu hiện tại, không thấy item đã ACTIVE ở phiếu KHÁC.
     */
    public function test_item_da_active_o_phieu_khac_bi_chan_voi_ly_do_nghiep_vu_ro_rang(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('HN-COMP-SEED-0025', true);

        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedScanCode($itemId, 'SN-SHARED-0001');

        // Phiếu A: scan item này ACTIVE, còn SCANNING (chưa Cancel/Post).
        $caseA = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => (string) $warehouse->id,
            'customer_name' => 'Khách A',
            'customer_phone' => '0944444444',
            'description' => 'Lỗi motor',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'ciq-shared-a-'.Str::random(10)])->assertCreated();
        $documentA = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouse->id,
            'warranty_case_id' => $this->moveCaseToChecking($headers, (string) $caseA->json('data.id')),
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $headers)->assertCreated();
        $this->postJson(
            '/api/v1/component-issue-documents/'.$documentA->json('data.document.id').'/scan',
            [
                'line_id' => $documentA->json('data.lines.0.id'),
                'code_value' => 'SN-SHARED-0001',
                'quantity' => 1,
            ],
            $headers + ['If-Match' => '1'],
        )->assertOk();
        $componentIssueNoA = (string) $documentA->json('data.document.component_issue_no');

        // Phiếu B: scan CÙNG item này — phải bị chặn, không phải lỗi DB thô.
        $caseB = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => (string) $warehouse->id,
            'customer_name' => 'Khách B',
            'customer_phone' => '0955555555',
            'description' => 'Lỗi khác',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'ciq-shared-b-'.Str::random(10)])->assertCreated();
        $documentB = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouse->id,
            'warranty_case_id' => $this->moveCaseToChecking($headers, (string) $caseB->json('data.id')),
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $headers)->assertCreated();

        $response = $this->postJson(
            '/api/v1/component-issue-documents/'.$documentB->json('data.document.id').'/scan',
            [
                'line_id' => $documentB->json('data.lines.0.id'),
                'code_value' => 'SN-SHARED-0001',
                'quantity' => 1,
            ],
            $headers + ['If-Match' => '1'],
        );

        $response->assertStatus(422)->assertJsonPath('error.code', 'COMPONENT_ITEM_ACTIVE_ELSEWHERE');
        $this->assertStringContainsString(
            $componentIssueNoA,
            (string) $response->json('message'),
        );
    }
}
