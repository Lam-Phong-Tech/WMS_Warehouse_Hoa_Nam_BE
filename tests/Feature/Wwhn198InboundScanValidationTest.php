<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-198 (HN-IN-002, BE2): "Triển khai quét/ghi nhận/validation" — Inbound scan backend.
 *
 * Rà soát xác nhận toàn bộ endpoint `POST /inbound-documents/{id}/scan`
 * (`CurrentBaselineStockFlowController::inboundScan` -> `versionedScan` ->
 * `CurrentBaselineStockService::scanStockDocument`/`resolveItem`/`assertItemAvailableForReceipt`,
 * cộng middleware `EnsureInboundComponentBox`) đã triển khai đầy đủ nghiệp vụ từ trước
 * (chung hạ tầng permission/If-Match/transaction đã kiểm chứng ở WWHN-190), nhưng
 * **chưa từng được lái qua HTTP thật cho nhánh inbound cụ thể** ở bất kỳ test nào
 * trước đây — coverage cũ chỉ phủ outbound scan (`Wwhn46OutboundPickScanDedupTest`,
 * đổi tên từ file bị gán nhầm key "Wwhn198" trước đó — xem ghi chú traceability
 * trong WWHN-187.md) hoặc happy-path COMPONENT (`FoundationInboundPutawayReadinessTest`).
 *
 * File này lấp gap coverage đó cho nhánh inbound: validation quantity/line/SKU,
 * resolve item qua item_id/code_value, ràng buộc serial/receivable, dedup
 * ITEM_ALREADY_RECEIVING, guard version/permission, và middleware BOX cho COMPONENT.
 * Không sửa code sản xuất trừ khi phát hiện bug thật trong lúc viết test (xem
 * WWHN-198.md để biết kết quả).
 */
