<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class MiniAppOutboundDraftCreateTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_mini_app_can_create_canonical_outbound_draft_without_stock_effect_and_replay_safely(): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-DRAFT-001');
        $headers = $this->superAdminHeaders() + [
            'Idempotency-Key' => 'mini-outbound-draft-001',
        ];

        $movementCountBefore = DB::table('stock_movements')->count();
        $payload = [
            'warehouse_id' => (string) $warehouse->id,
            'name' => 'Xuất hàng Mini App',
            'recipient_name' => 'Đại lý Mini App',
            'recipient_address' => 'Đà Nẵng',
            'recipient_contact_phone' => '0912345678',
            'expected_total_qty' => 3,
            // Duplicate SKU rows intentionally prove that the adapter aggregates
            // one planned document line per SKU before scan auto-match begins.
            'lines' => [
                ['sku_id' => (string) $sku->sku_id, 'required_qty' => 1],
                ['sku_code' => (string) $sku->sku_code, 'required_qty' => 2],
            ],
        ];

        $response = $this->postJson('/api/v1/mini-app/outbound-documents', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.mini_app_status', 'PICKING')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.expected_total_qty', 3)
            ->assertJsonPath('data.scanned_total_qty', 0)
            ->assertJsonPath('data.remaining_qty', 3)
            ->assertJsonPath('data.ready_for_issue', false)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.required_qty', 3);

        $documentId = (string) $response->json('data.document_id');
        $requestId = (string) $response->json('data.outbound_request_id');
        $importId = (string) $response->json('data.source_import_id');

        $this->assertNotSame('', $documentId);
        $this->assertSame('CONSUMED', (string) DB::table('outbound_requests')->where('id', $requestId)->value('status'));
        $this->assertSame('CONSUMED', (string) DB::table('outbound_request_imports')->where('id', $importId)->value('status'));
        $this->assertSame($requestId, (string) DB::table('stock_documents')->where('id', $documentId)->value('outbound_request_id'));
        $this->assertSame('DRAFT', (string) DB::table('stock_documents')->where('id', $documentId)->value('status'));
        $this->assertSame(3, (int) DB::table('stock_document_lines')->where('stock_document_id', $documentId)->value('qty_planned'));
        $this->assertSame(0, (int) DB::table('stock_document_lines')->where('stock_document_id', $documentId)->value('scanned_qty'));
        $this->assertSame($movementCountBefore, DB::table('stock_movements')->count());

        $requestCount = DB::table('outbound_requests')->count();
        $documentCount = DB::table('stock_documents')->where('doc_type', 'issue')->count();
        $importCount = DB::table('outbound_request_imports')->count();

        $replay = $this->postJson('/api/v1/mini-app/outbound-documents', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.document_id', $documentId);

        $this->assertSame('true', $replay->headers->get('Idempotent-Replay'));
        $this->assertSame($requestCount, DB::table('outbound_requests')->count());
        $this->assertSame($documentCount, DB::table('stock_documents')->where('doc_type', 'issue')->count());
        $this->assertSame($importCount, DB::table('outbound_request_imports')->count());
        $this->assertSame($movementCountBefore, DB::table('stock_movements')->count());
    }

    public function test_mini_app_rejects_expected_quantity_mismatch_before_creating_draft(): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-DRAFT-002');
        $headers = $this->superAdminHeaders() + [
            'Idempotency-Key' => 'mini-outbound-draft-qty-mismatch',
        ];

        $this->postJson('/api/v1/mini-app/outbound-documents', [
            'warehouse_id' => (string) $warehouse->id,
            'recipient_name' => 'Đại lý Mini App',
            'expected_total_qty' => 2,
            'lines' => [
                ['sku_id' => (string) $sku->sku_id, 'required_qty' => 1],
            ],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OUTBOUND_EXPECTED_QTY_MISMATCH');

        $this->assertSame(0, DB::table('stock_documents')->where('doc_type', 'issue')->count());
    }
}
