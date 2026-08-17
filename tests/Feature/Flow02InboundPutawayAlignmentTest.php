<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Flow 02 · Nhập kho — alignment gate với SRS v1.12 / ERD / Annex C-D.
 *
 * Trước đây flow 01 và flow 06 có test alignment riêng, flow 02 thì không, nên các
 * lệch contract dưới đây sống lâu mà CI vẫn xanh. File này khoá đúng những điểm đã
 * được sửa trong lần rà soát docs-vs-code của luồng 02:
 *
 *  - SRS §3: Putaway list/create/confirm/cancel dùng permission key `putaway.manage`
 *    (NV kho = A*). Trước đây path legacy /putaway/* chỉ nhận container.manage nên
 *    WAREHOUSE_STAFF được seed putaway.manage vẫn bị 403.
 *  - ERD bảng 28: inbound_scan_entries.status ACTIVE/REMOVED "cho sửa trước Post" —
 *    phải có đường gỡ mã quét sai, và Post không được đưa entry REMOVED vào tồn.
 *  - SRS §8.2: 412 -> STALE_VERSION (không phải PRECONDITION_FAILED).
 *  - Annex A / Annex D SCR-IN-01: field `source_reference` max 200, `scan_input` 1..150.
 *  - Annex C cancelInboundDocument: permission `inbound.create`.
 *  - §13.6 Pagination: nhận tham số `page_size`.
 */
class Flow02InboundPutawayAlignmentTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** Phiếu nhập DRAFT với 1 line PRODUCT serial-tracked. */
    private function draftInbound(string $skuCode, int $qty = 1, ?array $headers = null): array
    {
        $headers ??= $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC Flow02',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers)->assertCreated();

        return [
            'headers' => $headers,
            'document_id' => (string) $create->json('data.id'),
            'line_id' => (string) $create->json('data.lines.0.id'),
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    private function receivableItem(string $skuId): string
    {
        $itemId = $this->seedItemInStock($skuId);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);

        return $itemId;
    }

    // ── P0-1 · SRS §3 Putaway permission ────────────────────────────────────

    public function test_warehouse_staff_with_putaway_manage_can_list_and_create_putaway_on_both_paths(): void
    {
        // WAREHOUSE_STAFF được seed putaway.view + putaway.manage nhưng KHÔNG có
        // container.manage — đúng ma trận SRS §3. Cả path legacy /putaway/* (FE đang
        // gọi) lẫn alias canonical Annex C /putaway-tasks đều phải cho qua.
        $staff = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $this->assertFalse(
            DB::table('role_permission as rp')
                ->join('permission as p', 'p.permission_id', '=', 'rp.permission_id')
                ->join('role as r', 'r.role_id', '=', 'rp.role_id')
                ->where('r.role_code', 'WAREHOUSE_STAFF')
                ->where('p.permission_key', 'container.manage')
                ->exists(),
            'Test này chỉ có nghĩa khi WAREHOUSE_STAFF không có container.manage.'
        );

        $this->getJson('/api/v1/putaway/tasks', $staff)->assertOk();
        $this->getJson('/api/v1/putaway-tasks', $staff)->assertOk();
        $this->getJson('/api/v1/putaway/history', $staff)->assertOk();

        $sku = Sku::create([
            'sku_code' => 'FLOW02-PTW-COMP',
            'sku_name' => 'Component Flow02',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-FLOW02-PTW',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-FLOW02-PTW',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'zone_code' => 'COMPONENT_AREA',
            'version' => 1,
        ]);

        $created = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $staff);
        $created->assertSuccessful()
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $eventId = (string) $created->json('data.id');
        $version = (int) $created->json('data.version');

        $this->postJson("/api/v1/putaway-tasks/{$eventId}/confirm", [], $staff + ['If-Match' => (string) $version])
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.stock_effect', 'NONE');

        // AC-SRS-009: Putaway chỉ đổi mapping/location, không sinh movement.
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
        ]);
    }

    public function test_warehouse_staff_can_cancel_putaway_draft_before_confirm(): void
    {
        $staff = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $sku = Sku::create([
            'sku_code' => 'FLOW02-PTW-CANCEL',
            'sku_name' => 'Component Flow02 cancel',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-FLOW02-CANCEL',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-FLOW02-CANCEL',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $created = $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $staff)->assertSuccessful();

        $this->postJson(
            '/api/v1/putaway-tasks/'.$created->json('data.id').'/cancel',
            [],
            $staff + ['If-Match' => (string) $created->json('data.version')]
        )->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    // ── P1-3 · ERD bảng 28 gỡ mã quét sai trước Post ────────────────────────

    public function test_unscan_marks_entry_removed_and_restores_line_progress(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-01');
        $itemId = $this->receivableItem($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $itemId)->value('id');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
            'reason' => 'Quét sai serial',
        ], $ctx['headers'] + ['If-Match' => '2']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'REMOVED')
            ->assertJsonPath('data.scanned_quantity', 0)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.version', 3)
            ->assertHeader('ETag', '"3"');

        $this->assertDatabaseHas('inbound_scan_entries', ['id' => $entryId, 'status' => 'REMOVED']);
        $this->assertNotNull(DB::table('inbound_scan_entries')->where('id', $entryId)->value('removed_at'));
        $this->assertSame(0, (int) DB::table('stock_document_lines')->where('id', $ctx['line_id'])->value('scanned_qty'));
        $this->assertSame(0, DB::table('stock_movements')->count());
        // Item chưa từng vào tồn nên vẫn NEW.
        $this->assertDatabaseHas('item_instances', ['item_id' => $itemId, 'item_status' => 'NEW']);
    }

    public function test_removed_entry_allows_rescanning_the_same_item(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-02');
        $itemId = $this->receivableItem($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $itemId)->value('id');
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        // Quét lại chính item vừa gỡ phải được chấp nhận (không còn DUPLICATE_ITEM_IN_DOCUMENT).
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '3'])->assertOk();

        $this->assertSame(1, (int) DB::table('stock_document_lines')->where('id', $ctx['line_id'])->value('scanned_qty'));
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('item_id', $itemId)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('item_id', $itemId)->where('status', 'REMOVED')->count());
    }

    public function test_post_receipt_does_not_put_removed_items_into_stock(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-03', 1);
        $wrongItem = $this->receivableItem($ctx['sku_id']);
        $rightItem = $this->receivableItem($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $wrongItem,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $wrongItem)->value('id');
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $rightItem,
        ], $ctx['headers'] + ['If-Match' => '3'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt", [], $ctx['headers'] + [
            'If-Match' => '4',
            'Idempotency-Key' => 'flow02-unscan-03-post',
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        // Chỉ item còn ACTIVE được vào tồn; item đã gỡ phải giữ nguyên NEW.
        $this->assertDatabaseHas('item_instances', ['item_id' => $rightItem, 'item_status' => 'IN_STOCK']);
        $this->assertDatabaseHas('item_instances', ['item_id' => $wrongItem, 'item_status' => 'NEW']);
        $this->assertSame(1, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $ctx['warehouse_id'])
            ->where('sku_id', $ctx['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->value('quantity'));
    }

    public function test_unscan_requires_if_match_and_rejects_stale_version(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-04');
        $itemId = $this->receivableItem($ctx['sku_id']);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();
        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $itemId)->value('id');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        // SRS §8.2: 412 phải là STALE_VERSION.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '99'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        $this->assertDatabaseHas('inbound_scan_entries', ['id' => $entryId, 'status' => 'ACTIVE']);
    }

    public function test_unscan_is_rejected_twice_and_after_post(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-05', 2);
        $first = $this->receivableItem($ctx['sku_id']);
        $second = $this->receivableItem($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $first,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();
        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $first)->value('id');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        // Gỡ lần hai trên cùng entry: đã REMOVED nên bị chặn.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '3'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SCAN_ENTRY_NOT_ACTIVE');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $first,
        ], $ctx['headers'] + ['If-Match' => '3'])->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $second,
        ], $ctx['headers'] + ['If-Match' => '4'])->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt", [], $ctx['headers'] + [
            'If-Match' => '5',
            'Idempotency-Key' => 'flow02-unscan-05-post',
        ])->assertOk();

        $postedEntry = (string) DB::table('inbound_scan_entries')->where('item_id', $second)->value('id');
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $postedEntry,
        ], $ctx['headers'] + ['If-Match' => '6'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    public function test_unscan_rejects_entry_from_another_document(): void
    {
        $ctxA = $this->draftInbound('FLOW02-UNSCAN-06-A');
        $itemId = $this->receivableItem($ctxA['sku_id']);
        $this->postJson("/api/v1/inbound-documents/{$ctxA['document_id']}/scan", [
            'line_id' => $ctxA['line_id'], 'quantity' => 1, 'item_id' => $itemId,
        ], $ctxA['headers'] + ['If-Match' => '1'])->assertOk();
        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $itemId)->value('id');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $ctxA['warehouse_id'],
            'lines' => [['product_id' => $ctxA['sku_id'], 'qty_planned' => 1]],
        ], $ctxA['headers'])->assertCreated();

        $this->postJson('/api/v1/inbound-documents/'.$create->json('data.id').'/unscan', [
            'inbound_scan_entry_id' => $entryId,
        ], $ctxA['headers'] + ['If-Match' => '1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'INBOUND_SCAN_ENTRY_NOT_FOUND');
    }

    public function test_scan_entries_endpoint_hides_removed_rows_by_default(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-07', 2);
        $removed = $this->receivableItem($ctx['sku_id']);
        $kept = $this->receivableItem($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $removed,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();
        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $removed)->value('id');
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $kept,
        ], $ctx['headers'] + ['If-Match' => '3'])->assertOk();

        $active = $this->getJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan-entries", $ctx['headers'])->assertOk();
        $this->assertCount(1, $active->json('data'));
        $this->assertSame($kept, $active->json('data.0.item_id'));

        $all = $this->getJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan-entries?status=ALL", $ctx['headers'])->assertOk();
        $this->assertCount(2, $all->json('data'));
    }

    public function test_removing_every_scan_entry_reopens_line_structure_editing(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-08');
        $itemId = $this->receivableItem($ctx['sku_id']);
        $otherSku = $this->productSkuFixture('FLOW02-UNSCAN-08-OTHER');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        // Còn scan evidence sống -> không được đổi SKU của dòng.
        $this->patchJson("/api/v1/inbound-documents/{$ctx['document_id']}", [
            'lines' => [['id' => $ctx['line_id'], 'product_id' => $otherSku->sku_id, 'qty_planned' => 1]],
        ], $ctx['headers'] + ['If-Match' => '2'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SCANNED_LINE_STRUCTURE_IMMUTABLE');

        $entryId = (string) DB::table('inbound_scan_entries')->where('item_id', $itemId)->value('id');
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        // Gỡ hết mã đã quét -> phiếu trở lại như chưa quét, được sửa cấu trúc dòng.
        $this->patchJson("/api/v1/inbound-documents/{$ctx['document_id']}", [
            'lines' => [['product_id' => $otherSku->sku_id, 'qty_planned' => 2]],
        ], $ctx['headers'] + ['If-Match' => '3'])->assertOk();

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    // ── P2-6 · Annex A / Annex D field contract ─────────────────────────────

    public function test_source_reference_alias_is_accepted_and_returned(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('FLOW02-SRCREF');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_reference' => 'PO-2026-0001',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers)->assertCreated();

        $create->assertJsonPath('data.source_reference', 'PO-2026-0001')
            ->assertJsonPath('data.source_name', 'PO-2026-0001');

        $documentId = (string) $create->json('data.id');
        $this->getJson("/api/v1/inbound-documents/{$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.source_reference', 'PO-2026-0001');
        $this->getJson('/api/v1/inbound-documents', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.source_reference', 'PO-2026-0001');

        $this->patchJson("/api/v1/inbound-documents/{$documentId}", [
            'source_reference' => 'PO-2026-0002',
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.source_reference', 'PO-2026-0002');
    }

    public function test_source_reference_longer_than_200_chars_is_rejected(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('FLOW02-SRCREF-LONG');

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_reference' => str_repeat('A', 201),
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers)->assertStatus(422);

        $this->assertSame(0, DB::table('stock_documents')->where('doc_type', 'receipt')->count());
    }

    public function test_scan_code_value_is_trimmed_and_capped_at_150_chars(): void
    {
        $ctx = $this->draftInbound('FLOW02-SCANLEN');
        $itemId = $this->receivableItem($ctx['sku_id']);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemId, $codeValue);

        // Annex D: scan_input tối đa 150 ký tự.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => str_repeat('B', 151),
        ], $ctx['headers'] + ['If-Match' => '1'])->assertStatus(422);

        // Trim: khoảng trắng bao quanh không được làm resolver miss.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'code_value' => '  '.$codeValue.'  ',
        ], $ctx['headers'] + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.item_id', $itemId);
    }

    // ── P2-6 · Annex C permission + §13.6 pagination ────────────────────────

    public function test_cancel_inbound_accepts_annex_c_inbound_create_permission(): void
    {
        // Annex C cancelInboundDocument khai permission `inbound.create`.
        // WAREHOUSE_STAFF có inbound.create nhưng không có inbound.cancel.
        $staff = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $this->assertFalse(
            DB::table('role_permission as rp')
                ->join('permission as p', 'p.permission_id', '=', 'rp.permission_id')
                ->join('role as r', 'r.role_id', '=', 'rp.role_id')
                ->where('r.role_code', 'WAREHOUSE_STAFF')
                ->where('p.permission_key', 'inbound.cancel')
                ->exists(),
            'Test này chỉ có nghĩa khi WAREHOUSE_STAFF không có inbound.cancel.'
        );

        $ctx = $this->draftInbound('FLOW02-CANCEL', 1, $staff);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/cancel", [
            'reason' => 'Nhà cung cấp giao sai lô',
        ], $staff + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_inbound_list_accepts_page_size_pagination_parameter(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('FLOW02-PAGESIZE');

        foreach ([1, 2] as $index) {
            $this->postJson('/api/v1/inbound-documents', [
                'dst_warehouse_id' => $warehouse->id,
                'doc_no' => 'PN-FLOW02-PAGE-'.$index,
                'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
            ], $headers)->assertCreated();
        }

        // §13.6 dùng tên `page_size`; `per_page` vẫn phải hoạt động cho client hiện hành.
        $byPageSize = $this->getJson('/api/v1/inbound-documents?page_size=1', $headers)->assertOk();
        $this->assertCount(1, $byPageSize->json('data'));
        $this->assertSame(2, (int) $byPageSize->json('meta.total'));

        $byPerPage = $this->getJson('/api/v1/inbound-documents?per_page=1', $headers)->assertOk();
        $this->assertCount(1, $byPerPage->json('data'));
    }

    // ── P2-8 · Dead code trái BR-REV-01 phải fail-closed ───────────────────

    public function test_superseded_reverse_document_service_path_is_fail_closed(): void
    {
        $service = (string) file_get_contents(base_path('Modules/Warehouse/app/Services/StockDocumentService.php'));

        // BR-REV-01 + §6: chứng từ gốc giữ POSTED, không có state 'REVERSED'.
        $this->assertStringNotContainsString("'status' => StockDocument::STATUS_REVERSED", $service);
        $this->assertStringContainsString('reverse_document is outside the latest MVP baseline', $service);

        // §5.5 / BR-REV-05: không auto-reverse toàn chứng từ khi thiếu lines.
        $stock = (string) file_get_contents(base_path('Modules/Warehouse/app/Services/CurrentBaselineStockService.php'));
        $this->assertStringContainsString('REVERSAL_LINES_REQUIRED', $stock);
    }
}
