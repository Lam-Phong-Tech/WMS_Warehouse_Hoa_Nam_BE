<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class OutboundNfcReadTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_product_list_is_scoped_to_document_and_active_nfc_with_full_counts(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('NFC-OUT-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'TOA-NFC-001');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];

        $firstItem = $this->seedItemInStock($sku->sku_id);
        $secondItem = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($firstItem);
        $this->seedRfidCode($secondItem);
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId, 'quantity' => 1, 'item_id' => $firstItem,
        ], $headers + ['If-Match' => '1'])->assertOk();
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId, 'quantity' => 1, 'item_id' => $secondItem,
        ], $headers + ['If-Match' => '2'])->assertOk();
        $this->seedNfcTag($firstItem);
        $uid = (string) DB::table('physical_codes')->where('assigned_object_id', $firstItem)->where('carrier_type', 'NFC')->value('hardware_uid');

        // A scanning document is visible only when explicitly requested.
        $this->getJson('/api/v1/outbound-documents/nfc-items', $headers)
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/outbound-documents/nfc-items?document_id={$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('meta.tab_totals.ALL', 2)
            ->assertJsonPath('meta.tab_totals.ASSIGNED', 1)
            ->assertJsonPath('meta.tab_totals.UNASSIGNED', 1);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3', 'Idempotency-Key' => 'post-issue-nfc-read-001',
        ])->assertOk();

        $this->getJson('/api/v1/outbound-documents/nfc-items?nfc_status=UNASSIGNED', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.tab_totals.ALL', 2)
            ->assertJsonPath('data.0.item_id', $secondItem)
            ->assertJsonPath('data.0.nfc_status', 'UNASSIGNED');
        $this->getJson('/api/v1/outbound-documents/nfc-items?keyword='.$uid, $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.item_id', $firstItem)
            ->assertJsonPath('data.0.nfc.hardware_uid', $uid);
    }

    public function test_item_history_includes_revoked_and_replacement_chips_without_stock_effect(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('NFC-OUT-HISTORY');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-NFC-HISTORY');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($itemId);
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $created['line_ids'][0], 'quantity' => 1, 'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        // Looking up an untagged product is valid and must not fail while
        // joining the PostgreSQL audit compatibility view.
        $this->getJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/nfc-history?page=1&per_page=10", $headers)
            ->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $this->seedNfcTag($itemId, 'INACTIVE');
        $this->seedNfcTag($itemId);
        $tags = DB::table('physical_codes')->where('carrier_type', 'NFC')
            ->where('assigned_object_id', $itemId)->orderBy('created_at')->get();
        foreach ($tags as $tag) {
            $revoked = $tag->status === 'INACTIVE';
            DB::table('audit_log')->insert([
                'id' => (string) Str::uuid(), 'object_type' => 'physical_code',
                'object_id' => $tag->physical_code_id,
                'action' => $revoked ? 'REVOKE_ITEM_NFC' : 'ASSIGN_ITEM_NFC',
                'result' => 'SUCCESS', 'reason' => $revoked ? 'Thay chip' : null,
                'created_at' => now(),
            ]);
        }
        $movementCount = DB::table('stock_movements')->count();

        $this->getJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/nfc-history", $headers)
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');
        $viewer = $this->authHeadersWithRole('VIEWER');
        $this->getJson("/api/v1/outbound-documents/nfc-items?document_id={$documentId}", $viewer)
            ->assertOk()->assertJsonPath('meta.tab_totals.ASSIGNED', 1);
        $this->getJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/nfc-history", $viewer)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/outbound-documents/{$documentId}/nfc-history", $headers)
            ->assertNotFound();
        $this->getJson('/api/v1/outbound-documents/'.Str::uuid()."/items/{$itemId}/nfc-history", $headers)
            ->assertNotFound();
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }
}
