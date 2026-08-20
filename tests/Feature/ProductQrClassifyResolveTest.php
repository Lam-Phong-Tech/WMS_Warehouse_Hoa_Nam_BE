<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Change Pack QR/SKU 20/08/2026 (DEC-QR-001) — test QR-14..16: classify là
 * resolver dùng chung cho WARRANTY/TRACE với composite PRODUCT QR; trace và
 * warranty create nhận RAW composite payload và resolve theo normalized identity.
 */
class ProductQrClassifyResolveTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function composite(string $skuCode, string $itemUnique): string
    {
        return "HN1|SKU={$skuCode}|ITEM={$itemUnique}";
    }

    /** Item ISSUED (đủ điều kiện warranty) kèm physical code theo item_unique. */
    private function issuedItemFixture(string $skuCode, string $itemUnique): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
            'serial_number' => $itemUnique,
            'item_status' => 'ISSUED',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedScanCode($itemId, $itemUnique);

        return [
            'headers' => $headers,
            'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id,
            'item_id' => $itemId,
        ];
    }

    private function classify(array $fixture, string $rawCode, string $context = 'WARRANTY_RECEIVE')
    {
        return $this->postJson('/api/v1/scan/classify', [
            'raw_code' => $rawCode,
            'context' => $context,
            'warehouse_id' => $fixture['warehouse_id'],
        ], $fixture['headers']);
    }

    public function test_qr14_classify_composite_resolve_item_va_tra_parsed_identity(): void
    {
        $fixture = $this->issuedItemFixture('QR-CLS-01', 'SN-CLS-0001');
        $raw = $this->composite('QR-CLS-01', 'SN-CLS-0001');

        $this->classify($fixture, $raw)
            ->assertOk()
            ->assertJsonPath('data.raw_code', $raw)
            ->assertJsonPath('data.classification', 'ITEM')
            ->assertJsonPath('data.item_id', $fixture['item_id'])
            ->assertJsonPath('data.sku_id', $fixture['sku_id'])
            ->assertJsonPath('data.payload_format', 'COMPOSITE_V1')
            ->assertJsonPath('data.payload_version', 1)
            ->assertJsonPath('data.sku_code', 'QR-CLS-01')
            ->assertJsonPath('data.item_unique', 'SN-CLS-0001')
            ->assertJsonPath('data.normalized_code_value', 'SN-CLS-0001')
            ->assertJsonPath('data.resolution_status', 'EXISTING_ITEM')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
    }

    public function test_qr15_classify_composite_item_chua_dang_ky_tra_unknown_code(): void
    {
        $fixture = $this->issuedItemFixture('QR-CLS-02', 'SN-CLS-0002');

        $this->classify($fixture, $this->composite('QR-CLS-02', 'SN-CLS-GHOST'))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'UNKNOWN_CODE');
    }

    public function test_classify_composite_sku_lech_item_tra_wrong_sku(): void
    {
        $fixture = $this->issuedItemFixture('QR-CLS-03', 'SN-CLS-0003');
        $this->productSkuFixture('QR-CLS-03B');

        $this->classify($fixture, $this->composite('QR-CLS-03B', 'SN-CLS-0003'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_SKU');
    }

    public function test_classify_payload_hong_va_version_la_bi_reject(): void
    {
        $fixture = $this->issuedItemFixture('QR-CLS-04', 'SN-CLS-0004');

        $this->classify($fixture, 'HN1|SKU=QR-CLS-04')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PAYLOAD');

        $this->classify($fixture, 'HN7|SKU=QR-CLS-04|ITEM=SN-CLS-0004')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNSUPPORTED_PAYLOAD_VERSION');

        $this->classify($fixture, $this->composite('QR-CLS-GHOST', 'SN-CLS-0004'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_NOT_FOUND');
    }

    public function test_classify_plain_code_giu_contract_cu_khong_co_payload_fields(): void
    {
        $fixture = $this->issuedItemFixture('QR-CLS-05', 'SN-CLS-0005');

        $response = $this->classify($fixture, 'SN-CLS-0005', 'TRACE');
        $response->assertOk()
            ->assertJsonPath('data.classification', 'ITEM')
            ->assertJsonPath('data.contract_version', 'WWHN-150.1.1.0');
        $this->assertArrayNotHasKey('payload_format', $response->json('data'));
    }

    public function test_qr16_trace_nhan_raw_composite_va_tra_movement_chain_theo_normalized_code(): void
    {
        $fixture = $this->issuedItemFixture('QR-TRC-01', 'SN-TRC-0001');

        $response = $this->getJson(
            '/api/v1/stock-movements/trace-by-code?code='.urlencode($this->composite('QR-TRC-01', 'SN-TRC-0001')),
            $fixture['headers']
        );
        $response->assertOk()
            ->assertJsonPath('data.resolved_type', 'ITEM')
            ->assertJsonPath('data.resolved_id', $fixture['item_id']);
        $this->assertIsArray($response->json('data.movements'));
    }

    public function test_trace_composite_hong_bi_invalid_payload(): void
    {
        $fixture = $this->issuedItemFixture('QR-TRC-02', 'SN-TRC-0002');

        $this->getJson(
            '/api/v1/stock-movements/trace-by-code?code='.urlencode('HN1|SKU=QR-TRC-02'),
            $fixture['headers']
        )->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');
    }

    public function test_warranty_create_voi_raw_composite_tu_resolve_item_identified(): void
    {
        $fixture = $this->issuedItemFixture('QR-WAR-01', 'SN-WAR-0001');

        $response = $this->postJson('/api/v1/warranty-cases', [
            'item_code' => $this->composite('QR-WAR-01', 'SN-WAR-0001'),
            'customer_name' => 'Khach QR',
            'customer_phone' => '0900000001',
            'description' => 'May khong len nguon',
        ], $fixture['headers']);

        $response->assertCreated();
        $caseId = (string) $response->json('data.id');
        $this->assertNotSame('', $caseId);
        $this->assertSame(
            $fixture['item_id'],
            (string) DB::table('warranty_case')->where('warranty_case_id', $caseId)->value('item_id')
        );
        // Item chuyển IN_WARRANTY như baseline; scan/classify không đổi tồn.
        $this->assertSame('IN_WARRANTY', (string) DB::table('item_instances')->where('item_id', $fixture['item_id'])->value('item_status'));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_warranty_composite_item_chua_dang_ky_khong_auto_link(): void
    {
        $fixture = $this->issuedItemFixture('QR-WAR-02', 'SN-WAR-0002');

        // QR-15 warranty: parse OK nhưng item unknown -> khong auto-link, bao
        // WARRANTY_ITEM_NOT_FOUND de UI mo nhanh TEMP_ONLY.
        $this->postJson('/api/v1/warranty-cases', [
            'item_code' => $this->composite('QR-WAR-02', 'SN-WAR-GHOST'),
            'customer_name' => 'Khach QR 2',
            'customer_phone' => '0900000002',
            'description' => 'Mat QR goc',
        ], $fixture['headers'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_ITEM_NOT_FOUND');
    }

    public function test_warranty_composite_sku_lech_bi_wrong_sku(): void
    {
        $fixture = $this->issuedItemFixture('QR-WAR-03', 'SN-WAR-0003');
        $this->productSkuFixture('QR-WAR-03B');

        $this->postJson('/api/v1/warranty-cases', [
            'item_code' => $this->composite('QR-WAR-03B', 'SN-WAR-0003'),
            'customer_name' => 'Khach QR 3',
            'customer_phone' => '0900000003',
            'description' => 'QR dan nham SKU',
        ], $fixture['headers'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_SKU');
    }
}
