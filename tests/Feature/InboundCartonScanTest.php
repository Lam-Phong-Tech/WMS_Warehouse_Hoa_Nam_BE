<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Inbound Carton Scan v1.0 (Implementation Delta 20/08/2026) — carton scan
 * context cho PRODUCT inbound:
 *
 *  - classify expose container_id/container_code/container_type (§3.1, CTN-T01);
 *  - PRODUCT scan mang container_id được verify carton usable/CARTON/primary SKU
 *    khớp và persist vào inbound_scan_entries, vẫn stock-neutral (§3.2, CTN-T02);
 *  - máy lẻ scan không có container_id thì entry lưu null (CTN-T04);
 *  - POST /inbound-documents/{id}/cartons/{containerId}/complete-scan là
 *    audit-only: không đổi container.status, không bump document version, không
 *    movement/balance (§3.3, CTN-01/02/03, CTN-T03);
 *  - duplicate complete là harmless (CTN-T11) và scan lại carton sau complete để
 *    bổ sung item bị sót không cần REOPEN state (CTN-07, CTN-T08).
 */
class InboundCartonScanTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** Canonical containers (số nhiều) — nơi mọi guard runtime đọc. */
    private function cartonFixture(
        string $code,
        ?string $primarySkuId,
        string $type = 'CARTON',
        string $status = 'EMPTY'
    ): string {
        $containerId = (string) Str::uuid();
        DB::table('containers')->insert([
            // Trên Postgres containers có cột id mirror; sqlite thì không.
            ...(Schema::hasColumn('containers', 'id') ? ['id' => $containerId] : []),
            'container_id' => $containerId,
            'container_code' => $code,
            'container_type' => $type,
            'primary_sku_id' => $primarySkuId,
            'status' => $status,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $containerId;
    }

    /** item_instances.item_status=NEW — receivable theo assertItemAvailableForReceipt(). */
    private function seedReceivableItem(string $skuId): string
    {
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'serial_number' => 'SN-'.Str::upper(Str::random(10)),
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }

    /** @return array{headers: array, document_id: string, line_id: string, sku_id: string} */
    private function draftProductInbound(string $skuCode, int $qtyPlanned): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC Carton Scan v1.0',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qtyPlanned]],
        ], $headers)->assertCreated();

        return [
            'headers' => $headers,
            'document_id' => (string) $create->json('data.id'),
            'line_id' => (string) $create->json('data.lines.0.id'),
            'sku_id' => (string) $sku->sku_id,
        ];
    }

    private function scanItemIntoDocument(
        array $ctx,
        string $itemId,
        int $ifMatch,
        ?string $containerId = null
    ) {
        $body = ['line_id' => $ctx['line_id'], 'quantity' => 1, 'item_id' => $itemId];
        if ($containerId !== null) {
            $body['container_id'] = $containerId;
        }

        return $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            $body,
            $ctx['headers'] + ['If-Match' => (string) $ifMatch]
        );
    }

    /**
     * CTN-T01 (§3.1): classify một mã CARTON trả classification=CONTAINER kèm
     * container_id/container_code/container_type non-null, stock_effect=NONE.
     * Classify đọc qua relation legacy `container` (view trên PG, bảng trên
     * sqlite) nên fixture ghi trực tiếp vào đó.
     */
    public function test_classify_carton_exposes_container_identity(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('CTN-CLASSIFY-SKU-001');

        $containerId = (string) Str::uuid();
        DB::table('container')->insert([
            'container_id' => $containerId,
            'container_code' => 'CTN-CLASSIFY-001',
            'container_type' => 'CARTON',
            'warehouse_id' => $warehouse->id,
            'primary_product_id' => $sku->sku_id,
            'status' => 'EMPTY',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/scan/classify', [
            'raw_code' => 'CTN-CLASSIFY-001',
            'context' => 'INBOUND',
            'warehouse_id' => $warehouse->id,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.classification', 'CONTAINER')
            ->assertJsonPath('data.container_id', $containerId)
            ->assertJsonPath('data.container_code', 'CTN-CLASSIFY-001')
            ->assertJsonPath('data.container_type', 'CARTON')
            ->assertJsonPath('data.sku_id', $sku->sku_id)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
    }

    /**
     * CTN-T02 + CTN-T04 (§3.2): PRODUCT scan khi carton active persist đúng
     * container_id vào inbound_scan_entries và vẫn stock-neutral; máy lẻ sau đó
     * (không gửi container_id) lưu container_id=null — không dính thùng trước.
     */
    public function test_product_scan_binds_carton_and_loose_item_stays_unbound(): void
    {
        $ctx = $this->draftProductInbound('CTN-SCAN-SKU-001', 2);
        $cartonId = $this->cartonFixture('CTN-SCAN-001', $ctx['sku_id']);
        $itemInCarton = $this->seedReceivableItem($ctx['sku_id']);
        $looseItem = $this->seedReceivableItem($ctx['sku_id']);

        $movementsBefore = DB::table('stock_movements')->count();
        $balancesBefore = DB::table('inventory_balances')->count();

        $this->scanItemIntoDocument($ctx, $itemInCarton, 1, $cartonId)
            ->assertOk()
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.scanned_quantity', 1);
        $this->assertDatabaseHas('inbound_scan_entries', [
            'inbound_line_id' => $ctx['line_id'],
            'item_id' => $itemInCarton,
            'container_id' => $cartonId,
            'status' => 'ACTIVE',
        ]);

        // Máy lẻ: không gửi container_id — entry mới phải có container_id null.
        $this->scanItemIntoDocument($ctx, $looseItem, 2)
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2);
        $looseEntry = DB::table('inbound_scan_entries')
            ->where('inbound_line_id', $ctx['line_id'])
            ->where('item_id', $looseItem)
            ->first();
        $this->assertNotNull($looseEntry);
        $this->assertNull($looseEntry->container_id, 'Máy lẻ không được gắn vào carton đã kết thúc.');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), 'Scan carton phải stock-neutral.');
        $this->assertSame($balancesBefore, DB::table('inventory_balances')->count(), 'Scan carton không được tạo balance.');
    }

    /**
     * §3.2 validation: carton không tồn tại / sai loại (BOX) / primary SKU không
     * khớp dòng nhập đều bị reject và không tạo scan entry.
     */
    public function test_product_scan_rejects_invalid_carton_context(): void
    {
        $ctx = $this->draftProductInbound('CTN-VALID-SKU-001', 1);
        $otherSku = $this->productSkuFixture('CTN-VALID-SKU-OTHER');
        $item = $this->seedReceivableItem($ctx['sku_id']);
        $entriesBefore = DB::table('inbound_scan_entries')->count();

        // Carton không tồn tại.
        $this->scanItemIntoDocument($ctx, $item, 1, (string) Str::uuid())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CARTON_NOT_FOUND');

        // Container loại BOX không được nhận PRODUCT item.
        $boxId = $this->cartonFixture('CTN-VALID-BOX-001', $ctx['sku_id'], 'BOX');
        $this->scanItemIntoDocument($ctx, $item, 1, $boxId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CARTON_TYPE_INVALID');

        // Carton đăng ký primary SKU khác SKU của dòng nhập.
        $wrongSkuCarton = $this->cartonFixture('CTN-VALID-WRONG-SKU', $otherSku->sku_id);
        $this->scanItemIntoDocument($ctx, $item, 1, $wrongSkuCarton)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CARTON_SKU_MISMATCH');

        // Carton CLOSED không còn nhận scan mới.
        $closedCarton = $this->cartonFixture('CTN-VALID-CLOSED', $ctx['sku_id'], 'CARTON', 'CLOSED');
        $this->scanItemIntoDocument($ctx, $item, 1, $closedCarton)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CARTON_NOT_USABLE');

        $this->assertSame(
            $entriesBefore,
            DB::table('inbound_scan_entries')->count(),
            'Scan bị reject không được để lại inbound_scan_entries.'
        );
        $this->assertDatabaseHas('stock_document_lines', ['id' => $ctx['line_id'], 'scanned_qty' => 0]);
    }

    /**
     * CTN-T03 (§3.3): complete-scan là audit-only — actual_item_qty đếm ACTIVE
     * entries của carton trong phiếu; container.status và document version giữ
     * nguyên; không movement/balance. CTN-T11: gọi lại lần hai vẫn 200, không có
     * side effect nghiệp vụ mới (chỉ thêm một dòng audit lịch sử).
     */
    public function test_complete_scan_is_audit_only_and_duplicate_harmless(): void
    {
        $ctx = $this->draftProductInbound('CTN-COMPLETE-SKU-001', 3);
        $cartonId = $this->cartonFixture('CTN-COMPLETE-001', $ctx['sku_id']);
        $this->scanItemIntoDocument($ctx, $this->seedReceivableItem($ctx['sku_id']), 1, $cartonId)->assertOk();
        $this->scanItemIntoDocument($ctx, $this->seedReceivableItem($ctx['sku_id']), 2, $cartonId)->assertOk();

        $versionBefore = (int) DB::table('stock_documents')->where('id', $ctx['document_id'])->value('version');
        $cartonStatusBefore = (string) DB::table('containers')->where('container_id', $cartonId)->value('status');
        $movementsBefore = DB::table('stock_movements')->count();

        $complete = $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        );
        $complete->assertOk()
            ->assertJsonPath('data.document_id', $ctx['document_id'])
            ->assertJsonPath('data.container_id', $cartonId)
            ->assertJsonPath('data.carton_code', 'CTN-COMPLETE-001')
            ->assertJsonPath('data.actual_item_qty', 2)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $this->assertNotEmpty($complete->json('data.completed_at'));

        // CTN-02/CTN-03: không movement, không đổi container.status, không bump version.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(
            $cartonStatusBefore,
            (string) DB::table('containers')->where('container_id', $cartonId)->value('status'),
            'Complete-scan không được đổi container.status (CTN-03).'
        );
        $this->assertSame(
            $versionBefore,
            (int) DB::table('stock_documents')->where('id', $ctx['document_id'])->value('version'),
            'Complete-scan là audit-only, không bump document version.'
        );

        // Mốc được ghi nhận bằng audit event, không phải state mới.
        $this->assertDatabaseHas('audit_log', [
            'action' => 'INBOUND_CARTON_SCAN_COMPLETED',
            'object_type' => 'container',
            'object_id' => $cartonId,
        ]);

        // CTN-T11: retry/duplicate call harmless — vẫn 200 với cùng actual_item_qty.
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        )->assertOk()->assertJsonPath('data.actual_item_qty', 2);
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    /**
     * Guard complete-scan: carton chưa có ACTIVE entry trong phiếu → 422; entry đã
     * unscan (REMOVED) không được đếm; container loại BOX → 422; carton lạ → 404.
     */
    public function test_complete_scan_guards(): void
    {
        $ctx = $this->draftProductInbound('CTN-GUARD-SKU-001', 2);
        $cartonId = $this->cartonFixture('CTN-GUARD-001', $ctx['sku_id']);

        // Chưa quét gì vào carton → không có gì để xác nhận.
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        )->assertStatus(422)->assertJsonPath('error.code', 'CARTON_NO_ACTIVE_SCANS');

        // Entry REMOVED không còn tính là ACTIVE (ERD bảng 28).
        $item = $this->seedReceivableItem($ctx['sku_id']);
        $this->scanItemIntoDocument($ctx, $item, 1, $cartonId)->assertOk();
        $entryId = (string) DB::table('inbound_scan_entries')
            ->where('inbound_line_id', $ctx['line_id'])
            ->where('item_id', $item)
            ->value('id');
        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/unscan", [
            'inbound_scan_entry_id' => $entryId,
        ], $ctx['headers'] + ['If-Match' => '2'])->assertOk();
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        )->assertStatus(422)->assertJsonPath('error.code', 'CARTON_NO_ACTIVE_SCANS');

        // BOX không có mốc "xong thùng" của luồng PRODUCT carton.
        $boxId = $this->cartonFixture('CTN-GUARD-BOX-001', $ctx['sku_id'], 'BOX');
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$boxId}/complete-scan",
            [],
            $ctx['headers']
        )->assertStatus(422)->assertJsonPath('error.code', 'CARTON_TYPE_INVALID');

        // Carton không tồn tại.
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/".Str::uuid().'/complete-scan',
            [],
            $ctx['headers']
        )->assertStatus(404)->assertJsonPath('error.code', 'CARTON_NOT_FOUND');
    }

    /**
     * CTN-T08/CTN-07: sau khi complete phát hiện sót — scan lại cùng carton (không
     * cần REOPEN endpoint), quét thêm item rồi complete lần nữa; actual_item_qty
     * phản ánh tổng ACTIVE entries mới.
     */
    public function test_rescan_same_carton_after_complete_supplements_missed_item(): void
    {
        $ctx = $this->draftProductInbound('CTN-RESCAN-SKU-001', 2);
        $cartonId = $this->cartonFixture('CTN-RESCAN-001', $ctx['sku_id']);

        $this->scanItemIntoDocument($ctx, $this->seedReceivableItem($ctx['sku_id']), 1, $cartonId)->assertOk();
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        )->assertOk()->assertJsonPath('data.actual_item_qty', 1);

        // Phát hiện sót 1 máy: FE set lại active carton (không có state server nào
        // chặn) và item bổ sung tiếp tục mang container_id của chính carton đó.
        $this->scanItemIntoDocument($ctx, $this->seedReceivableItem($ctx['sku_id']), 2, $cartonId)->assertOk();
        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        )->assertOk()->assertJsonPath('data.actual_item_qty', 2);

        $this->assertSame(
            2,
            DB::table('audit_log')->where('action', 'INBOUND_CARTON_SCAN_COMPLETED')->where('object_id', $cartonId)->count(),
            'Mỗi lần bấm xong thùng là một audit event lịch sử riêng.'
        );
    }

    /** Complete-scan trên phiếu đã POSTED bị chặn bằng STATE_CONFLICT (§3.3 guard). */
    public function test_complete_scan_rejected_after_document_posted(): void
    {
        $ctx = $this->draftProductInbound('CTN-POSTED-SKU-001', 1);
        $cartonId = $this->cartonFixture('CTN-POSTED-001', $ctx['sku_id']);
        $this->scanItemIntoDocument($ctx, $this->seedReceivableItem($ctx['sku_id']), 1, $cartonId)->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/post-receipt", [], $ctx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'ctn-posted-'.Str::lower(Str::random(8)),
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $this->postJson(
            "/api/v1/inbound-documents/{$ctx['document_id']}/cartons/{$cartonId}/complete-scan",
            [],
            $ctx['headers']
        )->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    }
}
