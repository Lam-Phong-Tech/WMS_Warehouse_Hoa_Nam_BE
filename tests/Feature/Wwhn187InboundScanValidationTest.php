<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-187 (HN-IN-002, BE2): "Triển khai quét/ghi nhận/validation" — Inbound scan backend.
 *
 * 2026-08-25 — viết lại theo hợp đồng GĐ1 scan-driven inbound (BA baseline):
 *
 *  - Phiếu nhập tạo HEADER-ONLY (BaM1InboundDocumentController); dòng SKU
 *    materialize từ composite QR `HN1|SKU=..|ITEM=..` (DiagnosticCurrentBaseline
 *    StockService::prepareScanDrivenInboundLine) — không còn tạo phiếu kèm
 *    lines/qty_planned qua API.
 *  - Không còn gate "quét vượt dự kiến" (SCAN_EXCEEDS_REQUIRED): qty_planned tự
 *    nâng theo scan thực tế.
 *  - Identity đã tồn tại trong DB (bất kể item_status) bị chặn khỏi inbound
 *    thường bằng 409 ITEM_ALREADY_REGISTERED (GĐ1-REQ-008); nhận hàng PRODUCT
 *    đi qua new-item-candidate (register-at-Post, DEC-QR-03).
 *  - COMPONENT theo Box: dòng materialize qua /component-lines, Box qua /boxes,
 *    scan quantity-mode; các guard BOX của EnsureInboundComponentBox giữ nguyên.
 *
 * Các guard hạ tầng (auth/permission/If-Match/version/line/box) vẫn được lái qua
 * HTTP thật như bản gốc WWHN-187 — chỉ fixture đổi sang scan-driven.
 */
