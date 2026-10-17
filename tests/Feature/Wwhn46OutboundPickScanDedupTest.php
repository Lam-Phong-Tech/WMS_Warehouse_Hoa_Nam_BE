<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-46 (HN-OUT-002, BE2): "Triển khai lấy/quét hàng/dedup/availability".
 *
 * Lưu ý traceability: file này ban đầu bị đặt tên nhầm là "Wwhn198..." (trùng số với
 * key tạm dùng lúc viết cho task inbound "[DEV] Triển khai quét/ghi nhận/validation",
 * HN-IN-002, phía INBOUND, không liên quan outbound pick/scan — key chính thức đã
 * xác nhận qua Jira live 2026-08-18 là WWHN-187, xem docs/docs_task_jira/WWHN-187.md).
 * Đã đổi tên đúng theo task cha thật (WWHN-46/HN-OUT-002) để tránh nhầm lẫn
 * traceability với WWHN-187.
 *
 * Rà soát xác nhận toàn bộ endpoint pick/scan (`outboundScan`), dedup cấp item
 * (`ITEM_ALREADY_MATCHED`/`DUPLICATE_ITEM_IN_DOCUMENT`) và availability check
 * (`assertIssueAvailability`) đã triển khai đầy đủ từ trước và đã có test HTTP ở
 * WWHN-195 (OutboundDocumentFlowTest) và WWHN-199 (Wwhn199ReservationConcurrencyGuardTest).
 *
 * Gap thực sự tìm thấy: các nhánh lỗi của `resolveItem()`/`scanStockDocument()`
 * (CurrentBaselineStockService.php) — chính là phần "quét mã" cốt lõi khi resolve
 * code_value -> item — và các ràng buộc serial/SKU-type — chưa từng được lái qua
 * HTTP thật ở bất kỳ test nào trước đó. File này lấp gap coverage đó, không sửa
 * code sản xuất (logic đã đúng theo baseline).
 */
