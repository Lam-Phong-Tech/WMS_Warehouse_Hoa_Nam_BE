<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class OutboundReservationDiagnosticsTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_scan_conflict_identifies_the_active_outbound_holding_the_item(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-RESERVATION-DIAG');
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 2);
        $itemId = $this->seedItemInStock((string) $sku->sku_id);

        $firstRequestId = $this->outboundRequestFixture(
            (string) $sku->sku_id,
            1,
            'TOA-RESERVATION-A'
        );
        $first = $this->outboundDocumentFixture((string) $warehouse->id, $firstRequestId);

        $secondRequestId = $this->outboundRequestFixture(
            (string) $sku->sku_id,
            1,
            'TOA-RESERVATION-B'
        );
        $second = $this->outboundDocumentFixture((string) $warehouse->id, $secondRequestId);

        $this->postJson(
            "/api/v1/outbound-documents/{$first['document_id']}/scan",
            [
                'line_id' => $first['line_ids'][0],
                'quantity' => 1,
                'item_id' => $itemId,
                'scan_source' => 'MANUAL',
            ],
            $headers + ['If-Match' => '1']
        )->assertOk();

        $firstDocumentNo = (string) \Illuminate\Support\Facades\DB::table('stock_documents')
            ->where('id', $first['document_id'])
            ->value('doc_no');

        $response = $this->postJson(
            "/api/v1/outbound-documents/{$second['document_id']}/scan",
            [
                'line_id' => $second['line_ids'][0],
                'quantity' => 1,
                'item_id' => $itemId,
                'scan_source' => 'MANUAL',
            ],
            $headers + ['If-Match' => '1']
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_MATCHED')
            ->assertJsonPath('errors.item_id', $itemId)
            ->assertJsonPath('errors.conflicting_document_id', $first['document_id'])
            ->assertJsonPath('errors.conflicting_document_no', $firstDocumentNo)
            ->assertJsonPath('errors.conflicting_document_status', 'SCANNING')
            ->assertJsonPath('errors.conflicting_outbound_request_id', $firstRequestId);

        $this->assertStringContainsString(
            $firstDocumentNo,
            (string) $response->json('message')
        );
    }
}
