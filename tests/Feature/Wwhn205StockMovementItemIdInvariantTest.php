<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
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
        $sku = $this->productSkuFixture('PRD-WWHN205-RECEIPT-001');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-205 item_id invariant fixture',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => 1,
            ]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SN-WWHN205-'.Str::upper(Str::random(6)),
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn205-receipt-item-id-001',
        ])->assertOk();

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
        $sku = $this->productSkuFixture('PRD-WWHN205-MULTI-001');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-205 multi-item fixture',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => 2,
            ]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $itemIds = [];
        foreach (range(1, 2) as $i) {
            $itemId = (string) Str::uuid();
            DB::table('item_instances')->insert([
                'item_id' => $itemId,
                'sku_id' => $sku->sku_id,
                'serial_number' => 'SN-WWHN205-MULTI-'.$i.'-'.Str::upper(Str::random(6)),
                'item_status' => 'NEW',
                'item_condition' => 'NORMAL',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $itemIds[] = $itemId;

            $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
                'line_id' => $lineId,
                'quantity' => 1,
                'item_id' => $itemId,
            ], $headers + ['If-Match' => (string) $i])->assertOk();
        }

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'wwhn205-receipt-multi-item-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(2, (int) $movement->quantity);
        $this->assertNull(
            $movement->item_id,
            'Khi 1 movement gộp nhiều item (qty_planned>1), item_id phải là null — không được gán nhầm 1 trong 2 item.'
        );

        foreach ($itemIds as $itemId) {
            $this->assertContains($itemId, DB::table('inbound_scan_entries')
                ->where('inbound_line_id', $lineId)
                ->pluck('item_id')
                ->map(fn ($id) => (string) $id)
                ->all());
        }
    }

    public function test_component_receipt_leaves_item_id_null_for_quantity_mode_sku(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = Sku::create([
            'sku_code' => 'COMP-WWHN205-001',
            'sku_name' => 'Component WWHN-205 fixture',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-205 component fixture',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => 3,
            ]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $register = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-WWHN205-001',
        ], $headers);
        $register->assertCreated();
        $boxId = (string) $register->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 3,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn205-receipt-component-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(3, (int) $movement->quantity);
        $this->assertNull($movement->item_id, 'COMPONENT/quantity-mode SKU không có item cụ thể — item_id phải là null.');
    }
}
