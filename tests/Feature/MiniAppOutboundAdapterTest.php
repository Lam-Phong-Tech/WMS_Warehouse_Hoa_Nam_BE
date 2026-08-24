<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\AlignedOutboundStockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\DiagnosticCurrentBaselineStockService;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class MiniAppOutboundAdapterTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_final_stock_service_keeps_aligned_outbound_semantics_and_diagnostics(): void
    {
        $service = app(CurrentBaselineStockService::class);

        $this->assertInstanceOf(DiagnosticCurrentBaselineStockService::class, $service);
        $this->assertInstanceOf(AlignedOutboundStockService::class, $service);
    }

    public function test_mini_app_outbound_scan_is_stock_neutral_then_post_issue_decreases_stock_once(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-MINI-OUT-001');
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 1);

        $requestId = $this->outboundRequestFixture((string) $sku->sku_id, 1, 'TOA-MINI-OUT-001');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $itemId = $this->seedItemInStock((string) $sku->sku_id);
        $rawCode = 'MINI-OUT-QR-001';
        $this->seedScanCode($itemId, $rawCode);

        $list = $this->getJson('/api/v1/mini-app/outbound-documents?status=DRAFT', $headers)
            ->assertOk();
        $this->assertSame('PICKING', $list->json('data.0.mini_app_status'));
        $this->assertFalse((bool) $list->json('data.0.ready_for_issue'));

        $movementsBeforeScan = DB::table('stock_movements')->count();
        $balanceBeforeScan = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('sku_id', $sku->sku_id)
            ->value('available_qty');

        $scan = $this->postJson("/api/v1/mini-app/outbound-documents/{$documentId}/scan", [
            'code_value' => $rawCode,
            'quantity' => 1,
            'scan_source' => 'SCANNER',
        ], $headers + ['If-Match' => '1']);

        $scan->assertOk()
            ->assertJsonPath('data.document_id', $documentId)
            ->assertJsonPath('data.scan_source', 'HARDWARE')
            ->assertJsonPath('data.packing_label_ready', true)
            ->assertJsonPath('data.label_status', 'DRAFT');

        $this->assertSame($movementsBeforeScan, DB::table('stock_movements')->count());
        $this->assertSame($balanceBeforeScan, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('sku_id', $sku->sku_id)
            ->value('available_qty'));

        $label = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->first();
        $this->assertNotNull($label);
        $this->assertSame('DRAFT', (string) $label->status);

        $entries = $this->getJson("/api/v1/mini-app/outbound-documents/{$documentId}/scan-entries", $headers)
            ->assertOk();
        $this->assertSame($itemId, $entries->json('data.0.item_id'));
        $this->assertSame($rawCode, $entries->json('data.0.raw_code'));

        // Simulate successful canonical label print + physical apply. These are
        // stock-neutral gates; their own lifecycle is covered by AnnexC tests.
        $this->markLabelsPrinted($documentId);

        $this->getJson("/api/v1/mini-app/outbound-documents/{$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.full_scan', true)
            ->assertJsonPath('data.ready_for_issue', true)
            ->assertJsonPath('data.mini_app_status', 'READY_TO_ISSUE')
            ->assertJsonPath('data.packing_label.status', 'PRINTED')
            ->assertJsonPath('data.packing_label.applied', true);

        $postHeaders = $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'mini-app-outbound-post-001',
        ];
        $post = $this->postJson("/api/v1/mini-app/outbound-documents/{$documentId}/post-issue", [], $postHeaders);
        $post->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.stock_effect', 'DECREASE');

        $this->assertSame(0, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('sku_id', $sku->sku_id)
            ->value('available_qty'));
        $this->assertSame('ISSUED', (string) DB::table('item_instances')->where('item_id', $itemId)->value('item_status'));

        // Same business action replay must not create a second stock effect.
        $movementCountAfterFirstPost = DB::table('stock_movements')->count();
        $this->postJson("/api/v1/mini-app/outbound-documents/{$documentId}/post-issue", [], $postHeaders)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($movementCountAfterFirstPost, DB::table('stock_movements')->count());
    }
}
