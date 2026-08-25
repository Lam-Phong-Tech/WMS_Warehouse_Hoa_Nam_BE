<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
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
    use BuildsScanDrivenInbound;
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
        $docA = $this->outboundDocumentFixture((string) $warehouse->id, $requestA);
        $documentAId = $docA['document_id'];
        $lineAId = $docA['line_ids'][0];

        $requestB = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RESV-B-001');
        $docB = $this->outboundDocumentFixture((string) $warehouse->id, $requestB);
        $documentBId = $docB['document_id'];
        $lineBId = $docB['line_ids'][0];

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

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];

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
     * Đối xứng phía inbound (contract GĐ1 scan-driven): assertCandidateAvailable() chặn
     * cùng 1 item vật lý (composite QR HN1|SKU|ITEM, candidate chưa register) bị scan
     * vào 2 phiếu nhập đang mở cùng lúc — winner giữ candidate, loser nhận
     * ITEM_ALREADY_RECEIVING. (Item đã có sẵn trong item_instances thì thuộc guard khác:
     * ITEM_ALREADY_REGISTERED, cover ở Gd1ScanDrivenInboundContractTest.)
     */
    public function test_scanning_same_item_into_two_different_inbound_documents_is_rejected(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('PROD-RESV-003');
        $itemUnique = 'SN-RESV-003';

        $docA = $this->createInboundHeader($headers, (string) $warehouse->id);
        $docB = $this->createInboundHeader($headers, (string) $warehouse->id);

        $this->postJson("/api/v1/inbound-documents/{$docA['document_id']}/scans", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('PROD-RESV-003', $itemUnique),
        ], $headers + ['If-Match' => (string) $docA['version']])->assertOk();

        $loser = $this->postJson("/api/v1/inbound-documents/{$docB['document_id']}/scans", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('PROD-RESV-003', $itemUnique),
        ], $headers + ['If-Match' => (string) $docB['version']]);
        $loser->assertStatus(409)->assertJsonPath('error.code', 'ITEM_ALREADY_RECEIVING');

        $this->assertSame(
            1,
            DB::table('inbound_scan_entries')->where('candidate_item_unique', $itemUnique)->count(),
            'Candidate chỉ được ghi nhận đúng 1 lần dù 2 phiếu cùng cố scan.'
        );
        $this->assertSame(
            0,
            (int) DB::table('stock_document_lines')
                ->where('stock_document_id', $docB['document_id'])
                ->sum('scanned_qty'),
            'Phiếu thua cuộc không được tăng scanned_qty khi bị chặn.'
        );
    }

    /**
     * hydrateOutboundRequest() lockForUpdate() trên chính row outbound_requests trước
     * khi kiểm tra "đã có document active chưa" — race giữa 2 request tạo document từ
     * cùng toa được tuần tự hóa bởi row lock đó. OutboundDocumentFlowTest đã có 1 test
     * tuần tự cho OUTBOUND_REQUEST_ALREADY_USED; bổ sung ở đây theo đúng semantics
     * "winner tạo trước, loser bị chặn", và xác nhận trạng thái toa/documents cuối cùng
     * nhất quán (không có 2 document active cho cùng 1 toa dưới bất kỳ hoàn cảnh nào).
     */
    public function test_confirming_a_duplicate_request_no_never_creates_a_second_outbound_document(): void
    {
        $headers = $this->superAdminHeaders();
        $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESV-004');

        $row = [
            'request_no' => 'TOA-RESV-004',
            'customer_name' => 'Đại lý test',
            'sku_id' => $sku->sku_id,
            'required_qty' => 1,
        ];

        $first = $this->postJson('/api/v1/outbound-request-imports', ['rows' => [$row]], $headers);
        $first->assertCreated()->assertJsonPath('data.import.status', 'VALIDATED');
        $firstImportId = (string) $first->json('data.import.id');
        $this->postJson("/api/v1/outbound-request-imports/{$firstImportId}/confirm", [], $headers + [
            'If-Match' => (string) ((int) $first->json('data.import.version')),
            'Idempotency-Key' => 'confirm-resv-004-1',
        ])->assertOk()->assertJsonCount(1, 'data.outbound_document_ids');

        // Toa thứ hai cùng số toa (checksum khác vì thêm ghi chú) phải bị chặn.
        $second = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [$row + ['note' => 'phát hành lại']],
        ], $headers);
        $second->assertCreated()->assertJsonPath('data.import.status', 'REJECTED');
        $secondImportId = (string) $second->json('data.import.id');
        $this->postJson("/api/v1/outbound-request-imports/{$secondImportId}/confirm", [], $headers + [
            'If-Match' => (string) ((int) $second->json('data.import.version')),
            'Idempotency-Key' => 'confirm-resv-004-2',
        ])->assertStatus(409)->assertJsonPath('error.code', 'IMPORT_HAS_BLOCKING_ERRORS');

        $this->assertSame(
            1,
            DB::table('outbound_requests')->where('request_no', 'TOA-RESV-004')->count(),
            'Số toa là duy nhất — không được tạo toa thứ hai.'
        );
        $this->assertSame(
            1,
            DB::table('stock_documents')->where('doc_type', 'issue')->count(),
            'Toa chỉ được có đúng 1 phiếu xuất.'
        );
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
        $docA = $this->outboundDocumentFixture((string) $warehouse->id, $requestA);
        $documentAId = $docA['document_id'];
        $lineAId = $docA['line_ids'][0];

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
        $docB = $this->outboundDocumentFixture((string) $warehouse->id, $requestB);
        $documentBId = $docB['document_id'];
        $lineBId = $docB['line_ids'][0];

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
