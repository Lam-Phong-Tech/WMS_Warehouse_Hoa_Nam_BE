<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class MiniAppOutboundStagingTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_resolve_is_read_only_then_record_groups_scanned_items_and_full_matches_without_stock_effect(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-STAGE-001');
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 2);

        $item1 = $this->seedItemInStock((string) $sku->sku_id);
        $item2 = $this->seedItemInStock((string) $sku->sku_id);
        $unique1 = 'MINI-STAGE-ITEM-001';
        $unique2 = 'MINI-STAGE-ITEM-002';
        $this->seedScanCode($item1, $unique1);
        $this->seedScanCode($item2, $unique2);

        $raw1 = 'HN1|SKU='.$sku->sku_code.'|ITEM='.$unique1;
        $raw2 = 'HN1|SKU='.$sku->sku_code.'|ITEM='.$unique2;
        $issueDocumentsBefore = DB::table('stock_documents')->where('doc_type', 'issue')->count();
        $matchesBefore = DB::table('outbound_item_matches')->count();
        $movementsBefore = DB::table('stock_movements')->count();

        $this->postJson('/api/v1/mini-app/outbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id,
            'raw_code' => $raw1,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.sku_id', (string) $sku->sku_id)
            ->assertJsonPath('data.sku_code', (string) $sku->sku_code)
            ->assertJsonPath('data.item_id', $item1)
            ->assertJsonPath('data.eligible_for_outbound', true)
            ->assertJsonPath('data.wms_document_created', false)
            ->assertJsonPath('data.match_created', false)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->assertSame($issueDocumentsBefore, DB::table('stock_documents')->where('doc_type', 'issue')->count());
        $this->assertSame($matchesBefore, DB::table('outbound_item_matches')->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        $recordHeaders = $headers + ['Idempotency-Key' => 'mini-outbound-stage-record-001'];
        $record = $this->postJson('/api/v1/mini-app/outbound/record', [
            'name' => 'Xuất Mini App scan trước',
            'warehouse_id' => (string) $warehouse->id,
            'recipient_name' => 'Đại lý Mini App',
            'expected_total_qty' => 2,
            'items' => [
                ['raw_code' => $raw1, 'scan_source' => 'CAMERA'],
                ['raw_code' => $raw2, 'scan_source' => 'SCANNER'],
            ],
        ], $recordHeaders)
            ->assertCreated()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.mini_app_status', 'READY_TO_ISSUE')
            ->assertJsonPath('data.full_scan', true)
            ->assertJsonPath('data.expected_total_qty', 2)
            ->assertJsonPath('data.scanned_total_qty', 2)
            ->assertJsonPath('data.remaining_qty', 0)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.scan_evidence_count', 2)
            ->assertJsonPath('data.packing_label.status', 'DRAFT')
            ->assertJsonCount(1, 'data.sku_lines')
            ->assertJsonPath('data.sku_lines.0.required_qty', 2)
            ->assertJsonPath('data.sku_lines.0.scanned_qty', 2);

        $documentId = (string) $record->json('data.document_id');
        $this->assertNotSame('', $documentId);
        $this->assertSame(2, DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('m.status', 'ACTIVE')
            ->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(2, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->value('available_qty'));

        $replay = $this->postJson('/api/v1/mini-app/outbound/record', [
            'name' => 'Xuất Mini App scan trước',
            'warehouse_id' => (string) $warehouse->id,
            'recipient_name' => 'Đại lý Mini App',
            'expected_total_qty' => 2,
            'items' => [
                ['raw_code' => $raw1, 'scan_source' => 'CAMERA'],
                ['raw_code' => $raw2, 'scan_source' => 'SCANNER'],
            ],
        ], $recordHeaders)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.document_id', $documentId);

        $this->assertSame(2, DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('m.status', 'ACTIVE')
            ->count());
    }

    public function test_record_rejects_duplicate_local_item_before_creating_document(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-STAGE-002');
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 2);
        $item = $this->seedItemInStock((string) $sku->sku_id);
        $unique = 'MINI-STAGE-DUP-001';
        $this->seedScanCode($item, $unique);
        $raw = 'HN1|SKU='.$sku->sku_code.'|ITEM='.$unique;
        $documentsBefore = DB::table('stock_documents')->where('doc_type', 'issue')->count();

        $this->postJson('/api/v1/mini-app/outbound/record', [
            'name' => 'Batch duplicate',
            'warehouse_id' => (string) $warehouse->id,
            'recipient_name' => 'Đại lý Mini App',
            'expected_total_qty' => 2,
            'items' => [
                ['raw_code' => $raw],
                ['raw_code' => $raw],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-outbound-stage-duplicate'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DUPLICATE_ITEM_IN_BATCH');

        $this->assertSame($documentsBefore, DB::table('stock_documents')->where('doc_type', 'issue')->count());
    }

    public function test_record_rejects_unexpected_payload_fields_before_creating_document(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $documentsBefore = DB::table('stock_documents')->where('doc_type', 'issue')->count();

        $this->postJson('/api/v1/mini-app/outbound/record', [
            'name' => 'Batch has extra FE state',
            'warehouse_id' => (string) $warehouse->id,
            'recipient_name' => 'Mini App',
            'expected_total_qty' => 1,
            'client_state' => ['screen' => 'review'],
            'items' => [
                [
                    'raw_code' => 'HN1|SKU=PROD-MINI-STAGE-EXTRA|ITEM=ITEM-EXTRA',
                    'scan_source' => 'CAMERA',
                    'resolved_item_id' => 'client-only',
                ],
            ],
        ], $headers + ['Idempotency-Key' => 'mini-outbound-stage-extra-fields'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.unexpected_fields.0', 'client_state')
            ->assertJsonPath('errors.unexpected_fields.1', 'items.1.resolved_item_id');

        $this->assertSame($documentsBefore, DB::table('stock_documents')->where('doc_type', 'issue')->count());
    }
}
