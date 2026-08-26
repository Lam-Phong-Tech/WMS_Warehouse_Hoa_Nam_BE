<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-188 (HN-IN-002, BE1): rà soát giao dịch/khóa đồng thời cho bước
 * inbound scan (`POST /inbound-documents/{id}/scan`).
 *
 * 2026-08-25 — fixture chuyển sang hợp đồng GĐ1 scan-driven: phiếu header-only,
 * item nhận qua composite QR new-item-candidate (dòng SKU materialize theo
 * scan). Cơ chế race giữ nguyên bản gốc: 2 writer cùng đọc version rồi 1 bên
 * thắng trước — test suite dùng SQLite single-connection nên mô phỏng bằng cách
 * replay tuần tự request của writer thua, không phải race thật đa tiến trình
 * (cùng cách tiếp cận PostReceiptConcurrencyBehaviorTest).
 */
class InboundScanConcurrencyBehaviorTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function scanQr(string $documentId, array $headers, string $skuCode, string $unique, int $ifMatch, ?string $key = null)
    {
        $extra = ['If-Match' => (string) $ifMatch];
        if ($key !== null) {
            $extra['Idempotency-Key'] = $key;
        }

        return $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr($skuCode, $unique),
        ], $headers + $extra);
    }

    /**
     * Two writers both read the freshly-created header-only document at
     * version=1. Writer A scans SKU A first and wins, bumping the document to
     * version=2. Writer B replays its stale version=1 If-Match with SKU B: the
     * optimistic lock (assertVersion -> STALE_VERSION) must reject it even
     * though SKU B was never touched by A — no line/entry may materialize for
     * the losing racer.
     */
    public function test_stale_version_racer_is_rejected_after_winner_scan_commits(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $skuA = $this->productSkuFixture('WWHN188-SCAN-RACE-001-A');
        $skuB = $this->productSkuFixture('WWHN188-SCAN-RACE-001-B');
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC WWHN-188 Race',
        ]);
        $documentId = $document['document_id'];

        $entriesBefore = DB::table('inbound_scan_entries')->count();

        $winner = $this->scanQr($documentId, $headers, 'WWHN188-SCAN-RACE-001-A', 'SN-RACE-A-0001', 1);
        $winner->assertOk()->assertJsonPath('data.version', 2);

        // Loser read the document before the winner committed and still holds If-Match: 1.
        $loser = $this->scanQr($documentId, $headers, 'WWHN188-SCAN-RACE-001-B', 'SN-RACE-B-0001', 1);
        $loser->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');

        $this->assertSame(
            $entriesBefore + 1,
            DB::table('inbound_scan_entries')->count(),
            'Losing racer must not create an inbound_scan_entries row for SKU B.'
        );
        // Scan-driven: dòng SKU B của racer thua phải rollback cùng transaction.
        $this->assertSame(
            0,
            DB::table('stock_document_lines')
                ->where('stock_document_id', $documentId)
                ->where('product_id', (string) $skuB->sku_id)
                ->count(),
            'Losing racer must not leave a materialized line for SKU B.'
        );
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'version' => 2]);

        // Loser retries with the current version and succeeds — proving the
        // guard only blocks the stale read, not SKU B itself.
        $retry = $this->scanQr($documentId, $headers, 'WWHN188-SCAN-RACE-001-B', 'SN-RACE-B-0001', 2);
        $retry->assertOk()->assertJsonPath('data.version', 3);
        $lineB = DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->where('product_id', (string) $skuB->sku_id)
            ->first();
        $this->assertNotNull($lineB);
        $this->assertSame(1, (int) $lineB->scanned_qty);
        $this->assertSame((string) $skuA->sku_id, (string) $winner->json('data.sku_id'));
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
        $this->productSkuFixture('WWHN188-SCAN-STATE-001');
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC WWHN-188 State',
        ]);
        $documentId = $document['document_id'];

        $scan = $this->scanQr($documentId, $headers, 'WWHN188-SCAN-STATE-001', 'SN-STATE-0001', 1);
        $scan->assertOk()->assertJsonPath('data.version', 2);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn188-scan-state-post-'.Str::lower(Str::random(8)),
        ])->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.version', 3);

        $entriesAfterPost = DB::table('inbound_scan_entries')->count();

        // Fresh version=3 (the true current version), but the document is
        // POSTED — scanning a POSTED document must be rejected on state, not
        // silently accepted just because the version header matches.
        $rescan = $this->scanQr($documentId, $headers, 'WWHN188-SCAN-STATE-001', 'SN-STATE-0002', 3);
        $rescan->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        $this->assertSame(
            $entriesAfterPost,
            DB::table('inbound_scan_entries')->count(),
            'Rejected scan against a POSTED document must not create an additional scan entry.'
        );
    }

    /**
     * BE1-SCANCONC-OP-03 (WWHN-188 open point) / reviewer finding DEV188-P1-03R2
     * ("OPEN — VERIFIED DEFECT") — ĐÃ ĐÓNG bằng DEC-SCAN-IDEMP-001.
     *
     * COMPONENT quantity-mode scan (không có `item_id`) chỉ được bảo vệ bởi
     * optimistic `If-Match`; replay *mới* (re-fetch version hiện hành rồi gửi
     * lại) chỉ bị chặn khi có `Idempotency-Key`. Fixture GĐ1: dòng COMPONENT
     * materialize qua /component-lines, BOX qua /boxes — không còn qty_planned
     * nhập tay.
     *
     * Bộ case đầy đủ theo yêu cầu reviewer (lost-response / stale / re-fetch /
     * same key khác payload / chuỗi retry dài) nằm ở
     * {@see Wwhn188ScanIdempotencyContractTest}.
     */
    public function test_component_quantity_mode_refetch_retry_is_deduped_by_idempotency_key(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('WWHN188-QTY-RETRY-001');

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC WWHN-188 Quantity Retry',
        ]);
        $documentId = $document['document_id'];

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $sku->sku_id,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-WWHN188-QTY-RETRY',
        ], $headers)->assertSuccessful();
        $boxId = (string) ($box->json('data.container_id') ?? $box->json('container_id'));

        $scanKey = 'wwhn188-qty-retry-'.Str::lower(Str::random(10));
        $body = ['line_id' => $lineId, 'quantity' => 2, 'container_id' => $boxId];

        // First attempt: succeeds server-side, bumps version 1 -> 2, scanned_qty 0 -> 2.
        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", $body,
            $headers + ['If-Match' => '1', 'Idempotency-Key' => $scanKey])
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.replayed', false);

        // Client never saw the response (network drop). It re-fetches the document,
        // observes the now-current version=2, and — believing the scan never went
        // through — resubmits the identical logical scan with the SAME key.
        $refetchedVersion = (int) DB::table('stock_documents')->where('id', $documentId)->value('version');
        $this->assertSame(2, $refetchedVersion, 'Sanity check: server already applied the first scan before the retry.');

        $retry = $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", $body,
            $headers + ['If-Match' => (string) $refetchedVersion, 'Idempotency-Key' => $scanKey]);

        // Hành vi sau DEC-SCAN-IDEMP-001: replay đúng response cũ, không cộng dồn.
        $retry->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.replayed', true);
        $this->assertSame('true', $retry->headers->get('Idempotent-Replay'));
        $this->assertDatabaseHas('stock_document_lines', ['id' => $lineId, 'scanned_qty' => 2]);
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'version' => 2]);

        // Nhánh không có key: hành vi cũ được giữ nguyên có chủ đích — một request
        // không mang danh tính thì server buộc phải coi là lần quét thật thứ hai.
        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", $body,
            $headers + ['If-Match' => '2'])
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 4)
            ->assertJsonPath('data.replayed', false);
        $this->assertDatabaseHas('stock_document_lines', ['id' => $lineId, 'scanned_qty' => 4]);
    }
}