class Wwhn198InboundScanValidationTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** SKU PRODUCT serial-tracked, kèm document DRAFT có 1 line, sẵn sàng scan. */
    private function draftInboundWithProductLine(string $skuCode, int $qty = 1): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC WWHN-198',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => $qty,
            ]],
        ], $headers)->assertCreated();

        return [
            'headers' => $headers,
            'document_id' => (string) $create->json('data.id'),
            'line_id' => (string) $create->json('data.lines.0.id'),
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    /** SKU COMPONENT (quantity-mode), document DRAFT + BOX EMPTY đã đăng ký cho line. */
    private function draftInboundWithComponentBox(string $skuCode, int $qty = 5): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'Component WWHN-198',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC WWHN-198 Component',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => $qty,
            ]],
        ], $headers)->assertCreated();

        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        return [
            'headers' => $headers,
            'document_id' => $documentId,
            'line_id' => $lineId,
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    public function test_scan_requires_authentication(): void
    {
        // Không gọi bất kỳ helper nào login trước (kể cả draftInboundWithProductLine()) —
        // guard JWT giữ user đã login xuyên suốt các postJson() nội bộ cùng 1 test method
        // nếu không override Authorization, nên phải tự seed document/line qua DB trực tiếp
        // để cô lập hoàn toàn khỏi trạng thái auth (cùng cách Wwhn191BalanceMovementProjectionTest
        // ::test_stock_movements_endpoint_requires_authentication() đã làm).
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('IN-SCAN-001');
        $ownerUser = User::create([
            'username' => 'seed-'.Str::lower(Str::random(8)),
            'display_name' => 'Seed Owner',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $documentId = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_no' => 'IN-SCAN-001-'.Str::upper(Str::random(6)),
            'doc_type' => 'receipt',
            'doc_date' => now()->toDateString(),
            'dst_warehouse_id' => $warehouse->id,
            'status' => 'DRAFT',
            'version' => 1,
            'created_by' => $ownerUser->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lineId = (string) Str::uuid();
        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $sku->sku_id,
            'qty_planned' => 1,
            // chk_stock_document_line_integer_positive: qty_actual > 0.
            'qty_actual' => 1,
            'scanned_qty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
        ], ['If-Match' => '1'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_scan_requires_inbound_scan_permission(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-002');
        $approverHeaders = $this->authHeadersWithRole('WAREHOUSE_APPROVER');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $approverHeaders + ['If-Match' => '1']);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $response->assertJsonMissingPath('error.trace')->assertJsonMissingPath('error.stack');
        $this->assertSame(0, DB::table('inbound_scan_entries')->count());
    }

    public function test_scan_requires_if_match_header(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-003');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
    }

    public function test_scan_rejects_stale_version_with_412(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-004');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => '99']);

        $response->assertStatus(412)->assertJsonPath('error.code', 'PRECONDITION_FAILED');
        $this->assertDatabaseHas('stock_documents', ['id' => $ctx['document_id'], 'status' => 'DRAFT', 'version' => 1]);
    }

    public function test_scan_returns_404_for_unknown_document(): void
    {
        $headers = $this->superAdminHeaders();

        $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/scan', [
            'line_id' => (string) Str::uuid(),
            'quantity' => 1,
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_scan_rejects_unknown_line_id(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-005');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => (string) Str::uuid(),
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'DOCUMENT_LINE_NOT_FOUND');
    }

    public function test_scan_rejects_zero_quantity(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-006');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 0,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422);
    }

    public function test_scan_rejects_quantity_exceeding_line_planned_qty(): void
    {
        $ctx = $this->draftInboundWithComponentBox('IN-SCAN-007', 3);
        $tray = Container::create([
            'container_code' => 'BOX-IN-SCAN-007',
            'container_type' => 'BOX',
            'primary_sku_id' => $ctx['sku_id'],
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 4,
            'container_id' => $tray->container_id,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'SCAN_EXCEEDS_REQUIRED');
        $this->assertSame(0, (int) DB::table('stock_document_lines')->where('id', $ctx['line_id'])->value('scanned_qty'));
    }

    public function test_scan_rejects_inactive_sku(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-008');
        DB::table('sku')->where('sku_id', $ctx['sku_id'])->update(['status' => 'INACTIVE']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_NOT_ACTIVE');
    }

    public function test_scan_product_line_without_item_is_rejected(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-009');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_REQUIRED');
    }

    public function test_scan_serial_tracked_line_rejects_quantity_greater_than_one(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-010', 2);
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 2,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SERIAL_QUANTITY_MUST_BE_ONE');
    }

    public function test_scan_rejects_item_belonging_to_different_sku(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-011');
        $otherSku = $this->productSkuFixture('IN-SCAN-011-OTHER');
        $foreignItemId = $this->seedItemInStock($otherSku->sku_id);
        DB::table('item_instances')->where('item_id', $foreignItemId)->update(['item_status' => 'NEW']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $foreignItemId,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_SKU_MISMATCH');
    }

    public function test_scan_rejects_item_not_receivable_when_already_in_stock(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-012');
        // seedItemInStock() tạo item với item_status=IN_STOCK — không thuộc NEW/ADJUSTED_OUT,
        // tức item đã "sống" trong tồn kho, không hợp lệ để nhận lại qua inbound scan.
        $itemId = $this->seedItemInStock($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_NOT_RECEIVABLE');
    }

    public function test_scan_with_unknown_code_value_is_rejected(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-013');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => 'QR-DOES-NOT-EXIST',
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
        $this->assertSame(0, DB::table('inbound_scan_entries')->count());
    }

    public function test_scan_with_inactive_code_value_is_rejected(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-014');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);
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

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => $codeValue,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
    }

    public function test_scan_rejects_mismatched_item_id_and_code_value(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-015');
        $itemA = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemA)->update(['item_status' => 'NEW']);
        $itemB = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemB)->update(['item_status' => 'NEW']);
        $codeForB = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemB, $codeForB);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemA,
            'code_value' => $codeForB,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_ITEM_MISMATCH');
        $this->assertSame(0, DB::table('inbound_scan_entries')->count());
    }

    public function test_scan_code_value_resolves_item_and_records_scan_entry(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-016');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemId, $codeValue);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => $codeValue,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertOk()
            ->assertJsonPath('data.item_id', $itemId)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $this->assertDatabaseHas('inbound_scan_entries', ['inbound_line_id' => $ctx['line_id'], 'item_id' => $itemId]);
    }

    public function test_scanning_same_item_twice_into_same_inbound_document_is_rejected(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-017', 2);
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '2']);

        $response->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_ITEM_IN_DOCUMENT');
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('item_id', $itemId)->count());
    }

    public function test_scan_rejects_document_not_in_draft_or_scanning_state(): void
    {
        // Dùng SKU PRODUCT (không qua middleware EnsureInboundComponentBox) để cô lập
        // đúng guard STATE_CONFLICT ở tầng controller/service — nếu dùng COMPONENT+BOX,
        // sau Post Receipt box chuyển RECEIVED_PENDING_PUTAWAY (không còn EMPTY) nên
        // middleware sẽ chặn sớm hơn bằng BOX_NOT_RECEIVABLE, không phản ánh đúng guard
        // đang muốn xác nhận ở đây.
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-018');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt", [], $ctx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'in-scan-018-post',
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $secondItemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $secondItemId)->update(['item_status' => 'NEW']);
        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $secondItemId,
        ], $ctx['headers'] + ['If-Match' => '3']);

        $response->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    public function test_scan_rejects_component_line_without_box(): void
    {
        $ctx = $this->draftInboundWithComponentBox('IN-SCAN-019', 5);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_BOX_REQUIRED');
    }

    public function test_scan_rejects_unknown_box_container_id(): void
    {
        $ctx = $this->draftInboundWithComponentBox('IN-SCAN-020', 5);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
            'container_id' => (string) Str::uuid(),
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_BOX_NOT_FOUND');
    }

    public function test_scan_rejects_box_with_mismatched_sku(): void
    {
        $ctx = $this->draftInboundWithComponentBox('IN-SCAN-021', 5);
        $otherSku = Sku::create([
            'sku_code' => 'IN-SCAN-021-OTHER',
            'sku_name' => 'Other component',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-IN-SCAN-021',
            'container_type' => 'BOX',
            'primary_sku_id' => $otherSku->sku_id,
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
            'container_id' => $box->container_id,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BOX_SKU_MISMATCH');
    }

    public function test_scan_rejects_box_not_in_receivable_state(): void
    {
        $ctx = $this->draftInboundWithComponentBox('IN-SCAN-022', 5);
        $box = Container::create([
            'container_code' => 'BOX-IN-SCAN-022',
            'container_type' => 'BOX',
            'primary_sku_id' => $ctx['sku_id'],
            'status' => 'STORED',
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
            'container_id' => $box->container_id,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertStatus(409)->assertJsonPath('error.code', 'BOX_NOT_RECEIVABLE');
    }

    public function test_scan_rejects_box_already_used_by_other_active_receipt(): void
    {
        $ctxA = $this->draftInboundWithComponentBox('IN-SCAN-023', 5);
        $box = Container::create([
            'container_code' => 'BOX-IN-SCAN-023',
            'container_type' => 'BOX',
            'primary_sku_id' => $ctxA['sku_id'],
            'status' => 'EMPTY',
            'version' => 1,
        ]);
        $this->postJson("/api/v1/inbound-documents/{$ctxA['document_id']}/scan", [
            'line_id' => $ctxA['line_id'],
            'quantity' => 2,
            'container_id' => $box->container_id,
        ], $ctxA['headers'] + ['If-Match' => '1'])->assertOk();

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $ctxA['warehouse_id'],
            'source_name' => 'NCC WWHN-198 doc B',
            'lines' => [[
                'product_id' => $ctxA['sku_id'],
                'qty_planned' => 5,
            ]],
        ], $ctxA['headers'])->assertCreated();
        $lineB = (string) $create->json('data.lines.0.id');
        $documentB = (string) $create->json('data.id');

        $response = $this->postJson("/api/v1/inbound-documents/{$documentB}/scan", [
            'line_id' => $lineB,
            'quantity' => 1,
            'container_id' => $box->container_id,
        ], $ctxA['headers'] + ['If-Match' => '1']);

        $response->assertStatus(409)->assertJsonPath('error.code', 'BOX_IN_OTHER_ACTIVE_RECEIPT');
    }

    public function test_scanning_same_item_into_two_different_inbound_documents_is_rejected(): void
    {
        $ctxA = $this->draftInboundWithProductLine('IN-SCAN-024-A');
        $itemId = $this->seedItemInStock($ctxA['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);

        $this->postJson("/api/v1/inbound-documents/{$ctxA['document_id']}/scan", [
            'line_id' => $ctxA['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctxA['headers'] + ['If-Match' => '1'])->assertOk();

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $ctxA['warehouse_id'],
            'source_name' => 'NCC WWHN-198 doc B',
            'lines' => [[
                'product_id' => $ctxA['sku_id'],
                'qty_planned' => 1,
            ]],
        ], $ctxA['headers'])->assertCreated();
        $lineB = (string) $create->json('data.lines.0.id');
        $documentB = (string) $create->json('data.id');

        $response = $this->postJson("/api/v1/inbound-documents/{$documentB}/scan", [
            'line_id' => $lineB,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctxA['headers'] + ['If-Match' => '1']);

        $response->assertStatus(409)->assertJsonPath('error.code', 'ITEM_ALREADY_RECEIVING');
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('item_id', $itemId)->count());
    }

    public function test_scan_document_transitions_draft_to_scanning_and_bumps_version(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-025');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2)
            ->assertHeader('ETag', '"2"');
        $this->assertDatabaseHas('stock_documents', ['id' => $ctx['document_id'], 'status' => 'SCANNING', 'version' => 2]);
    }

    public function test_scan_writes_scan_event_evidence_row(): void
    {
        $ctx = $this->draftInboundWithProductLine('IN-SCAN-026');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $this->assertSame(1, DB::table('scan_event as se')
            ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
            ->where('sb.source_id', $ctx['document_id'])
            ->where('se.resolved_object_id', $itemId)
            ->where('se.resolved_object_type', 'ITEM')
            ->count());
    }
}