class Wwhn187InboundScanValidationTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /**
     * Header-only document + SKU PRODUCT đã khai master — chưa có dòng nào
     * (dòng chỉ xuất hiện khi scan composite QR).
     */
    private function scanDrivenProductContext(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $document = $this->createInboundHeader($headers, (string) $warehouse->id);

        return [
            'headers' => $headers,
            'document_id' => $document['document_id'],
            'version' => $document['version'],
            'sku_code' => $skuCode,
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    /**
     * Materialize sẵn một dòng PRODUCT bằng đúng đường scan composite (1 item),
     * trả về line_id + version hiện hành cho các guard cần line có thật.
     */
    private function scanDrivenProductContextWithLine(string $skuCode): array
    {
        $ctx = $this->scanDrivenProductContext($skuCode);
        $scanned = $this->scanProductItems(
            $ctx['headers'],
            $ctx['document_id'],
            $skuCode,
            1,
            $ctx['version']
        );
        $ctx['line_id'] = $scanned['line_id'];
        $ctx['version'] = $scanned['version'];

        return $ctx;
    }

    /** Header-only document + SKU COMPONENT + dòng materialize qua /component-lines. */
    private function scanDrivenComponentContext(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSkuFixture($skuCode);
        $document = $this->createInboundHeader($headers, (string) $warehouse->id);

        $ensure = $this->postJson(
            "/api/v1/inbound-documents/{$document['document_id']}/component-lines",
            ['sku_id' => (string) $sku->sku_id],
            $headers
        )->assertOk();

        return [
            'headers' => $headers,
            'document_id' => $document['document_id'],
            'version' => $document['version'],
            'line_id' => (string) $ensure->json('data.line_id'),
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    public function test_scan_requires_authentication(): void
    {
        // Không gọi bất kỳ helper nào login trước — guard JWT giữ user đã login
        // xuyên suốt các postJson() nội bộ cùng 1 test method nếu không override
        // Authorization, nên phải tự seed document/line qua DB trực tiếp để cô
        // lập hoàn toàn khỏi trạng thái auth.
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('IN-SCAN-001');
        $ownerUser = User::create([
            'username' => 'seed-'.Str::lower(Str::random(8)),
            'display_name' => 'Seed Owner',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $documentId = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_no' => 'IN-SCAN-001-'.Str::upper(Str::random(6)),
            'doc_type' => 'receipt',
            'doc_date' => now()->toDateString(),
            'dst_warehouse_id' => $warehouse->id,
            'status' => 'DRAFT',
            'version' => 1,
            'created_by' => $ownerUser->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lineId = (string) Str::uuid();
        DB::table('stock_document_lines')->insert([
            'id' => $lineId,
            'stock_document_id' => $documentId,
            'line_no' => 1,
            'product_id' => $sku->sku_id,
            'qty_planned' => 1,
            // chk_stock_document_line_integer_positive: qty_actual > 0.
            'qty_actual' => 1,
            'scanned_qty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
        ], ['If-Match' => '1'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_scan_requires_inbound_scan_permission(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-002');
        $approverHeaders = $this->authHeadersWithRole('WAREHOUSE_APPROVER');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-002', 'SN-IN-SCAN-002-0001'),
        ], $approverHeaders + ['If-Match' => (string) $ctx['version']]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $response->assertJsonMissingPath('error.trace')->assertJsonMissingPath('error.stack');
        $this->assertSame(0, DB::table('inbound_scan_entries')->count());
    }

    public function test_scan_requires_if_match_header(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-003');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-003', 'SN-IN-SCAN-003-0001'),
        ], $ctx['headers'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
    }

    public function test_scan_rejects_stale_version_with_412(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-004');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-004', 'SN-IN-SCAN-004-0001'),
        ], $ctx['headers'] + ['If-Match' => '99']);

        $response->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertDatabaseHas('stock_documents', ['id' => $ctx['document_id'], 'status' => 'DRAFT', 'version' => 1]);
    }

    public function test_scan_returns_404_for_unknown_document(): void
    {
        $headers = $this->superAdminHeaders();

        $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/scan', [
            'line_id' => (string) Str::uuid(),
            'quantity' => 1,
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_scan_rejects_unknown_line_id(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-005');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => (string) Str::uuid(),
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'DOCUMENT_LINE_NOT_FOUND');
    }

    public function test_scan_rejects_zero_quantity(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-006');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 0,
            'code_value' => $this->compositeProductQr('IN-SCAN-006', 'SN-IN-SCAN-006-0001'),
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422);
    }

    /**
     * Hợp đồng scan-driven: KHÔNG còn SCAN_EXCEEDS_REQUIRED — qty_planned tự
     * nâng theo scan nên quét thêm bao nhiêu cũng được ghi nhận đủ bấy nhiêu.
     * (Thay cho test cũ assert 422 SCAN_EXCEEDS_REQUIRED khi quantity > planned.)
     */
    public function test_scan_beyond_any_prior_quantity_is_accepted_scan_driven(): void
    {
        $ctx = $this->scanDrivenComponentContext('IN-SCAN-007');
        $tray = Container::create([
            'container_code' => 'BOX-IN-SCAN-007',
            'container_type' => 'BOX',
            'primary_sku_id' => $ctx['sku_id'],
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $first = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 3,
            'container_id' => $tray->container_id,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);
        $first->assertOk()->assertJsonPath('data.scanned_quantity', 3);

        $second = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 4,
            'container_id' => $tray->container_id,
        ], $ctx['headers'] + ['If-Match' => (string) $first->json('data.version')]);
        $second->assertOk()->assertJsonPath('data.scanned_quantity', 7);

        $this->assertSame(7, (int) DB::table('stock_document_lines')->where('id', $ctx['line_id'])->value('scanned_qty'));
    }

    public function test_scan_rejects_inactive_sku(): void
    {
        $ctx = $this->scanDrivenProductContextWithLine('IN-SCAN-008');
        DB::table('sku')->where('sku_id', $ctx['sku_id'])->update(['status' => 'INACTIVE']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_NOT_ACTIVE');
    }

    public function test_scan_product_line_without_item_is_rejected(): void
    {
        $ctx = $this->scanDrivenProductContextWithLine('IN-SCAN-009');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_REQUIRED');
    }

    public function test_scan_serial_tracked_line_rejects_quantity_greater_than_one(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-010');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 2,
            'code_value' => $this->compositeProductQr('IN-SCAN-010', 'SN-IN-SCAN-010-0001'),
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SERIAL_QUANTITY_MUST_BE_ONE');
    }

    public function test_scan_rejects_item_belonging_to_different_sku(): void
    {
        $ctx = $this->scanDrivenProductContextWithLine('IN-SCAN-011');
        $otherSku = $this->productSkuFixture('IN-SCAN-011-OTHER');
        $foreignItemId = $this->seedItemInStock($otherSku->sku_id);
        DB::table('item_instances')->where('item_id', $foreignItemId)->update(['item_status' => 'NEW']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $foreignItemId,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_SKU_MISMATCH');
    }

    /**
     * GĐ1-REQ-008: identity đã tồn tại trong DB (bất kể item_status, kể cả
     * IN_STOCK) không được nhận lại qua inbound thường — 409
     * ITEM_ALREADY_REGISTERED, sửa sai đi qua luồng reversal/return.
     * (Thay cho test cũ assert 422 ITEM_NOT_RECEIVABLE.)
     */
    public function test_scan_rejects_item_already_registered_in_system(): void
    {
        $ctx = $this->scanDrivenProductContextWithLine('IN-SCAN-012');
        $itemId = $this->seedItemInStock($ctx['sku_id']);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_REGISTERED');
    }

    public function test_scan_with_unknown_code_value_is_rejected(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-013');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => 'QR-DOES-NOT-EXIST',
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
        $this->assertSame(0, DB::table('inbound_scan_entries')->count());
    }

    public function test_scan_with_inactive_code_value_is_rejected(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-014');
        $itemId = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'NEW']);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        DB::table('physical_codes')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'QR',
            'status' => 'LOST',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $codeValue,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
    }

    public function test_scan_rejects_mismatched_item_id_and_code_value(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-015');
        $itemA = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemA)->update(['item_status' => 'NEW']);
        $itemB = $this->seedItemInStock($ctx['sku_id']);
        DB::table('item_instances')->where('item_id', $itemB)->update(['item_status' => 'NEW']);
        $codeForB = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($itemB, $codeForB);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'item_id' => $itemA,
            'code_value' => $codeForB,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_ITEM_MISMATCH');
        $this->assertSame(0, DB::table('inbound_scan_entries')->count());
    }

    /**
     * Đường nhận hàng PRODUCT chính thống: composite QR → dòng SKU materialize,
     * entry candidate được ghi (item_id=null cho tới Post Receipt — DEC-QR-03).
     * (Thay cho test cũ resolve item ĐÃ TỒN TẠI qua code_value — hành vi đó nay
     * bị chặn bởi ITEM_ALREADY_REGISTERED.)
     */
    public function test_scan_composite_qr_materializes_line_and_records_candidate_entry(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-016');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-016', 'SN-IN-SCAN-016-0001'),
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);

        $response->assertOk()
            ->assertJsonPath('data.item_id', null)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.resolution_status', 'NEW_ITEM_CANDIDATE')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $lineId = (string) $response->json('data.line_id');
        $this->assertDatabaseHas('inbound_scan_entries', [
            'inbound_line_id' => $lineId,
            'candidate_item_unique' => 'SN-IN-SCAN-016-0001',
        ]);
        $this->assertSame(1, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
    }

    public function test_scanning_same_item_twice_into_same_inbound_document_is_rejected(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-017');
        $code = $this->compositeProductQr('IN-SCAN-017', 'SN-IN-SCAN-017-0001');

        $first = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $code,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);
        $first->assertOk();

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $code,
        ], $ctx['headers'] + ['If-Match' => (string) $first->json('data.version')]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_ITEM_IN_DOCUMENT');
        $this->assertSame(
            1,
            DB::table('inbound_scan_entries')->where('candidate_item_unique', 'SN-IN-SCAN-017-0001')->count()
        );
    }

    public function test_scan_rejects_document_not_in_draft_or_scanning_state(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('IN-SCAN-018');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => 'IN-SCAN-018', 'qty' => 1],
        ]);
        $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'])
            ->assertOk()->assertJsonPath('data.status', 'POSTED');

        $response = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-018', 'SN-IN-SCAN-018-EXTRA'),
        ], $headers + ['If-Match' => (string) ($fixture['version'] + 1)]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    public function test_scan_rejects_component_line_without_box(): void
    {
        $ctx = $this->scanDrivenComponentContext('IN-SCAN-019');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_BOX_REQUIRED');
    }

    public function test_scan_rejects_unknown_box_container_id(): void
    {
        $ctx = $this->scanDrivenComponentContext('IN-SCAN-020');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
            'container_id' => (string) Str::uuid(),
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_BOX_NOT_FOUND');
    }

    public function test_scan_rejects_box_with_mismatched_sku(): void
    {
        $ctx = $this->scanDrivenComponentContext('IN-SCAN-021');
        $otherSku = $this->componentSkuFixture('IN-SCAN-021-OTHER');
        $box = Container::create([
            'container_code' => 'BOX-IN-SCAN-021',
            'container_type' => 'BOX',
            'primary_sku_id' => $otherSku->sku_id,
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
            'container_id' => $box->container_id,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BOX_SKU_MISMATCH');
    }

    public function test_scan_rejects_box_not_in_receivable_state(): void
    {
        $ctx = $this->scanDrivenComponentContext('IN-SCAN-022');
        $box = Container::create([
            'container_code' => 'BOX-IN-SCAN-022',
            'container_type' => 'BOX',
            'primary_sku_id' => $ctx['sku_id'],
            'status' => 'STORED',
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 5,
            'container_id' => $box->container_id,
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'BOX_NOT_RECEIVABLE');
    }

    public function test_scan_rejects_box_already_used_by_other_active_receipt(): void
    {
        $ctxA = $this->scanDrivenComponentContext('IN-SCAN-023');
        $box = Container::create([
            'container_code' => 'BOX-IN-SCAN-023',
            'container_type' => 'BOX',
            'primary_sku_id' => $ctxA['sku_id'],
            'status' => 'EMPTY',
            'version' => 1,
        ]);
        $this->postJson("/api/v1/inbound-documents/{$ctxA['document_id']}/scan", [
            'line_id' => $ctxA['line_id'],
            'quantity' => 2,
            'container_id' => $box->container_id,
        ], $ctxA['headers'] + ['If-Match' => (string) $ctxA['version']])->assertOk();

        // Phiếu B header-only + materialize dòng COMPONENT cùng SKU.
        $documentB = $this->createInboundHeader($ctxA['headers'], (string) $ctxA['warehouse_id']);
        $ensureB = $this->postJson(
            "/api/v1/inbound-documents/{$documentB['document_id']}/component-lines",
            ['sku_id' => (string) $ctxA['sku_id']],
            $ctxA['headers']
        )->assertOk();
        $lineB = (string) $ensureB->json('data.line_id');

        $response = $this->postJson("/api/v1/inbound-documents/{$documentB['document_id']}/scan", [
            'line_id' => $lineB,
            'quantity' => 1,
            'container_id' => $box->container_id,
        ], $ctxA['headers'] + ['If-Match' => (string) $documentB['version']]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'BOX_IN_OTHER_ACTIVE_RECEIPT');
    }

    public function test_scanning_same_item_into_two_different_inbound_documents_is_rejected(): void
    {
        $ctxA = $this->scanDrivenProductContext('IN-SCAN-024-A');
        $code = $this->compositeProductQr('IN-SCAN-024-A', 'SN-IN-SCAN-024-0001');

        $this->postJson("/api/v1/inbound-documents/{$ctxA['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $code,
        ], $ctxA['headers'] + ['If-Match' => (string) $ctxA['version']])->assertOk();

        $documentB = $this->createInboundHeader($ctxA['headers'], (string) $ctxA['warehouse_id']);

        $response = $this->postJson("/api/v1/inbound-documents/{$documentB['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $code,
        ], $ctxA['headers'] + ['If-Match' => (string) $documentB['version']]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'ITEM_ALREADY_RECEIVING');
        $this->assertSame(
            1,
            DB::table('inbound_scan_entries')->where('candidate_item_unique', 'SN-IN-SCAN-024-0001')->count()
        );
    }

    public function test_scan_document_transitions_draft_to_scanning_and_bumps_version(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-025');

        $response = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-025', 'SN-IN-SCAN-025-0001'),
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2)
            ->assertHeader('ETag', '"2"');
        $this->assertDatabaseHas('stock_documents', ['id' => $ctx['document_id'], 'status' => 'SCANNING', 'version' => 2]);
    }

    public function test_scan_writes_scan_event_evidence_row(): void
    {
        $ctx = $this->scanDrivenProductContext('IN-SCAN-026');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", [
            'quantity' => 1,
            'code_value' => $this->compositeProductQr('IN-SCAN-026', 'SN-IN-SCAN-026-0001'),
        ], $ctx['headers'] + ['If-Match' => (string) $ctx['version']])->assertOk();

        // Candidate chưa có item_id — evidence NFR-SRS-004 resolve về SKU của dòng.
        $this->assertSame(1, DB::table('scan_event as se')
            ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
            ->where('sb.source_id', $ctx['document_id'])
            ->where('se.resolved_object_id', $ctx['sku_id'])
            ->where('se.resolved_object_type', 'SKU')
            ->count());
    }
}