class Wwhn46OutboundPickScanDedupTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function draftDocumentWithLine(string $skuCode, int $qty = 1): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, $qty);
        $requestId = $this->outboundRequestFixture($sku->sku_id, $qty, 'TOA-'.Str::upper(Str::random(6)));

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        return [
            'headers' => $headers,
            'document_id' => $created['document_id'],
            'line_id' => $created['line_ids'][0],
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    public function test_scan_with_unknown_code_value_is_rejected(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-001');

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => 'QR-DOES-NOT-EXIST',
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
        $this->assertSame(0, DB::table('outbound_item_matches')->count(), 'Không được tạo match nào khi code_value không resolve được.');
        $this->assertSame(0, (int) DB::table('stock_document_lines')->where('id', $ctx['line_id'])->value('scanned_qty'));
    }

    public function test_scan_with_inactive_code_value_is_rejected(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-002');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        DB::table('physical_codes')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'QR',
            'status' => 'LOST',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => $codeValue,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
    }

    public function test_scan_rejects_mismatched_item_id_and_code_value(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-003');
        $itemA = $this->seedItemInStock($ctx['sku_id']);
        $itemB = $this->seedItemInStock($ctx['sku_id']);
        $codeForB = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemB, $codeForB);

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemA,
            'code_value' => $codeForB,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_ITEM_MISMATCH');
        $this->assertSame(0, DB::table('outbound_item_matches')->count());
    }

    public function test_scan_rejects_item_belonging_to_different_sku(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-004');
        $otherSku = $this->productSkuFixture('PICK-DEDUP-004-OTHER');
        $foreignItemId = $this->seedItemInStock($otherSku->sku_id);

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $foreignItemId,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'ITEM_SKU_MISMATCH');
    }

    public function test_scan_rejects_item_not_in_stock(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-005');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'ISSUED']);

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'ITEM_NOT_AVAILABLE');
    }

    public function test_scan_serial_tracked_line_rejects_quantity_greater_than_one(): void
    {
        // qty_planned phải >= 2 để request không bị SCAN_EXCEEDS_REQUIRED chặn trước
        // khi tới nhánh kiểm tra serial-tracked (thứ tự check trong scanStockDocument()).
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-006', 2);
        $itemId = $this->seedItemInStock($ctx['sku_id']);

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 2,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_QUANTITY_MUST_BE_ONE');
    }

    public function test_scan_product_line_without_item_is_rejected(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-007');

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'ITEM_REQUIRED');
    }

    public function test_scan_rejects_quantity_exceeding_line_required_qty(): void
    {
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-008', 1);
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        $this->seedRfidCode($itemId);

        $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $secondItemId = $this->seedItemInStock($ctx['sku_id']);
        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $secondItemId,
        ], $ctx['headers'] + ['If-Match' => '2']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'SCAN_EXCEEDS_REQUIRED');
    }

    public function test_scan_code_value_resolves_via_item_instances_fallback_when_physical_code_unassigned_type(): void
    {
        // resolveItem() ưu tiên physical_codes.assigned_object_id khi assigned_object_type=ITEM;
        // nếu không phải ITEM, fallback tra item_instances.physical_code_id. Test nhánh fallback.
        $ctx = $this->draftDocumentWithLine('PICK-DEDUP-009');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        $physicalCodeId = (string) Str::uuid();
        DB::table('physical_codes')->insert([
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'QR',
            'status' => 'ACTIVE',
            'assigned_object_type' => null,
            'assigned_object_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mirrorLegacyPhysicalCode($physicalCodeId, $codeValue, 'QR', $itemId);
        DB::table('item_instances')->where('item_id', $itemId)->update(['physical_code_id' => $physicalCodeId]);

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => $codeValue,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertOk()->assertJsonPath('data.item_id', $itemId);
    }

    /**
     * PRODUCT_SKU_REQUIRED thực tế được chặn sớm hơn dự kiến ban đầu — tại
     * CurrentBaselineDocumentController::hydrateOutboundRequest() (create-from-request),
     * không phải tại scan — vì toa nguồn (outbound_request_lines) đã gắn SKU cố định
     * trước khi document/line tồn tại để scan tới. Điều này xác nhận đúng boundary
     * 2 lớp validate: SKU type được chốt tại create, scanStockDocument() chỉ re-check
     * phòng hờ (defense in depth) cho trường hợp SKU bị đổi type sau khi toa đã tạo.
     */
    public function test_outbound_request_intake_resolves_a_valid_sku_code_when_the_submitted_id_is_stale(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = $this->productSkuFixture('PICK-DEDUP-SKU-FALLBACK');

        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-'.Str::upper(Str::random(6)),
                'customer_name' => 'Đại lý test',
                // UUID hợp lệ về định dạng nhưng không còn trong catalog — mô phỏng
                // dữ liệu chọn SKU bị stale trong lúc catalog vừa đồng bộ.
                'sku_id' => (string) Str::uuid(),
                'sku_code' => $sku->sku_code,
                'required_qty' => 1,
            ]],
        ], $headers);

        $import->assertCreated()
            ->assertJsonPath('data.import.status', 'VALIDATED');
        $normalized = json_decode($import->json('data.rows.0.normalized_payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($sku->sku_id, $normalized['sku_id']);
        $this->assertSame($sku->sku_code, $normalized['sku_code']);
    }

    public function test_outbound_request_intake_rejects_mismatched_sku_id_and_code(): void
    {
        $headers = $this->superAdminHeaders();
        $firstSku = $this->productSkuFixture('PICK-DEDUP-SKU-MISMATCH-A');
        $secondSku = $this->productSkuFixture('PICK-DEDUP-SKU-MISMATCH-B');

        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-'.Str::upper(Str::random(6)),
                'customer_name' => 'Đại lý test',
                'sku_id' => $firstSku->sku_id,
                'sku_code' => $secondSku->sku_code,
                'required_qty' => 1,
            ]],
        ], $headers);

        $import->assertCreated()
            ->assertJsonPath('data.import.status', 'REJECTED')
            ->assertJsonPath('data.rows.0.error_code', 'SKU_REFERENCE_MISMATCH');
    }

    /**
     * §5.4.1: COMPONENT chỉ được xuất qua Component Issue gắn warranty case active,
     * nên toa xuất chứa COMPONENT bị chặn ngay ở bước tiếp nhận/Confirm (§8.1 — không
     * còn endpoint tạo phiếu xuất trực tiếp để chặn ở đó nữa).
     */
    public function test_outbound_request_intake_rejects_component_sku_request(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $componentSku = Sku::create([
            'sku_code' => 'PICK-DEDUP-010-COMP',
            'sku_name' => 'Component test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $this->seedAvailableBalance($warehouse->id, $componentSku->sku_id, 1);

        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-'.Str::upper(Str::random(6)),
                'customer_name' => 'Đại lý test',
                'sku_id' => $componentSku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers);

        $import->assertCreated()->assertJsonPath('data.import.status', 'REJECTED');
        $this->assertSame(
            'COMPONENT_OUTBOUND_FORBIDDEN',
            (string) $import->json('data.rows.0.error_code'),
        );

        $importId = (string) $import->json('data.import.id');
        $this->postJson("/api/v1/outbound-request-imports/{$importId}/confirm", [], $headers + [
            'If-Match' => (string) ((int) $import->json('data.import.version')),
            'Idempotency-Key' => 'confirm-component-forbidden',
        ])->assertStatus(409)->assertJsonPath('error.code', 'IMPORT_HAS_BLOCKING_ERRORS');

        $this->assertSame(0, DB::table('stock_documents')->where('doc_type', 'issue')->count());
    }
}
