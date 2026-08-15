<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-199/WWHN-46 (HN-OUT-002): rà soát khóa đồng thời/quy tắc reservation cho
 * outbound — cụ thể là "chống ghi nhận trùng" khi quét mã (một item vật lý không
 * được match/nhận vào 2 phiếu khác nhau cùng lúc) và "một toa chỉ sinh một phiếu".
 *
 * Các guard này (ITEM_ALREADY_MATCHED, DUPLICATE_ITEM_MATCH, ITEM_ALREADY_RECEIVING,
 * OUTBOUND_REQUEST_ALREADY_USED) đã tồn tại trong CurrentBaselineStockService /
 * CurrentBaselineDocumentController từ trước (xem assertItemAvailableForIssue,
 * recordOutboundMatch, assertItemAvailableForReceipt, hydrateOutboundRequest) nhưng
 * chưa từng được lái qua HTTP thật — OutboundDocumentFlowTest/PostIssueConcurrencyBehaviorTest
 * chỉ cover version/state/business_key. Đây là gap coverage chính mà rà soát này lấp lại.
 */
class Wwhn199ReservationConcurrencyGuardTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /**
     * item_instances.item_id là row-lock target dùng chung bởi resolveItem() ở cả
     * inbound lẫn outbound scan; vì mọi scan cho cùng 1 item phải lấy được lock này
     * trước khi kiểm tra/ghi outbound_item_matches, hai request quét trùng item vào
     * 2 phiếu xuất khác nhau bị tuần tự hóa bởi chính DB row lock — request thắng
     * commit trước, request thua đọc lại thấy match ACTIVE của winner và bị chặn.
     */
    public function test_scanning_same_item_into_two_different_outbound_documents_is_rejected(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESV-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $itemId = $this->seedItemInStock($sku->sku_id);

        $requestA = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RESV-A-001');
        $docA = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestA,
        ], $headers)->assertCreated();
        $documentAId = (string) $docA->json('data.id');
        $lineAId = (string) $docA->json('data.lines.0.id');

        $requestB = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RESV-B-001');
        $docB = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestB,
        ], $headers)->assertCreated();
        $documentBId = (string) $docB->json('data.id');
        $lineBId = (string) $docB->json('data.lines.0.id');

        // Winner: item được match thành công vào phiếu A.
        $this->postJson("/api/v1/outbound-documents/{$documentAId}/scan", [
            'line_id' => $lineAId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk()->assertJsonPath('data.item_id', $itemId);

        // Loser: cùng item, phiếu khác — phải bị chặn ITEM_ALREADY_MATCHED, không được ghi trùng.
        $loser = $this->postJson("/api/v1/outbound-documents/{$documentBId}/scan", [
            'line_id' => $lineBId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1']);
        $loser->assertStatus(409)->assertJsonPath('error.code', 'ITEM_ALREADY_MATCHED');

        $activeMatches = DB::table('outbound_item_matches')
            ->where('item_id', $itemId)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
            })
            ->count();
        $this->assertSame(1, $activeMatches, 'Item chỉ được có đúng 1 match ACTIVE dù có 2 phiếu cùng cố match.');
        $this->assertSame(
            0,
            DB::table('stock_document_lines')->where('id', $lineBId)->value('scanned_qty'),
            'Line thua cuộc không được tăng scanned_qty khi bị chặn.'
        );
    }

    /**
     * assertNoDuplicateItemInDocument() (controller) và recordOutboundMatch() (service)
     * là 2 lớp chặn độc lập cho trường hợp cùng 1 item bị scan lặp lại 2 lần vào chính
     * cùng 1 document — phân biệt với ITEM_ALREADY_MATCHED (khác document).
     */
    public function test_scanning_same_item_twice_into_same_document_is_rejected(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESV-002');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $itemId = $this->seedItemInStock($sku->sku_id);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'TOA-RESV-002');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $duplicate = $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '2']);
        $duplicate->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_ITEM_IN_DOCUMENT');

        $this->assertSame(1, DB::table('outbound_item_matches')->where('item_id', $itemId)->count());
        $this->assertSame(1, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
    }

    /**
     * Đối xứng phía inbound: assertItemAvailableForReceipt() chặn cùng 1 item vật lý
     * (item_status NEW/ADJUSTED_OUT) bị scan vào 2 phiếu nhập đang mở cùng lúc — cùng
     * cơ chế row-lock trên item_instances như phía outbound, chưa từng có test HTTP.
     */
    public function test_scanning_same_item_into_two_different_inbound_documents_is_rejected(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESV-003');

        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SN-RESV-003',
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $docA = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers)->assertCreated();
        $documentAId = (string) $docA->json('data.id');
        $lineAId = (string) $docA->json('data.lines.0.id');

        $docB = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers)->assertCreated();
        $documentBId = (string) $docB->json('data.id');
        $lineBId = (string) $docB->json('data.lines.0.id');

        $this->postJson("/api/v1/inbound-documents/{$documentAId}/scan", [
            'line_id' => $lineAId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $loser = $this->postJson("/api/v1/inbound-documents/{$documentBId}/scan", [
            'line_id' => $lineBId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1']);
        $loser->assertStatus(409)->assertJsonPath('error.code', 'ITEM_ALREADY_RECEIVING');

        $this->assertSame(1, DB::table('inbound_scan_entries')->where('item_id', $itemId)->count());
        $this->assertSame(0, (int) DB::table('stock_document_lines')->where('id', $lineBId)->value('scanned_qty'));
    }

    /**
     * hydrateOutboundRequest() lockForUpdate() trên chính row outbound_requests trước
     * khi kiểm tra "đã có document active chưa" — race giữa 2 request tạo document từ
     * cùng toa được tuần tự hóa bởi row lock đó. OutboundDocumentFlowTest đã có 1 test
     * tuần tự cho OUTBOUND_REQUEST_ALREADY_USED; bổ sung ở đây theo đúng semantics
     * "winner tạo trước, loser đọc version cũ" giống PostIssueConcurrencyBehaviorTest,
     * và xác nhận trạng thái toa/documents cuối cùng nhất quán (không có 2 document
     * active cho cùng 1 toa dưới bất kỳ hoàn cảnh nào).
     */
    public function test_creating_outbound_document_from_already_consumed_request_leaves_single_active_document(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESV-004');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RESV-004');

        $winner = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers);
        $winner->assertCreated();
        $winnerDocumentId = (string) $winner->json('data.id');

        $loser = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $this->warehouseFixture('KHO-RESV-004-B')->id,
            'outbound_request_id' => $requestId,
        ], $headers);
        $loser->assertStatus(409)->assertJsonPath('error.code', 'OUTBOUND_REQUEST_ALREADY_USED');

        $activeDocuments = DB::table('stock_documents')
            ->where('outbound_request_id', $requestId)
            ->whereIn('status', ['DRAFT', 'SCANNING', 'POSTED'])
            ->count();
        $this->assertSame(1, $activeDocuments, 'Toa chỉ được có đúng 1 document active dù có 2 request tạo cùng lúc.');
        $this->assertDatabaseHas('stock_documents', ['id' => $winnerDocumentId, 'outbound_request_id' => $requestId]);
        $this->assertDatabaseHas('outbound_requests', ['id' => $requestId, 'status' => 'CONSUMED']);
    }

    /**
     * outbound_item_matches.status có thể REMOVED (gỡ trước Post) — item được gỡ khỏi
     * phiếu A phải match được lại vào phiếu B, xác nhận ITEM_ALREADY_MATCHED chỉ chặn
     * match ACTIVE/đang hiệu lực chứ không khóa vĩnh viễn item sau khi unmatch.
     */
    public function test_item_removed_from_one_document_can_be_matched_into_another(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESV-005');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $itemId = $this->seedItemInStock($sku->sku_id);

        $requestA = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RESV-A-005');
        $docA = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestA,
        ], $headers)->assertCreated();
        $documentAId = (string) $docA->json('data.id');
        $lineAId = (string) $docA->json('data.lines.0.id');

        $scanA = $this->postJson("/api/v1/outbound-documents/{$documentAId}/scan", [
            'line_id' => $lineAId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();
        $matchId = DB::table('outbound_item_matches')->where('item_id', $itemId)->value('id');

        $this->postJson("/api/v1/outbound-documents/{$documentAId}/unmatch", [
            'outbound_item_match_id' => $matchId,
        ], $headers + ['If-Match' => (string) $scanA->json('data.version')])->assertOk()->assertJsonPath('data.status', 'REMOVED');

        $requestB = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RESV-B-005');
        $docB = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestB,
        ], $headers)->assertCreated();
        $documentBId = (string) $docB->json('data.id');
        $lineBId = (string) $docB->json('data.lines.0.id');

        $this->postJson("/api/v1/outbound-documents/{$documentBId}/scan", [
            'line_id' => $lineBId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk()->assertJsonPath('data.item_id', $itemId);

        $this->assertSame(
            1,
            DB::table('outbound_item_matches')->where('item_id', $itemId)->where('status', 'ACTIVE')->count(),
            'Sau khi unmatch khỏi A và match lại vào B, chỉ có đúng 1 match ACTIVE.'
        );
    }
}
