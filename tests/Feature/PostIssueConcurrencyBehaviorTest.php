<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195: đối xứng với PostReceiptConcurrencyBehaviorTest cho luồng Post Issue.
 * Lái HTTP contract thật để chứng minh optimistic lock (If-Match/version) và
 * document-state guard (STATE_CONFLICT) thực sự chặn racer thứ hai chứ không chỉ
 * assert chuỗi nguồn tồn tại như OutboundDocsAlignmentContractTest.
 *
 * Race thật ở mức 2-connection DB lock không khả thi trên sqlite ":memory:" (một
 * connection duy nhất mỗi process) — races được mô phỏng bằng cách giữ version/state
 * cũ rồi replay request thứ hai, đúng như những gì một racer thua cuộc quan sát được
 * sau khi winner đã commit trước.
 */
class PostIssueConcurrencyBehaviorTest extends TestCase
{
    use RefreshDatabase;
    use PreparesOutboundFixtures;

    public function test_stale_version_racer_is_rejected_after_winner_commits_and_stock_effect_applies_once(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-RACE-ISSUE-VERSION-001');
        $documentId = $doc['document_id'];

        $movementBefore = DB::table('stock_movements')->count();
        $balanceBefore = (int) (DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->value('available_qty') ?? 0);

        $winnerHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'issue-race-winner-001'];
        $winner = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $winnerHeaders);
        $winner->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.version', 3);

        // Loser đọc document trước khi winner commit, vẫn giữ If-Match: 2 (stale).
        $loserHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'issue-race-loser-001'];
        $loser = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $loserHeaders);
        $loser->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');

        $movementAfter = DB::table('stock_movements')->count();
        $this->assertSame($movementBefore + 1, $movementAfter, 'Racer thua không được tạo thêm stock_movement thứ hai.');

        $balanceAfter = (int) DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->value('available_qty');
        $this->assertSame($balanceBefore - 1, $balanceAfter, 'Stock effect chỉ được áp dụng đúng một lần dù có race.');

        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'POSTED', 'version' => 3]);
    }

    /**
     * Racer gửi lại If-Match hiện hành (vd client retry sau khi re-fetch document đã
     * POSTED) vẫn phải bị document-state guard (STATE_CONFLICT) chặn — chứng minh guard
     * là defense-in-depth, không chỉ dựa vào optimistic locking.
     */
    public function test_repost_with_current_version_after_already_posted_is_rejected_by_state_guard(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-RACE-ISSUE-STATE-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'issue-race-state-winner-001',
        ])->assertOk()->assertJsonPath('data.version', 3);

        $movementAfterFirstPost = DB::table('stock_movements')->count();

        // Idempotency-Key khác => không phải replay; phải re-evaluate và bị từ chối theo state.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'issue-race-state-second-writer-001',
        ])->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        $this->assertSame(
            $movementAfterFirstPost,
            DB::table('stock_movements')->count(),
            'Writer thứ hai bị từ chối không được tạo thêm stock_movement.'
        );
    }

    /**
     * business_key = sha1(ISSUE|document_id|line_id) là last line of defense chống
     * double stock effect trong mutateBalance() ngay cả khi hai writer vượt qua được
     * document-level lock. Verify bằng cách kiểm tra tính duy nhất business_key sau một
     * lần post bình thường — đây chính là invariant mà DUPLICATE_STOCK_EFFECT dựa vào.
     */
    public function test_stock_movement_business_key_is_unique_per_document_line_after_post(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'COMP-RACE-ISSUE-BKEY-001');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'issue-race-bkey-001',
        ])->assertOk();

        $businessKeys = DB::table('stock_movements')->where('stock_document_id', $documentId)->pluck('business_key');
        $this->assertCount(1, $businessKeys);
        $this->assertSame(
            sha1('ISSUE|'.$documentId.'|'.DB::table('stock_document_lines')->where('stock_document_id', $documentId)->value('id')),
            $businessKeys->first()
        );

        $this->assertSame(
            1,
            DB::table('stock_movements')->where('business_key', $businessKeys->first())->count(),
            'business_key phải duy nhất — đây là điều DUPLICATE_STOCK_EFFECT bảo vệ trước racing writer.'
        );
    }

    public function test_scan_stale_version_is_rejected_by_optimistic_lock(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('COMP-RACE-ISSUE-SCAN-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-RACE-SCAN-001');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');
        $itemId = $this->seedItemInStock($sku->sku_id);

        // Winner scan trước với If-Match đúng (version=1), document -> SCANNING/version=2.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk()->assertJsonPath('data.version', 2);

        // Racer thua gửi lại If-Match=1 (stale) — phải bị optimistic lock chặn.
        $loser = $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1']);
        $loser->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');

        $this->assertSame(1, DB::table('outbound_item_matches')->where('item_id', $itemId)->count());
    }
}
