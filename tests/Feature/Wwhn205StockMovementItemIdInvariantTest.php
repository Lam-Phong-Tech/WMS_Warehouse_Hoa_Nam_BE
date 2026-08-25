<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-205 (BE1): CurrentBaselineStockService::mutateBalance() previously never set
 * stock_movements.item_id/container_id when called from postStockDocument() (receipt/issue),
 * even though the columns exist with an FK constraint (migration 2026_08_10_000600) and
 * CurrentBaselineReversalService::mutateBalance() always sets item_id per reversal_line.
 * WWHN-202/WWHN-207 (BE2) documented this as a read-side gap (trace-by-code fallback) but
 * explicitly left the write-side fix out of scope (owned by BE1/transaction-posting logic).
 *
 * Fix: resolveLineItemId() sets item_id on the posted movement whenever exactly one item
 * backs that document line (the common serial-tracked PRODUCT case, qty_planned=1). When a
 * line aggregates multiple items (qty_planned>1, serial-tracked) or has none (COMPONENT,
 * quantity-mode SKU), item_id legitimately stays null — a single movement row cannot
 * truthfully attribute itself to more than one item, and the read-side fallback already
 * covers that case.
 */
class Wwhn205StockMovementItemIdInvariantTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_post_issue_sets_item_id_on_movement_for_single_item_line(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'PRD-WWHN205-ISSUE-001');

        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn205-issue-item-id-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $doc['document_id'])
            ->where('movement_type', 'ISSUE')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame($doc['item_id'], $movement->item_id, 'stock_movements.item_id phải khớp item đã match cho line 1-item/qty=1.');
    }

    public function test_post_receipt_sets_item_id_on_movement_for_single_item_serial_tracked_line(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('PRD-WWHN205-RECEIPT-001');

        // Contract GĐ1 scan-driven: header-only + composite QR, item register-at-Post.
        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'PRD-WWHN205-RECEIPT-001', 'qty' => 1],
        ]);
        $documentId = $fixture['document_id'];

        $this->postInboundReceipt($headers, $documentId, $fixture['version'], 'wwhn205-receipt-item-id-001')
            ->assertOk();

        $itemUnique = $fixture['lines']['PRD-WWHN205-RECEIPT-001']['item_uniques'][0];
        $itemId = (string) DB::table('item_instances')->where('serial_number', $itemUnique)->value('item_id');
        $this->assertNotSame('', $itemId, 'Post Receipt phải register item candidate thành item_instances.');

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame($itemId, $movement->item_id, 'stock_movements.item_id phải khớp item đã scan cho line 1-item/qty=1.');
    }

    public function test_post_receipt_leaves_item_id_null_when_line_aggregates_multiple_items(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('PRD-WWHN205-MULTI-001');

        // 2 item composite QR cùng SKU → gom về đúng 1 dòng scanned_qty=2.
        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'PRD-WWHN205-MULTI-001', 'qty' => 2],
        ]);
        $documentId = $fixture['document_id'];
        $lineId = $fixture['lines']['PRD-WWHN205-MULTI-001']['line_id'];

        $this->postInboundReceipt($headers, $documentId, $fixture['version'], 'wwhn205-receipt-multi-item-001')
            ->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(2, (int) $movement->quantity);
        $this->assertNull(
            $movement->item_id,
            'Khi 1 movement gộp nhiều item (scanned_qty>1), item_id phải là null — không được gán nhầm 1 trong 2 item.'
        );

        // Cả 2 item được register-at-Post và backfill vào scan entries của line.
        $entryItemIds = DB::table('inbound_scan_entries')
            ->where('inbound_line_id', $lineId)
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        foreach ($fixture['lines']['PRD-WWHN205-MULTI-001']['item_uniques'] as $itemUnique) {
            $itemId = (string) DB::table('item_instances')->where('serial_number', $itemUnique)->value('item_id');
            $this->assertNotSame('', $itemId);
            $this->assertContains($itemId, $entryItemIds);
        }
    }

    public function test_component_receipt_leaves_item_id_null_for_quantity_mode_sku(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture('COMP-WWHN205-001');

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'WWHN-205 component fixture',
        ]);
        $documentId = $document['document_id'];

        $received = $this->receiveComponentQuantity(
            $headers,
            $documentId,
            (string) $sku->sku_id,
            3,
            $document['version'],
            'BOX-WWHN205-001'
        );

        $this->postInboundReceipt($headers, $documentId, $received['version'], 'wwhn205-receipt-component-001')
            ->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(3, (int) $movement->quantity);
        $this->assertNull($movement->item_id, 'COMPONENT/quantity-mode SKU không có item cụ thể — item_id phải là null.');
    }
}
