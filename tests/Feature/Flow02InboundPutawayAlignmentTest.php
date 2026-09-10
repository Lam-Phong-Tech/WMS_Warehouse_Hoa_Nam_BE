<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
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
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    /**
     * Phiếu nhập DRAFT theo contract GĐ1 scan-driven: HEADER-ONLY — dòng SKU chỉ
     * materialize khi quét composite QR HN1|SKU|ITEM (item mới, register-at-Post).
     */
    private function draftInbound(string $skuCode, int $qty = 1, ?array $headers = null): array
    {
        $headers ??= $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'NCC Flow02',
        ]);

        return [
            'headers' => $headers,
            'document_id' => $document['document_id'],
            'sku_code' => $skuCode,
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    /** Quét 1 item-candidate composite vào phiếu, trả về TestResponse (assertOk sẵn). */
    private function scanCandidate(array $ctx, string $itemUnique, int $ifMatch)
    {
        return $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr($ctx['sku_code'], $itemUnique),
        ], $ctx['headers'] + ['If-Match' => (string) $ifMatch])->assertOk();
    }

    private function entryIdOfCandidate(string $itemUnique): string
    {
        return (string) DB::table('inbound_scan_entries')->where('candidate_item_unique', $itemUnique)->value('id');
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
        // DEV193-P1-05R: putaway task đòi linkage phiếu nhập POSTED, không chỉ trạng thái.
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);
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
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);
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
        $itemUnique = 'FLOW02-UNSCAN-01-ITEM-0001';

        $scan = $this->scanCandidate($ctx, $itemUnique, 1);
        $lineId = (string) $scan->json('data.line_id');
        $entryId = $this->entryIdOfCandidate($itemUnique);

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
        $this->assertSame(0, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
        $this->assertSame(0, DB::table('stock_movements')->count());
        // Candidate register-at-Post: chưa Post nên identity chưa được ghi.
        $this->assertSame(0, DB::table('item_instances')->where('serial_number', $itemUnique)->count());
    }

    public function test_removed_entry_allows_rescanning_the_same_item(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-02');
        $itemUnique = 'FLOW02-UNSCAN-02-ITEM-0001';

        $scan = $this->scanCandidate($ctx, $itemUnique, 1);
        $lineId = (string) $scan->json('data.line_id');

        $entryId = $this->entryIdOfCandidate($itemUnique);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        // Quét lại chính mã vừa gỡ phải được chấp nhận (không còn DUPLICATE_ITEM_IN_DOCUMENT).
        $this->scanCandidate($ctx, $itemUnique, 3);

        $this->assertSame(1, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('candidate_item_unique', $itemUnique)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('candidate_item_unique', $itemUnique)->where('status', 'REMOVED')->count());
    }

    public function test_post_receipt_does_not_put_removed_items_into_stock(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-03', 1);
        $wrongUnique = 'FLOW02-UNSCAN-03-WRONG-0001';
        $rightUnique = 'FLOW02-UNSCAN-03-RIGHT-0001';

        $this->scanCandidate($ctx, $wrongUnique, 1);

        $entryId = $this->entryIdOfCandidate($wrongUnique);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        $this->scanCandidate($ctx, $rightUnique, 3);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt", [], $ctx['headers'] + [
            'If-Match' => '4',
            'Idempotency-Key' => 'flow02-unscan-03-post',
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        // Chỉ candidate còn ACTIVE được register + vào tồn; candidate đã gỡ không
        // được ghi identity nào (register-at-Post chỉ đọc entry ACTIVE).
        $this->assertDatabaseHas('item_instances', ['serial_number' => $rightUnique, 'item_status' => 'IN_STOCK']);
        $this->assertSame(0, DB::table('item_instances')->where('serial_number', $wrongUnique)->count());
        $this->assertSame(1, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $ctx['warehouse_id'])
            ->where('sku_id', $ctx['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->value('quantity'));
    }

    public function test_unscan_requires_if_match_and_rejects_stale_version(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-04');
        $itemUnique = 'FLOW02-UNSCAN-04-ITEM-0001';
        $this->scanCandidate($ctx, $itemUnique, 1);
        $entryId = $this->entryIdOfCandidate($itemUnique);

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
        $firstUnique = 'FLOW02-UNSCAN-05-ITEM-0001';
        $secondUnique = 'FLOW02-UNSCAN-05-ITEM-0002';

        $this->scanCandidate($ctx, $firstUnique, 1);
        $entryId = $this->entryIdOfCandidate($firstUnique);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        // Gỡ lần hai trên cùng entry: đã REMOVED nên bị chặn.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '3'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SCAN_ENTRY_NOT_ACTIVE');

        $this->scanCandidate($ctx, $firstUnique, 3);
        $this->scanCandidate($ctx, $secondUnique, 4);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt", [], $ctx['headers'] + [
            'If-Match' => '5',
            'Idempotency-Key' => 'flow02-unscan-05-post',
        ])->assertOk();

        $postedEntry = $this->entryIdOfCandidate($secondUnique);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $postedEntry,
        ], $ctx['headers'] + ['If-Match' => '6'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    public function test_unscan_rejects_entry_from_another_document(): void
    {
        $ctxA = $this->draftInbound('FLOW02-UNSCAN-06-A');
        $itemUnique = 'FLOW02-UNSCAN-06-ITEM-0001';
        $this->scanCandidate($ctxA, $itemUnique, 1);
        $entryId = $this->entryIdOfCandidate($itemUnique);

        $other = $this->createInboundHeader($ctxA['headers'], (string) $ctxA['warehouse_id']);

        $this->postJson('/api/v1/inbound-documents/'.$other['document_id'].'/unscan', [
            'inbound_scan_entry_id' => $entryId,
        ], $ctxA['headers'] + ['If-Match' => '1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'INBOUND_SCAN_ENTRY_NOT_FOUND');
    }

    public function test_scan_entries_endpoint_hides_removed_rows_by_default(): void
    {
        $ctx = $this->draftInbound('FLOW02-UNSCAN-07', 2);
        $removedUnique = 'FLOW02-UNSCAN-07-GO-0001';
        $keptUnique = 'FLOW02-UNSCAN-07-GIU-0001';

        $this->scanCandidate($ctx, $removedUnique, 1);
        $entryId = $this->entryIdOfCandidate($removedUnique);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();
        $this->scanCandidate($ctx, $keptUnique, 3);

        $active = $this->getJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan-entries", $ctx['headers'])->assertOk();
        $this->assertCount(1, $active->json('data'));
        // Candidate chưa Post nên identity hiển thị qua candidate_item_unique (DEC-QR-03).
        $this->assertSame($keptUnique, $active->json('data.0.candidate_item_unique'));

        $all = $this->getJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan-entries?status=ALL", $ctx['headers'])->assertOk();
        $this->assertCount(2, $all->json('data'));
    }

    public function test_manual_line_editing_stays_closed_under_scan_driven_contract(): void
    {
        // GĐ1 scan-driven thay thế hành vi cũ "gỡ hết scan thì được sửa cấu trúc dòng":
        // dòng SKU nay là HỆ QUẢ của quét thực tế, nên PATCH gửi `lines` bị chặn
        // INBOUND_SCAN_DRIVEN vô điều kiện — kể cả khi đã gỡ hết mã quét. Đầu phiếu
        // (source_reference/note...) vẫn cập nhật được bình thường.
        $ctx = $this->draftInbound('FLOW02-UNSCAN-08');
        $itemUnique = 'FLOW02-UNSCAN-08-ITEM-0001';
        $otherSku = $this->productSkuFixture('FLOW02-UNSCAN-08-OTHER');

        $this->scanCandidate($ctx, $itemUnique, 1);

        $this->patchJson("/api/v1/inbound-documents/{$ctx['document_id']}", [
            'lines' => [['product_id' => $otherSku->sku_id, 'qty_planned' => 1]],
        ], $ctx['headers'] + ['If-Match' => '2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INBOUND_SCAN_DRIVEN');

        $entryId = $this->entryIdOfCandidate($itemUnique);
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();

        // Gỡ hết mã quét cũng KHÔNG mở lại cửa nhập dòng tay.
        $this->patchJson("/api/v1/inbound-documents/{$ctx['document_id']}", [
            'lines' => [['product_id' => $otherSku->sku_id, 'qty_planned' => 2]],
        ], $ctx['headers'] + ['If-Match' => '3'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INBOUND_SCAN_DRIVEN');

        // Đầu phiếu vẫn sửa được — bề mặt hợp lệ không bị đóng nhầm.
        $this->patchJson("/api/v1/inbound-documents/{$ctx['document_id']}", [
            'source_reference' => 'PO-FLOW02-UNSCAN-08',
        ], $ctx['headers'] + ['If-Match' => '3'])
            ->assertOk()
            ->assertJsonPath('data.source_reference', 'PO-FLOW02-UNSCAN-08');

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
        ], $headers)->assertStatus(422);

        $this->assertSame(0, DB::table('stock_documents')->where('doc_type', 'receipt')->count());
    }

    public function test_scan_code_value_is_trimmed_and_capped_at_150_chars(): void
    {
        $ctx = $this->draftInbound('FLOW02-SCANLEN');
        $itemUnique = 'FLOW02-SCANLEN-ITEM-0001';

        // Annex D: scan_input tối đa 150 ký tự.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => str_repeat('B', 151),
        ], $ctx['headers'] + ['If-Match' => '1'])->assertStatus(422);

        // Trim: khoảng trắng bao quanh không được làm parser composite miss.
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => '  '.$this->compositeProductQr($ctx['sku_code'], $itemUnique).'  ',
        ], $ctx['headers'] + ['If-Match' => '1'])->assertOk();

        $this->assertSame(
            1,
            DB::table('inbound_scan_entries')->where('candidate_item_unique', $itemUnique)->count(),
            'Candidate phải được ghi nhận đúng item_unique đã chuẩn hoá (trim).'
        );
    }

    // ── P2-6 · Annex C permission + §13.6 pagination ────────────────────────

    public function test_cancel_inbound_accepts_annex_c_inbound_create_permission(): void
    {
        // Annex C cancelInboundDocument khai permission `inbound.create`, nên route cancel nhận
        // `inbound.cancel|inbound.create` (Modules/Warehouse/routes/api.php).
        //
        // DELTA-06 (docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json; seeder d019d63): từ 06/09/2026
        // WAREHOUSE_STAFF giữ CẢ inbound.cancel, nên vai đó không còn đo được nhánh alias
        // `inbound.create`. Nhánh alias chuyển sang một vai fixture chỉ có inbound.create; NV kho
        // nhận ca dương tính của quyết định mới; vai không có quyền nào trong hai quyền vẫn 403.
        $staff = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $this->assertTrue(
            $this->roleHasPermission('WAREHOUSE_STAFF', 'inbound.cancel'),
            'DELTA-06: WAREHOUSE_STAFF phải giữ inbound.cancel.'
        );

        // 1) Nhánh alias Annex C: vai CHỈ có inbound.create.
        [$createOnly, $createOnlyRole] = $this->headersForFixtureRole(['inbound.create']);
        $this->assertTrue($this->roleHasPermission($createOnlyRole, 'inbound.create'));
        $this->assertFalse(
            $this->roleHasPermission($createOnlyRole, 'inbound.cancel'),
            'Nhánh alias chỉ có nghĩa khi vai fixture không có inbound.cancel.'
        );

        $ctx = $this->draftInbound('FLOW02-CANCEL', 1, $createOnly);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/cancel", [
            'reason' => 'Nhà cung cấp giao sai lô',
        ], $createOnly + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        // 2) DELTA-06: NV kho tự huỷ phiếu nhập. Quên guard để request xác thực lại từ token của
        // NV kho, không dùng lại user vừa đăng nhập gần nhất.
        $this->app['auth']->forgetGuards();
        $staffCtx = $this->draftInbound('FLOW02-CANCEL-STAFF', 1, $staff);

        $this->postJson("/api/v1/inbound-documents/{$staffCtx['document_id']}/cancel", [
            'reason' => 'NV kho huỷ phiếu nhập tạo nhầm',
        ], $staff + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        // 3) Vai không có inbound.cancel lẫn inbound.create (VIEWER chỉ đọc) vẫn bị chặn.
        $openCtx = $this->draftInbound('FLOW02-CANCEL-DENY', 1, $staff);
        $viewer = $this->authHeadersWithRole('VIEWER');
        $this->assertFalse($this->roleHasPermission('VIEWER', 'inbound.cancel'));
        $this->assertFalse($this->roleHasPermission('VIEWER', 'inbound.create'));

        $this->postJson("/api/v1/inbound-documents/{$openCtx['document_id']}/cancel", [
            'reason' => 'Vai chỉ đọc thử huỷ phiếu',
        ], $viewer + ['If-Match' => '1'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->assertDatabaseHas('stock_documents', ['id' => $openCtx['document_id'], 'status' => 'DRAFT']);

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    private function roleHasPermission(string $roleCode, string $permissionKey): bool
    {
        return DB::table('role_permission as rp')
            ->join('permission as p', 'p.permission_id', '=', 'rp.permission_id')
            ->join('role as r', 'r.role_id', '=', 'rp.role_id')
            ->where('r.role_code', $roleCode)
            ->where('p.permission_key', $permissionKey)
            ->exists();
    }

    /**
     * Vai fixture mang ĐÚNG các quyền liệt kê, lấy từ bảng permission đã seed thật — không mock
     * middleware, CheckPermission chạy như production.
     *
     * @param  list<string>  $permissionKeys
     * @return array{0: array<string, string>, 1: string} headers + role_code
     */
    private function headersForFixtureRole(array $permissionKeys): array
    {
        $roleCode = 'FLOW02_FIXTURE_'.Str::upper(Str::random(8));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => 'Flow02 fixture role (Test)',
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
        ]);
        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->where('permission_key', $permissionKey)->firstOrFail();
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }

        $user = User::create([
            'username' => 'flow02-fixture-'.Str::lower(Str::random(8)),
            'display_name' => 'Flow02 fixture user',
            // Không bao giờ đăng nhập bằng mật khẩu: token cấp thẳng bên dưới.
            'password_hash' => Hash::make(Str::random(40)),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [['Authorization' => 'Bearer '.auth('api')->login($user)], $roleCode];
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
