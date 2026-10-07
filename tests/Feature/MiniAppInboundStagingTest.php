<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class MiniAppInboundStagingTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_resolve_is_read_only_then_record_creates_waiting_approval_receipt_without_stock_effect(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-IN-STAGE-001');
        $raw1 = 'HN1|SKU='.$sku->sku_code.'|ITEM=DCJZ1240-001|SER=DCJZ1240-001';
        $raw2 = 'HN1|SKU='.$sku->sku_code.'|ITEM=DCJZ1240-002|SER=DCJZ1240-002';

        $documentsBefore = DB::table('stock_documents')->where('doc_type', 'receipt')->count();
        $entriesBefore = DB::table('inbound_scan_entries')->count();
        $movementsBefore = DB::table('stock_movements')->count();
        $balancesBefore = DB::table('inventory_balances')->count();

        $this->postJson('/api/v1/mini-app/inbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id,
            'raw_code' => $raw1,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.sku_id', (string) $sku->sku_id)
            ->assertJsonPath('data.sku_code', (string) $sku->sku_code)
            ->assertJsonPath('data.item_id', null)
            ->assertJsonPath('data.resolution_status', 'NEW_ITEM_CANDIDATE')
            ->assertJsonPath('data.wms_document_created', false)
            ->assertJsonPath('data.scan_evidence_created', false)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->assertSame($documentsBefore, DB::table('stock_documents')->where('doc_type', 'receipt')->count());
        $this->assertSame($entriesBefore, DB::table('inbound_scan_entries')->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        $record = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap Mini App scan truoc',
            'dst_warehouse_id' => (string) $warehouse->id,
            'expected_total_qty' => 2,
            'items' => [
                ['raw_code' => $raw1, 'scan_source' => 'CAMERA'],
                ['raw_code' => $raw2, 'scan_source' => 'SCANNER'],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-record-001'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.mini_app_status', 'WAITING_APPROVAL')
            ->assertJsonPath('data.ready_for_post', true)
            ->assertJsonPath('data.expected_total_qty', 2)
            ->assertJsonPath('data.scanned_total_qty', 2)
            ->assertJsonPath('data.remaining_qty', 0)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.scan_evidence_created', true)
            ->assertJsonPath('data.scan_evidence_count', 2)
            ->assertJsonCount(1, 'data.sku_lines')
            ->assertJsonPath('data.sku_lines.0.qty_planned', 2)
            ->assertJsonPath('data.sku_lines.0.scanned_qty', 2);

        $documentId = (string) $record->json('data.document_id');
        $this->assertNotSame('', $documentId);
        $this->assertSame(2, DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('e.status', 'ACTIVE')
            ->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($balancesBefore, DB::table('inventory_balances')->count());
        $this->assertDatabaseMissing('item_instances', ['serial_number' => 'DCJZ1240-001']);
        $this->assertDatabaseMissing('physical_codes', ['code_value' => 'DCJZ1240-001']);
    }

    public function test_record_accepts_resolve_code_row_verbatim_including_warehouse_id_alias(): void
    {
        // Mini App gửi lại nguyên row local đã resolve: field kho mang tên
        // `warehouse_id` (tên của bước resolve-code) và mỗi row còn kèm các field
        // echo. Cả hai đều KHÔNG được làm hỏng batch.
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-IN-ALIAS-001');
        $raw = 'HN1|SKU='.$sku->sku_code.'|ITEM=DCALIAS-0001|SER=DCALIAS-0001';

        $resolved = $this->postJson('/api/v1/mini-app/inbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id,
            'raw_code' => $raw,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.item_type', 'ITEM')
            ->assertJsonPath('data.requires_quantity', false)
            ->json('data');

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap gui lai row resolve-code',
            'warehouse_id' => (string) $warehouse->id,
            'items' => [
                [
                    'raw_code' => $raw,
                    'scan_source' => 'CAMERA',
                    'item_type' => 'ITEM',
                    'quantity' => 1,
                    'sku_id' => $resolved['sku_id'],
                    'sku_code' => $resolved['sku_code'],
                    'item_id' => $resolved['item_id'],
                    'item_unique' => $resolved['item_unique'],
                    'resolution_status' => $resolved['resolution_status'],
                ],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-alias-001'])
            ->assertCreated()
            ->assertJsonPath('data.dst_warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.ready_for_post', true)
            ->assertJsonPath('data.scanned_total_qty', 1)
            ->assertJsonPath('data.items.0.item_type', 'ITEM')
            ->assertJsonPath('data.items.0.quantity', 1);
    }

    public function test_record_rejects_warehouse_id_alias_conflicting_with_dst_warehouse_id(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $documentsBefore = DB::table('stock_documents')->where('doc_type', 'receipt')->count();

        // dst_warehouse_id giữ đúng kho canonical để guard
        // EnsureCanonicalInboundWarehouse cho đi qua — phần phải bắt ở đây là
        // alias trỏ sang một kho khác, không phải kho sai canonical.
        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Hai kho khac nhau',
            'warehouse_id' => (string) Str::uuid(),
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [
                ['raw_code' => 'HN1|SKU=PROD-MINI-IN-CONFLICT|ITEM=ITEM-CONFLICT', 'scan_source' => 'CAMERA'],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-alias-conflict'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'WAREHOUSE_ID_CONFLICT');

        $this->assertSame($documentsBefore, DB::table('stock_documents')->where('doc_type', 'receipt')->count());
    }

    public function test_resolve_and_record_handle_component_box_code_with_manual_quantity(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $boxCode = 'BOX-HN-COMP-MINI-001-0007';
        $movementsBefore = DB::table('stock_movements')->count();

        // Mã hộp tự mang SKU: resolve-code không được abort INVALID_PAYLOAD mà
        // phải báo BOX + yêu cầu nhập số lượng, và vẫn không ghi gì.
        $this->postJson('/api/v1/mini-app/inbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id,
            'raw_code' => $boxCode,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.item_type', 'BOX')
            ->assertJsonPath('data.requires_quantity', true)
            ->assertJsonPath('data.box_code', $boxCode)
            ->assertJsonPath('data.box_number', '0007')
            ->assertJsonPath('data.sku_code', 'HN-COMP-MINI-001')
            ->assertJsonPath('data.sku_type', 'COMPONENT')
            ->assertJsonPath('data.resolution_status', 'NEW_SKU_CANDIDATE')
            ->assertJsonPath('data.wms_document_created', false);
        $this->assertDatabaseMissing('sku', ['sku_code' => 'HN-COMP-MINI-001']);

        // Thiếu quantity là lỗi rõ ràng, không được im lặng ghi 1.
        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap hop thieu so luong',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => $boxCode, 'scan_source' => 'CAMERA', 'item_type' => 'BOX']],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-box-noqty'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BOX_QUANTITY_REQUIRED');

        $record = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap hop linh kien',
            'warehouse_id' => (string) $warehouse->id,
            'items' => [
                [
                    'raw_code' => $boxCode,
                    'scan_source' => 'CAMERA',
                    'item_type' => 'BOX',
                    'quantity' => 50,
                    'box_number' => '0007',
                    'sku_code' => 'HN-COMP-MINI-001',
                ],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-box-001'])
            ->assertCreated()
            ->assertJsonPath('data.ready_for_post', true)
            ->assertJsonPath('data.expected_total_qty', 50)
            ->assertJsonPath('data.scanned_total_qty', 50)
            ->assertJsonPath('data.remaining_qty', 0)
            ->assertJsonPath('data.scan_evidence_count', 1)
            ->assertJsonPath('data.items.0.item_type', 'BOX')
            ->assertJsonPath('data.items.0.quantity', 50)
            ->assertJsonPath('data.items.0.box_code', $boxCode)
            ->assertJsonCount(1, 'data.sku_lines')
            ->assertJsonPath('data.sku_lines.0.qty_planned', 50)
            ->assertJsonPath('data.sku_lines.0.scanned_qty', 50);

        // SKU linh kiện được materialize từ mã hộp; hộp thành container BOX.
        $sku = DB::table('sku')->where('sku_code', 'HN-COMP-MINI-001')->first();
        $this->assertNotNull($sku);
        $this->assertSame('COMPONENT', strtoupper((string) $sku->sku_type));

        $container = DB::table('containers')->where('container_code', $boxCode)->first();
        $this->assertNotNull($container);
        $this->assertSame('BOX', strtoupper((string) $container->container_type));
        $this->assertSame((string) $sku->sku_id, (string) $container->primary_sku_id);

        $documentId = (string) $record->json('data.document_id');
        $entry = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->first(['e.item_id', 'e.container_id', 'e.quantity']);
        $this->assertNotNull($entry);
        $this->assertNull($entry->item_id);
        $this->assertSame((string) $container->container_id, (string) $entry->container_id);
        $this->assertSame(50, (int) $entry->quantity);

        // Vẫn stock-neutral: tồn chỉ tăng ở post-receipt.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    public function test_record_accepts_batch_mixing_item_rows_and_several_boxes_of_the_same_component(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-IN-MIX-001');
        $raw = 'HN1|SKU='.$sku->sku_code.'|ITEM=DCMIX-0001|SER=DCMIX-0001';

        $record = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap tron item va nhieu hop',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [
                ['raw_code' => $raw, 'scan_source' => 'CAMERA'],
                ['raw_code' => 'BOX-HN-COMP-MINI-MIX-0001', 'item_type' => 'BOX', 'quantity' => 30],
                ['raw_code' => 'BOX-HN-COMP-MINI-MIX-0002', 'item_type' => 'BOX', 'quantity' => 12],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-mix-001'])
            ->assertCreated()
            ->assertJsonPath('data.ready_for_post', true)
            // 1 hiện vật + 30 + 12 linh kiện: tổng theo SỐ LƯỢNG, không theo số dòng.
            ->assertJsonPath('data.expected_total_qty', 43)
            ->assertJsonPath('data.scanned_total_qty', 43)
            ->assertJsonPath('data.scan_evidence_count', 3)
            ->assertJsonCount(2, 'data.sku_lines');

        $documentId = (string) $record->json('data.document_id');

        // Hai hộp cùng SKU dồn vào một dòng nhưng là hai bằng chứng quét riêng,
        // mỗi entry trỏ đúng container của nó.
        $componentSkuId = (string) DB::table('sku')->where('sku_code', 'HN-COMP-MINI-MIX')->value('sku_id');
        $this->assertNotSame('', $componentSkuId);

        $entries = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('containers as c', 'c.container_id', '=', 'e.container_id')
            ->where('l.stock_document_id', $documentId)
            ->where('l.product_id', $componentSkuId)
            ->orderBy('c.container_code')
            ->get(['c.container_code', 'e.quantity']);

        $this->assertSame(
            ['BOX-HN-COMP-MINI-MIX-0001' => 30, 'BOX-HN-COMP-MINI-MIX-0002' => 12],
            $entries->pluck('quantity', 'container_code')->map(fn ($qty) => (int) $qty)->all(),
        );

        $this->assertSame([43], [(int) DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->sum('scanned_qty')]);
    }

    public function test_record_rejects_item_type_and_quantity_that_contradict_the_scanned_code(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-IN-CONTRA-001');
        $raw = 'HN1|SKU='.$sku->sku_code.'|ITEM=DCCONTRA-0001';
        $documentsBefore = DB::table('stock_documents')->where('doc_type', 'receipt')->count();

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Item khai la BOX',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => $raw, 'item_type' => 'BOX', 'quantity' => 5]],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-contra-type'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ITEM_TYPE_MISMATCH');

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Item khai so luong 5',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => $raw, 'quantity' => 5]],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-contra-qty'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ITEM_QUANTITY_MUST_BE_ONE');

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Hop khai sai so hop',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [[
                'raw_code' => 'BOX-HN-COMP-MINI-002-0003',
                'item_type' => 'BOX',
                'quantity' => 4,
                'box_number' => '9',
            ]],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-contra-box'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BOX_NUMBER_MISMATCH');

        $this->assertSame($documentsBefore, DB::table('stock_documents')->where('doc_type', 'receipt')->count());
    }

    public function test_record_rejects_unexpected_payload_fields_before_creating_document(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $documentsBefore = DB::table('stock_documents')->where('doc_type', 'receipt')->count();

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Batch has extra FE state',
            'dst_warehouse_id' => (string) $warehouse->id,
            'expected_total_qty' => 1,
            'client_state' => ['screen' => 'review'],
            'items' => [
                [
                    'raw_code' => 'HN1|SKU=PROD-MINI-IN-STAGE-EXTRA|ITEM=ITEM-EXTRA',
                    'scan_source' => 'CAMERA',
                    'resolved_item_id' => 'client-only',
                ],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-inbound-stage-extra-fields'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.unexpected_fields.0', 'client_state')
            ->assertJsonPath('errors.unexpected_fields.1', 'items.1.resolved_item_id');

        $this->assertSame($documentsBefore, DB::table('stock_documents')->where('doc_type', 'receipt')->count());
    }

    public function test_inbound_create_surfaces_do_not_depend_on_dirty_outbound_agency_history(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-IN-AGENCY-ISO');
        $this->seedDirtyOutboundAgencyHistory((string) $warehouse->id, (string) $headers['__user_id']);

        // Hợp đồng GĐ1 scan-driven: canonical create là HEADER-ONLY (gửi lines
        // sẽ bị 422 INBOUND_SCAN_DRIVEN — dòng SKU materialize từ scan thực tế).
        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => (string) $warehouse->id,
            'source_name' => 'Canonical inbound isolated from outbound history',
        ], $headers + ['Idempotency-Key' => 'inbound-dirty-outbound-canonical'])
            ->assertCreated()
            ->assertJsonPath('data.doc_type', 'receipt');

        $this->postJson('/api/v1/mini-app/inbound-documents', [
            'dst_warehouse_id' => (string) $warehouse->id,
            'source_name' => 'Mini app inbound adapter isolated from outbound history',
            'expected_qty' => 1,
            'product_id' => (string) $sku->sku_id,
        ], $headers + ['Idempotency-Key' => 'inbound-dirty-outbound-mini-adapter'])
            ->assertCreated()
            ->assertJsonPath('data.doc_type', 'receipt');

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Mini app inbound record isolated from outbound history',
            'dst_warehouse_id' => (string) $warehouse->id,
            'expected_total_qty' => 1,
            'items' => [[
                'raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM=DIRTY-OUTBOUND-ISO-001|SER=DIRTY-OUTBOUND-ISO-001',
                'scan_source' => 'CAMERA',
            ]],
        ], $headers + ['Idempotency-Key' => 'inbound-dirty-outbound-record'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.movement_created', false);
    }

    public function test_outbound_document_mirror_migration_is_row_scoped_to_issue_documents(): void
    {
        $source = (string) file_get_contents(base_path(
            'Modules/Warehouse/database/migrations/2026_08_24_110000_scope_outbound_document_mirror_to_changed_issue_document.php'
        ));

        $this->assertStringContainsString('FOR EACH ROW', $source);
        $this->assertStringContainsString("NEW.doc_type IS DISTINCT FROM 'issue'", $source);
        $this->assertStringContainsString("OLD.doc_type IS DISTINCT FROM 'issue'", $source);
        $this->assertStringContainsString('WHERE "id" = v_document_id', $source);
        $this->assertStringNotContainsString('FOR EACH STATEMENT', $source);
    }

    public function test_record_reuses_mixed_case_existing_sku_without_creating_a_duplicate(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('prod-P04-MixedCase');
        $before = DB::table('sku')->count();
        $raw = 'HN1|SKU=PROD-P04-MIXEDCASE|ITEM=P04-MIXED-CASE-ONE';
        $this->postJson('/api/v1/mini-app/inbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id, 'raw_code' => $raw,
        ], $headers)->assertOk()->assertJsonPath('data.sku_id', (string) $sku->sku_id);
        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Mixed-case existing SKU', 'warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => $raw, 'quantity' => 1]],
        ], $headers + ['Idempotency-Key' => 'p04-mixed-case-existing-sku'])->assertCreated()
            ->assertJsonPath('data.items.0.sku_id', (string) $sku->sku_id)
            ->assertJsonPath('data.stock_effect', 'NONE');
        $this->assertSame($before, DB::table('sku')->count());
    }

    private function seedDirtyOutboundAgencyHistory(string $warehouseId, string $actorId): void
    {
        $inactiveAgencyId = (string) Str::uuid();
        $agencyRow = [
            'agency_id' => $inactiveAgencyId,
            'agency_code' => 'AG-DIRTY-'.Str::upper(Str::random(6)),
            'agency_name' => 'Inactive historical agency',
            // The agency was active when the historical document was created.
            // Withdraw it AFTER creation; never bypass the PostgreSQL guard.
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('agencies', 'id')) {
            $agencyRow['id'] = $inactiveAgencyId;
        }
        DB::table('agencies')->insert($agencyRow);

        foreach ([$inactiveAgencyId, null] as $index => $agencyId) {
            DB::table('stock_documents')->insert([
                'id' => (string) Str::uuid(),
                'doc_type' => 'issue',
                'doc_no' => 'PX-DIRTY-'.Str::upper(Str::random(8)),
                'doc_date' => now()->toDateString(),
                'status' => 'DRAFT',
                'src_warehouse_id' => $warehouseId,
                'dst_warehouse_id' => null,
                'agency_id' => $agencyId,
                'recipient_name' => 'Historical recipient '.$index,
                'recipient_address' => 'Historical address',
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('agencies')->where('agency_id', $inactiveAgencyId)->update(['status' => 'INACTIVE']);
        $this->assertDatabaseHas('agencies', ['agency_id' => $inactiveAgencyId, 'status' => 'INACTIVE']);
        $this->assertSame(1, DB::table('stock_documents')->where('doc_type', 'issue')->where('agency_id', $inactiveAgencyId)->count());
    }
}
