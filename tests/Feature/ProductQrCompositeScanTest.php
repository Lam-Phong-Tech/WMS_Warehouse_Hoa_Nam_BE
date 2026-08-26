<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Change Pack QR/SKU 20/08/2026 (DEC-QR-001) — test QR-01..13: composite PRODUCT
 * QR trên inbound scan (auto-match SKU, new item candidate, register-at-Post) và
 * outbound scan (resolve existing item, cross-check SKU embedded).
 */
class ProductQrCompositeScanTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function composite(string $skuCode, string $itemUnique, ?string $serial = null): string
    {
        $raw = "HN1|SKU={$skuCode}|ITEM={$itemUnique}";

        return $serial !== null ? $raw."|SER={$serial}" : $raw;
    }

    /**
     * Phiếu nhập DRAFT header-only theo hợp đồng GĐ1 scan-driven — dòng SKU chỉ
     * materialize khi scan composite QR (không còn tạo phiếu kèm lines).
     */
    private function draftInbound(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC QR Change Pack',
        ], $headers)->assertCreated();

        return [
            'headers' => $headers,
            'document_id' => (string) $create->json('data.id'),
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
        ];
    }

    private function inboundScan(array $fixture, string $rawPayload, int $version = 1)
    {
        return $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/scans", [
            'quantity' => 1,
            'code_value' => $rawPayload,
        ], $fixture['headers'] + ['If-Match' => (string) $version]);
    }

    // ------------------------------------------------------------------ inbound

    public function test_qr01_inbound_composite_moi_tu_match_line_va_nhan_candidate(): void
    {
        $fixture = $this->draftInbound('QR-SKU-01');

        $response = $this->inboundScan($fixture, $this->composite('QR-SKU-01', 'SN-QR01-0001'));

        $response->assertOk()
            ->assertJsonPath('data.item_id', null)
            ->assertJsonPath('data.scanned_quantity', 1)
            ->assertJsonPath('data.payload_format', 'COMPOSITE_V1')
            ->assertJsonPath('data.payload_version', 1)
            ->assertJsonPath('data.sku_code', 'QR-SKU-01')
            ->assertJsonPath('data.item_unique', 'SN-QR01-0001')
            ->assertJsonPath('data.normalized_code_value', 'SN-QR01-0001')
            ->assertJsonPath('data.resolution_status', 'NEW_ITEM_CANDIDATE')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        // Scan không tạo item/physical code/tồn (AC-QR-03/08).
        $this->assertDatabaseMissing('item_instances', ['serial_number' => 'SN-QR01-0001']);
        $this->assertDatabaseMissing('physical_codes', ['code_value' => 'SN-QR01-0001']);
        $this->assertSame(0, DB::table('stock_movements')->count());

        // GĐ1 scan-driven: dòng SKU do chính lần scan materialize.
        $lineId = (string) $response->json('data.line_id');
        $this->assertNotSame('', $lineId);
        $entry = DB::table('inbound_scan_entries')->where('inbound_line_id', $lineId)->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->item_id);
        $this->assertSame('SN-QR01-0001', $entry->candidate_item_unique);
    }

    public function test_qr02_inbound_cung_item_unique_lan_hai_bi_duplicate(): void
    {
        $fixture = $this->draftInbound('QR-SKU-02');
        $first = $this->inboundScan($fixture, $this->composite('QR-SKU-02', 'SN-QR02-0001'));
        $first->assertOk();
        $lineId = (string) $first->json('data.line_id');

        $this->inboundScan($fixture, $this->composite('QR-SKU-02', 'SN-QR02-0001'), 2)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ITEM_IN_DOCUMENT');

        $this->assertSame(1, (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'));
    }

    /**
     * GĐ1 scan-driven: SKU embedded chưa có dòng KHÔNG còn là WRONG_SKU — hệ
     * thống materialize dòng mới cho đúng SKU đó (Change Pack §6.1 bước 4 +
     * DiagnosticCurrentBaselineStockService::prepareScanDrivenInboundLine).
     */
    public function test_qr03_embedded_sku_chua_co_line_tu_materialize_dong_moi(): void
    {
        $fixture = $this->draftInbound('QR-SKU-03');
        $skuB = $this->productSkuFixture('QR-SKU-03B');

        $first = $this->inboundScan($fixture, $this->composite('QR-SKU-03', 'SN-QR03-0001'));
        $first->assertOk();
        $second = $this->inboundScan($fixture, $this->composite('QR-SKU-03B', 'SN-QR03-0002'), 2);
        $second->assertOk()->assertJsonPath('data.sku_id', (string) $skuB->sku_id);

        $this->assertNotSame((string) $first->json('data.line_id'), (string) $second->json('data.line_id'));
        $this->assertSame(
            2,
            DB::table('stock_document_lines')->where('stock_document_id', $fixture['document_id'])->count()
        );
        $this->assertSame(
            1,
            (int) DB::table('stock_document_lines')->where('id', (string) $second->json('data.line_id'))->value('scanned_qty')
        );
    }

    /**
     * GĐ1 scan-driven: không còn gate "quét vượt expected" (SCAN_EXCEEDS_REQUIRED
     * unreachable ở inbound) — qty_planned tự nâng theo scan, item thứ hai vẫn
     * được nhận và scanned_qty tăng.
     */
    public function test_qr04_scan_vuot_tham_chieu_van_duoc_nhan_scan_driven(): void
    {
        $fixture = $this->draftInbound('QR-SKU-04');
        $first = $this->inboundScan($fixture, $this->composite('QR-SKU-04', 'SN-QR04-0001'));
        $first->assertOk()->assertJsonPath('data.scanned_quantity', 1);

        $this->inboundScan($fixture, $this->composite('QR-SKU-04', 'SN-QR04-0002'), 2)
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2);

        $this->assertSame(
            2,
            (int) DB::table('stock_document_lines')->where('id', (string) $first->json('data.line_id'))->value('scanned_qty')
        );
    }

    public function test_qr05_payload_hong_va_version_la_khong_tang_scanned_qty(): void
    {
        $fixture = $this->draftInbound('QR-SKU-05');

        $this->inboundScan($fixture, 'HN1|SKU=QR-SKU-05')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PAYLOAD');

        $this->inboundScan($fixture, 'HN9|SKU=QR-SKU-05|ITEM=SN-QR05-0001')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNSUPPORTED_PAYLOAD_VERSION');

        // GĐ1-REQ-007: SKU chưa tồn tại trong master KHÔNG còn bị SKU_NOT_FOUND —
        // BaM1StockService auto-create SKU PRODUCT ACTIVE tối thiểu rồi nhận scan.
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'QR-SKU-05-GHOST')->count());
        $this->inboundScan($fixture, $this->composite('QR-SKU-05-GHOST', 'SN-QR05-0001'))
            ->assertOk()
            ->assertJsonPath('data.resolution_status', 'NEW_ITEM_CANDIDATE');
        $ghost = DB::table('sku')->where('sku_code', 'QR-SKU-05-GHOST')->first();
        $this->assertNotNull($ghost);
        $this->assertSame('ACTIVE', strtoupper((string) $ghost->status));

        // SKU đã tồn tại nhưng INACTIVE thì vẫn bị chặn (không auto-create đè).
        Sku::create([
            'sku_code' => 'QR-SKU-05-OFF',
            'sku_name' => 'Inactive QR sku',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'INACTIVE',
        ]);
        $this->inboundScan($fixture, $this->composite('QR-SKU-05-OFF', 'SN-QR05-0002'), 2)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_INACTIVE');

        // Không lần scan lỗi nào để lại scanned_qty hay movement.
        $this->assertSame(
            0,
            (int) DB::table('stock_document_lines')
                ->where('stock_document_id', $fixture['document_id'])
                ->where('product_id', '!=', (string) $ghost->sku_id)
                ->sum('scanned_qty')
        );
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_qr06_post_receipt_dang_ky_item_physical_code_va_tang_ton_dung_mot_lan(): void
    {
        $fixture = $this->draftInbound('QR-SKU-06');
        $firstScan = $this->inboundScan($fixture, $this->composite('QR-SKU-06', 'SN-QR06-0001'));
        $firstScan->assertOk();
        $lineId = (string) $firstScan->json('data.line_id');
        $this->inboundScan($fixture, $this->composite('QR-SKU-06', 'SN-QR06-0002', 'NSX-06-0002'), 2)->assertOk();

        $post = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $fixture['headers'] + [
            'If-Match' => '3',
            'Idempotency-Key' => 'qr06-post-'.Str::random(8),
        ]);
        $post->assertOk()->assertJsonPath('data.status', 'POSTED');

        // Item + physical code được đăng ký atomically (AC-QR-04).
        $item1 = DB::table('item_instances')->where('serial_number', 'SN-QR06-0001')->first();
        $this->assertNotNull($item1);
        $this->assertSame('IN_STOCK', (string) $item1->item_status);
        // SER= khác item_unique -> serial giữ theo NSX, physical code theo item_unique.
        $item2 = DB::table('item_instances')->where('serial_number', 'NSX-06-0002')->first();
        $this->assertNotNull($item2);

        $code1 = DB::table('physical_codes')->where('code_value', 'SN-QR06-0001')->first();
        $this->assertNotNull($code1);
        $this->assertSame('ITEM', (string) $code1->assigned_object_type);
        $this->assertSame((string) $item1->item_id, (string) $code1->assigned_object_id);
        $this->assertSame('1', (string) $code1->encoded_payload_version);
        $this->assertSame('QR', (string) $code1->carrier_type);

        // Entry candidate đã được gán item_id.
        $entries = DB::table('inbound_scan_entries')->where('inbound_line_id', $lineId)->get();
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertNotNull($entry->item_id);
        }

        // Balance +2 đúng một lần.
        $this->assertSame(2, (int) DB::table('inventory_balances')
            ->where('sku_id', $fixture['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->value('quantity'));
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'RECEIPT')->count());
    }

    public function test_qr07_post_receipt_replay_cung_idempotency_key_khong_tao_item_lan_hai(): void
    {
        $fixture = $this->draftInbound('QR-SKU-07');
        $this->inboundScan($fixture, $this->composite('QR-SKU-07', 'SN-QR07-0001'))->assertOk();

        $key = 'qr07-post-'.Str::random(8);
        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ])->assertOk();

        $replay = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $replay->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame(1, DB::table('item_instances')->where('serial_number', 'SN-QR07-0001')->count());
        $this->assertSame(1, DB::table('physical_codes')->where('code_value', 'SN-QR07-0001')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'RECEIPT')->count());
    }

    public function test_qr08_cancel_truoc_post_khong_de_lai_item_hay_ton(): void
    {
        $fixture = $this->draftInbound('QR-SKU-08');
        $this->inboundScan($fixture, $this->composite('QR-SKU-08', 'SN-QR08-0001'))->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/cancel", [
            'reason' => 'QR-08 cancel truoc Post',
        ], $fixture['headers'] + ['If-Match' => '2'])->assertOk();

        $this->assertDatabaseMissing('item_instances', ['serial_number' => 'SN-QR08-0001']);
        $this->assertDatabaseMissing('physical_codes', ['code_value' => 'SN-QR08-0001']);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * GĐ1-REQ-008: identity đã tồn tại trong DB (kể cả item_status=NEW) không
     * được nhận lại qua inbound thường — 409 ITEM_ALREADY_REGISTERED; cross-check
     * SKU embedded vẫn chạy TRƯỚC guard đó (WRONG_SKU khi QR dán nhầm SKU khác).
     * (Thay cho test cũ resolve EXISTING_ITEM OK — hành vi đó đã bị bỏ.)
     */
    public function test_inbound_item_da_ton_tai_bi_chan_va_van_cross_check_sku(): void
    {
        $fixture = $this->draftInbound('QR-SKU-EX');
        // Item đã đăng ký sẵn (NEW) với physical code trùng item_unique.
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $fixture['sku_id'],
            'serial_number' => 'SN-QREX-0001',
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedScanCode($itemId, 'SN-QREX-0001');

        $this->inboundScan($fixture, $this->composite('QR-SKU-EX', 'SN-QREX-0001'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_REGISTERED');
        $this->assertSame(
            0,
            (int) DB::table('stock_document_lines')->where('stock_document_id', $fixture['document_id'])->sum('scanned_qty')
        );

        // Cùng item nhưng QR dán nhầm SKU khác -> WRONG_SKU (AC-QR-05 tinh thần chung).
        $other = $this->draftInbound('QR-SKU-EX2');
        $this->inboundScan($other, $this->composite('QR-SKU-EX2', 'SN-QREX-0001'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_SKU');
    }

    public function test_candidate_dang_scan_o_phieu_khac_bi_item_already_receiving(): void
    {
        $first = $this->draftInbound('QR-SKU-XDOC');
        $this->inboundScan($first, $this->composite('QR-SKU-XDOC', 'SN-XDOC-0001'))->assertOk();

        $headers = $first['headers'];
        $second = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $first['warehouse_id'],
            'source_name' => 'NCC QR XDOC 2',
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/inbound-documents/'.$second->json('data.id').'/scans', [
            'quantity' => 1,
            'code_value' => $this->composite('QR-SKU-XDOC', 'SN-XDOC-0001'),
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_RECEIVING');
    }

    public function test_legacy_flag_off_chan_plain_code_nhung_composite_van_chay(): void
    {
        config(['warehouse.product_qr.legacy_enabled' => false]);

        $fixture = $this->draftInbound('QR-SKU-LGC');
        // Plain legacy code của item đã đăng ký -> bị chặn khi flag OFF (QR-18).
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $fixture['sku_id'],
            'serial_number' => 'SN-LGC-0001',
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedScanCode($itemId, 'SN-LGC-0001');

        $this->inboundScan($fixture, 'SN-LGC-0001')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LEGACY_CODE_DISABLED');

        $this->inboundScan($fixture, $this->composite('QR-SKU-LGC', 'SN-LGC-0002'))
            ->assertOk()
            ->assertJsonPath('data.resolution_status', 'NEW_ITEM_CANDIDATE');
    }

    /**
     * GĐ1-REQ-008: legacy code + flag ON vẫn resolve được item hiện hữu, nhưng
     * identity đã tồn tại trong DB thì inbound thường phải chặn bằng 409
     * ITEM_ALREADY_REGISTERED (sửa sai đi qua luồng reversal/return).
     * (Thay cho hành vi cũ nhận LEGACY_ITEM OK.)
     */
    public function test_legacy_flag_on_plain_code_cua_item_da_ton_tai_bi_chan(): void
    {
        $fixture = $this->draftInbound('QR-SKU-LGO');
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $fixture['sku_id'],
            'serial_number' => 'SN-LGO-0001',
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedScanCode($itemId, 'SN-LGO-0001');

        $this->inboundScan($fixture, 'SN-LGO-0001')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_REGISTERED');
        $this->assertSame(0, DB::table('inbound_scan_entries')->where('item_id', $itemId)->count());
    }

    // ----------------------------------------------------------------- outbound

    private function outboundScanReady(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 5);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'REQ-'.Str::upper(Str::random(8)));
        $document = $this->outboundDocumentFixture($warehouse->id, $requestId);

        return [
            'headers' => $headers,
            'document_id' => $document['document_id'],
            'line_id' => (string) $document['line_ids'][0],
            'sku_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
            'sku_code' => $skuCode,
        ];
    }

    private function outboundScan(array $fixture, string $rawPayload, int $version = 1)
    {
        return $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scans", [
            'quantity' => 1,
            'code_value' => $rawPayload,
            'scan_source' => 'CAMERA',
        ], $fixture['headers'] + ['If-Match' => (string) $version]);
    }

    public function test_qr09_qr13_outbound_composite_resolve_item_va_giu_packing_label_flow(): void
    {
        $fixture = $this->outboundScanReady('QR-OUT-01');
        $itemId = $this->seedItemInStock($fixture['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['serial_number' => 'SN-OUT-0001']);
        $this->seedScanCode($itemId, 'SN-OUT-0001');

        $response = $this->outboundScan($fixture, $this->composite('QR-OUT-01', 'SN-OUT-0001'));
        $response->assertOk()
            ->assertJsonPath('data.item_id', $itemId)
            ->assertJsonPath('data.line_id', $fixture['line_id'])
            ->assertJsonPath('data.resolution_status', 'EXISTING_ITEM')
            ->assertJsonPath('data.payload_format', 'COMPOSITE_V1')
            ->assertJsonPath('data.stock_effect', 'NONE')
            // QR-13: full match -> Packing Label DRAFT tự tạo, không trừ tồn.
            ->assertJsonPath('data.packing_label_ready', true)
            ->assertJsonPath('data.label_status', 'DRAFT');

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_qr10_outbound_embedded_sku_lech_item_bi_wrong_sku(): void
    {
        $fixture = $this->outboundScanReady('QR-OUT-02');
        $this->productSkuFixture('QR-OUT-02B');
        $itemId = $this->seedItemInStock($fixture['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update(['serial_number' => 'SN-OUT-0002']);
        $this->seedScanCode($itemId, 'SN-OUT-0002');

        $this->outboundScan($fixture, $this->composite('QR-OUT-02B', 'SN-OUT-0002'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_SKU');
    }

    public function test_qr11_outbound_item_chua_dang_ky_bi_unknown_code(): void
    {
        $fixture = $this->outboundScanReady('QR-OUT-03');

        $this->outboundScan($fixture, $this->composite('QR-OUT-03', 'SN-OUT-GHOST'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_CODE');
    }

    public function test_qr12_outbound_item_da_issued_bi_invalid_state(): void
    {
        $fixture = $this->outboundScanReady('QR-OUT-04');
        $itemId = $this->seedItemInStock($fixture['sku_id']);
        DB::table('item_instances')->where('item_id', $itemId)->update([
            'serial_number' => 'SN-OUT-0004',
            'item_status' => 'ISSUED',
        ]);
        $this->seedScanCode($itemId, 'SN-OUT-0004');

        $this->outboundScan($fixture, $this->composite('QR-OUT-04', 'SN-OUT-0004'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_NOT_AVAILABLE');
    }
}
