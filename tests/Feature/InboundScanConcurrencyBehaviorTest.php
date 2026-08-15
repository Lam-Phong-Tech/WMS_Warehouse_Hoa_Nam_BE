<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-187 (HN-IN-002, BE1): rà soát giao dịch/khóa đồng thời cho bước
 * inbound scan (`POST /inbound-documents/{id}/scan`).
 *
 * `Wwhn198InboundScanValidationTest::test_scan_rejects_stale_version_with_412`
 * chỉ gửi `If-Match` sai tĩnh (giá trị 99), không mô phỏng race thật giữa 2
 * writer cùng đọc version rồi 1 bên thắng trước. `Wwhn199ReservationConcurrencyGuardTest`
 * cover guard trùng item (`ITEM_ALREADY_RECEIVING`) nhưng không cover optimistic
 * version guard của chính request scan. File này lấp gap đó: 2 writer cùng đọc
 * document ở version=1, writer A scan line 1 trước và thắng (bump version=2),
 * writer B replay version=1 cũ vào line 2 phải bị 412 PRECONDITION_FAILED —
 * đúng hành vi 1 racer thua cuộc sẽ quan sát được khi 2 scan đến gần như đồng
 * thời trên cùng document (test suite dùng SQLite single-connection nên mô
 * phỏng bằng cách replay tuần tự request của writer thua, không phải race
 * thật đa tiến trình — cùng cách tiếp cận đã dùng ở PostReceiptConcurrencyBehaviorTest).
 */
class InboundScanConcurrencyBehaviorTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** item_instances.item_status=NEW — receivable state required by assertItemAvailableForReceipt(). */
    private function seedReceivableItem(string $skuId): string
    {
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'serial_number' => 'SN-'.Str::upper(Str::random(8)),
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }

    private function draftInboundWithTwoProductLines(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $skuA = $this->productSkuFixture($skuCode.'-A');
        $skuB = $this->productSkuFixture($skuCode.'-B');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC WWHN-187 Race',
            'lines' => [
                ['product_id' => $skuA->sku_id, 'qty_planned' => 1],
                ['product_id' => $skuB->sku_id, 'qty_planned' => 1],
            ],
        ], $headers)->assertCreated();

        return [
            'headers' => $headers,
            'document_id' => (string) $create->json('data.id'),
            'line_a_id' => (string) $create->json('data.lines.0.id'),
            'line_b_id' => (string) $create->json('data.lines.1.id'),
            'sku_a_id' => $skuA->sku_id,
            'sku_b_id' => $skuB->sku_id,
        ];
    }

    /**
     * Two writers both read the freshly-created document at version=1.
     * Writer A scans line 1 first and wins, bumping the document to version=2.
     * Writer B replays its stale version=1 If-Match against line 2: the
     * optimistic lock (assertVersion -> PRECONDITION_FAILED) must reject it
     * even though line 2 itself was never touched by A, and B's line must
     * show zero scanned_qty / zero inbound_scan_entries afterwards.
     */
    public function test_stale_version_racer_is_rejected_after_winner_scan_commits(): void
    {
        $ctx = $this->draftInboundWithTwoProductLines('WWHN187-SCAN-RACE-001');
        $itemA = $this->seedReceivableItem($ctx['sku_a_id']);
        $itemB = $this->seedReceivableItem($ctx['sku_b_id']);

        $entriesBefore = DB::table('inbound_scan_entries')->count();

        $winner = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_a_id'],
            'quantity' => 1,
            'item_id' => $itemA,
        ], $ctx['headers'] + ['If-Match' => '1']);
        $winner->assertOk()->assertJsonPath('data.version', 2);

        // Loser read the document before the winner committed and still holds If-Match: 1.
        $loser = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_b_id'],
            'quantity' => 1,
            'item_id' => $itemB,
        ], $ctx['headers'] + ['If-Match' => '1']);
        $loser->assertStatus(412)->assertJsonPath('error.code', 'PRECONDITION_FAILED');

        $this->assertSame(
            $entriesBefore + 1,
            DB::table('inbound_scan_entries')->count(),
            'Losing racer must not create an inbound_scan_entries row for line B.'
        );
        $this->assertDatabaseHas('stock_document_lines', ['id' => $ctx['line_b_id'], 'scanned_qty' => 0]);
        $this->assertDatabaseHas('stock_documents', ['id' => $ctx['document_id'], 'version' => 2]);

        // Loser retries with the current version and succeeds — proving the
        // guard only blocks the stale read, not line B itself.
        $retry = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_b_id'],
            'quantity' => 1,
            'item_id' => $itemB,
        ], $ctx['headers'] + ['If-Match' => '2']);
        $retry->assertOk()->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('stock_document_lines', ['id' => $ctx['line_b_id'], 'scanned_qty' => 1]);
    }

    /**
     * A racer who resends the *current* version (e.g. re-fetched the document
     * after the winner's scan already flipped it to SCANNING/moved it out of
     * DRAFT) but targets a document that has since been fully posted must
     * still be rejected by the document-state guard, not just the version
     * check — proving the scan guard is defense-in-depth like post-receipt.
     */
    public function test_scan_with_current_version_on_posted_document_is_rejected_by_state_guard(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN187-SCAN-STATE-001');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC WWHN-187 State',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');
        $itemId = $this->seedReceivableItem($sku->sku_id);

        $scan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1']);
        $scan->assertOk()->assertJsonPath('data.version', 2);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn187-scan-state-post-'.Str::lower(Str::random(8)),
        ])->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.version', 3);

        $entriesAfterPost = DB::table('inbound_scan_entries')->count();

        // Fresh version=3 (the true current version), but the document is
        // POSTED — scanning a POSTED document must be rejected on state, not
        // silently accepted just because the version header matches.
        $rescan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '3']);
        $rescan->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        $this->assertSame(
            $entriesAfterPost,
            DB::table('inbound_scan_entries')->count(),
            'Rejected scan against a POSTED document must not create an additional scan entry.'
        );
    }
}
