<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Mọi luồng tạo phiếu xuất đều phải dùng cùng một dãy số PX-YYYYMMDD-NNNN.
 *
 * Trước đây app direct-create và web import confirm đều rơi về
 * PX-YYYYMMDDHHmmss-RANDOM, trong khi P05/StockDocumentService dùng dãy ngày.
 * Test này khóa contract thống nhất đó.
 */
class OutboundDocumentNumberUnificationTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_mini_app_direct_create_uses_daily_sequence(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('NUM-DIRECT-'.Str::upper(Str::random(6)));

        $response = $this->postJson('/api/v1/mini-app/outbound-documents', [
            'warehouse_id' => $warehouse->id,
            'recipient_name' => 'Khách test numbering',
            'expected_total_qty' => 1,
            'lines' => [[
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers + [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $docNo = (string) $response->json('data.doc_no');
        $this->assertMatchesRegularExpression('/^PX-[0-9]{8}-[0-9]{4,}$/D', $docNo);
        $this->assertDatabaseHas('stock_documents', [
            'doc_no' => $docNo,
            'doc_type' => 'issue',
        ]);
    }

    public function test_web_import_confirm_uses_daily_sequence(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('NUM-IMPORT-'.Str::upper(Str::random(6)));
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);

        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-NUM-'.Str::upper(Str::random(8)),
                'customer_name' => 'Khách test numbering',
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers);

        $import->assertCreated();
        $importId = (string) $import->json('data.import.id');

        $confirm = $this->postJson("/api/v1/outbound-request-imports/{$importId}/confirm", [], $headers + [
            'If-Match' => (string) $import->json('data.import.version'),
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $confirm->assertOk();
        $documentId = (string) $confirm->json('data.outbound_document_ids.0');
        $docNo = (string) DB::table('stock_documents')->where('id', $documentId)->value('doc_no');
        $this->assertMatchesRegularExpression('/^PX-[0-9]{8}-[0-9]{4,}$/D', $docNo);
    }

    public function test_direct_create_fails_closed_when_numbering_schema_is_missing(): void
    {
        Schema::drop('outbound_daily_numbers');

        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('NUM-MISSING-'.Str::upper(Str::random(6)));

        $response = $this->postJson('/api/v1/mini-app/outbound-documents', [
            'warehouse_id' => $warehouse->id,
            'recipient_name' => 'Khách test numbering',
            'expected_total_qty' => 1,
            'lines' => [[
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers + [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'P05_NUMBERING_SCHEMA_UNAVAILABLE');
        $this->assertDatabaseMissing('stock_documents', [
            'doc_type' => 'issue',
            'recipient_name' => 'Khách test numbering',
        ]);
    }
}
