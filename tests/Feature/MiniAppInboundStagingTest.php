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

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => (string) $warehouse->id,
            'source_name' => 'Canonical inbound isolated from outbound history',
            'lines' => [[
                'product_id' => (string) $sku->sku_id,
                'qty_planned' => 1,
            ]],
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

    private function seedDirtyOutboundAgencyHistory(string $warehouseId, string $actorId): void
    {
        $inactiveAgencyId = (string) Str::uuid();
        $agencyRow = [
            'agency_id' => $inactiveAgencyId,
            'agency_code' => 'AG-DIRTY-'.Str::upper(Str::random(6)),
            'agency_name' => 'Inactive historical agency',
            'status' => 'INACTIVE',
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
    }
}
