<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195: drives the real outbound HTTP contract end-to-end — create-from-request,
 * scan bằng cả hai chế độ (item_id thủ công và code_value quét mã), label PRINTED
 * bắt buộc, Post Issue atomic, và Reverse — thay cho OutboundDocsAlignmentContractTest
 * vốn chỉ assert chuỗi nguồn tồn tại chứ không lái request thật qua HTTP.
 */
class OutboundDocumentFlowTest extends TestCase
{
    use RefreshDatabase;
    use PreparesOutboundFixtures;

    public function test_full_outbound_flow_manual_and_scan_mode_then_post_issue_and_reverse(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-FLOW-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'TOA-FLOW-001');

        // 1. Tạo outbound document từ toa — lines bị hydrate tự động, không nhận lines thủ công.
        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers);
        $create->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');
        $this->assertSame(2, (int) $create->json('data.lines.0.qty_planned'));

        // Toa phải chuyển CONSUMED ngay khi document được tạo (ERD bảng 37).
        $this->assertDatabaseHas('outbound_requests', ['id' => $requestId, 'status' => 'CONSUMED']);

        // 2. Chế độ "nhập tay" — gửi thẳng item_id.
        $manualItemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($manualItemId);
        $manualScan = $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $manualItemId,
        ], $headers + ['If-Match' => '1']);
        $manualScan->assertOk()->assertJsonPath('data.item_id', $manualItemId);

        // 3. Chế độ "quét mã" — gửi code_value, server tự resolve ra item.
        $scanItemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($scanItemId);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($scanItemId, $codeValue);
        $codeScan = $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'code_value' => $codeValue,
        ], $headers + ['If-Match' => '2']);
        $codeScan->assertOk()->assertJsonPath('data.item_id', $scanItemId);
        $this->assertSame(2, (int) $codeScan->json('data.scanned_quantity'));

        $document = DB::table('stock_documents')->where('id', $documentId)->first();
        $this->assertSame('SCANNING', $document->status);
        $this->assertSame(3, (int) $document->version);

        // 4. Post Issue phải bị chặn nếu chưa đủ label PRINTED cho mọi PRODUCT item.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'post-issue-flow-001',
        ])->assertStatus(422)->assertJsonPath('error.code', 'LABEL_NOT_PRINTED');

        $this->markLabelsPrinted($documentId);

        // 5. Post Issue thành công — stock effect DECREASE, item ISSUED, match POSTED.
        $post = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'post-issue-flow-001',
        ]);
        $post->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.stock_effect', 'DECREASE')
            ->assertHeader('Idempotent-Replay', 'false');

        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'POSTED']);
        $this->assertDatabaseHas('item_instances', ['item_id' => $manualItemId, 'item_status' => 'ISSUED']);
        $this->assertDatabaseHas('item_instances', ['item_id' => $scanItemId, 'item_status' => 'ISSUED']);
        $this->assertDatabaseHas('outbound_item_matches', ['item_id' => $manualItemId, 'status' => 'POSTED']);

        $balanceAfterPost = (int) DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('available_qty');
        $this->assertSame(0, $balanceAfterPost);

        // Idempotent replay không được double-apply stock effect.
        $replay = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'post-issue-flow-001',
        ]);
        $replay->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('available_qty'));

        // 6. Reverse — trả hàng SELLABLE, item quay lại IN_STOCK.
        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'ISSUE')
            ->value('id');
        $reverse = $this->postJson("/api/v1/outbound-documents/{$documentId}/reverse", [
            'reason' => 'Khách trả hàng',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => true,
            'lines' => [
                ['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $manualItemId],
                ['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $scanItemId],
            ],
        ], $headers + [
            'If-Match' => '4',
            'Idempotency-Key' => 'reverse-flow-001',
        ]);
        $reverse->assertOk()->assertJsonPath('data.original_document_status', 'POSTED');

        $this->assertDatabaseHas('item_instances', ['item_id' => $manualItemId, 'item_status' => 'IN_STOCK']);
        $balanceAfterReverse = (int) DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('available_qty');
        $this->assertSame(2, $balanceAfterReverse);
    }

    public function test_store_outbound_rejects_manual_lines_payload(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-FLOW-002');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-FLOW-002');

        $response = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.details.lines.0', fn ($msg) => is_string($msg));
    }

    public function test_store_outbound_rejects_already_consumed_request(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-FLOW-003');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-FLOW-003');

        $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();

        $second = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $this->warehouseFixture('KHO-B')->id,
            'outbound_request_id' => $requestId,
        ], $headers);

        $second->assertStatus(409)->assertJsonPath('error.code', 'OUTBOUND_REQUEST_ALREADY_USED');
    }

    public function test_scan_rejects_item_without_sufficient_available_stock(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-FLOW-004');
        // Cố ý không seed balance AVAILABLE.
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-FLOW-004');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');
        $itemId = $this->seedItemInStock($sku->sku_id);

        $scan = $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1']);

        $scan->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
    }

    public function test_unmatch_removes_active_match_before_post_and_is_scan_neutral(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-FLOW-005');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-FLOW-005');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');
        $itemId = $this->seedItemInStock($sku->sku_id);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $matchId = (string) DB::table('outbound_item_matches')->where('item_id', $itemId)->value('id');
        $unmatch = $this->postJson("/api/v1/outbound-documents/{$documentId}/unmatch", [
            'outbound_item_match_id' => $matchId,
            'reason' => 'Scan nhầm item',
        ], $headers + ['If-Match' => '2']);

        $unmatch->assertOk()->assertJsonPath('data.stock_effect', 'NONE')->assertJsonPath('data.scanned_quantity', 0);
        $this->assertDatabaseHas('outbound_item_matches', ['id' => $matchId, 'status' => 'REMOVED']);
        // Item vẫn IN_STOCK — unmatch không tạo stock effect.
        $this->assertDatabaseHas('item_instances', ['item_id' => $itemId, 'item_status' => 'IN_STOCK']);
    }
}
