<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Notification\Listeners\SendInboundSkuInfoPendingNotification;
use Modules\Warehouse\Events\InboundSkuInfoPending;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Bàn giao "phiếu Mini App có SKU mới" sang web.
 *
 * Luật nghiệp vụ: phiếu VẪN được gửi lên và nằm ở "Đang quét nhận hàng"
 * (SCANNING); chỉ Ghi nhận nhập kho (Post Receipt) mới bị chặn cho tới khi có
 * người bổ sung tên + ĐVT trên web. Bộ test này khoá cả hai nửa đó lại: gửi
 * được, và chặn đúng chỗ — kèm mọi tín hiệu để Mini App/web biết phải làm gì.
 */
class MiniAppInboundNewSkuHandoffTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_record_with_new_sku_lands_in_scanning_and_tells_client_post_is_blocked(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();
        $raw = 'HN1|SKU=SKU-MINI-NEW-001|ITEM=MINI-NEW-0001|SER=MINI-NEW-0001';

        $this->postJson('/api/v1/mini-app/inbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id,
            'raw_code' => $raw,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolution_status', 'NEW_SKU_CANDIDATE')
            // Mini App phải hỏi "Sản phẩm hay Linh kiện?" ngay khi quét.
            ->assertJsonPath('data.requires_new_sku_type', true)
            ->assertJsonPath('data.wms_document_created', false);

        $record = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap Mini App co SKU moi',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => $raw, 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-record-001'])
            // Gửi được — điểm mấu chốt: SKU mới KHÔNG chặn việc ghi nhận.
            ->assertCreated()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.mini_app_status', 'WAITING_APPROVAL')
            ->assertJsonPath('data.ready_for_post', true)
            // ...nhưng nói thẳng là chưa Post được và vì sao.
            ->assertJsonPath('data.product_info_complete', false)
            ->assertJsonPath('data.can_post', false)
            ->assertJsonPath('data.post_blocked_reason', 'INBOUND_SKU_INFO_INCOMPLETE')
            ->assertJsonPath('data.pending_product_info_count', 1)
            ->assertJsonPath('data.skus_pending_info.0.sku_code', 'SKU-MINI-NEW-001')
            ->assertJsonPath('data.skus_pending_info.0.missing_fields', ['sku_name', 'unit']);

        $documentId = (string) $record->json('data.document_id');
        $version = (int) $record->json('data.version');

        // Danh sách + chi tiết của Mini App mang đủ tín hiệu để ẩn nút duyệt.
        $this->getJson('/api/v1/mini-app/inbound-documents?status=SCANNING', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.product_info_complete', false)
            ->assertJsonPath('data.0.can_post', false)
            ->assertJsonPath('data.0.post_blocked_reason', 'INBOUND_SKU_INFO_INCOMPLETE');

        $this->getJson("/api/v1/mini-app/inbound-documents/{$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.can_post', false)
            // Dòng phiếu phải đọc được mã/tên/ĐVT, không chỉ product_id thô.
            ->assertJsonPath('data.lines.0.product_code', 'SKU-MINI-NEW-001')
            ->assertJsonPath('data.lines.0.product_info_complete', false)
            ->assertJsonPath('data.lines.0.product_missing_info', ['sku_name', 'unit']);

        // Bấm duyệt ở Mini App vẫn bị chặn — chốt chặn thật nằm ở BE.
        $this->postJson("/api/v1/mini-app/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => (string) $version,
            'Idempotency-Key' => 'mini-new-sku-approve-001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INBOUND_SKU_INFO_INCOMPLETE');

        $this->assertSame('SCANNING', (string) DB::table('stock_documents')->where('id', $documentId)->value('status'));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_web_supplements_info_then_posts_the_mini_app_document(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();
        $raw = 'HN1|SKU=SKU-MINI-NEW-002|ITEM=MINI-NEW-0002|SER=MINI-NEW-0002';

        $record = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap Mini App co SKU moi 2',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => $raw, 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-record-002'])->assertCreated();

        $documentId = (string) $record->json('data.document_id');
        $lineId = (string) $record->json('data.sku_lines.0.line_id');

        // Web: bổ sung tên + ĐVT ngay trên dòng phiếu (route hẹp cho NV kho).
        $this->patchJson("/api/v1/inbound-documents/{$documentId}/lines/{$lineId}/sku", [
            'sku_name' => 'May khoan bua DCPB16',
            'unit' => 'Cai',
        ], $headers)->assertOk();

        $detail = $this->getJson("/api/v1/inbound-documents/{$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.lines.0.product_info_complete', true);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => (string) $detail->json('data.version'),
            'Idempotency-Key' => 'mini-new-sku-post-002',
        ])->assertOk();

        $this->assertSame('POSTED', (string) DB::table('stock_documents')->where('id', $documentId)->value('status'));
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_new_sku_type_component_from_item_code_creates_serial_tracked_component(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();
        $raw = 'HN1|SKU=SKU-MINI-COMP-001|ITEM=MINI-COMP-0001|SER=MINI-COMP-0001';

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap linh kien dan ma tung cai',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [[
                'raw_code' => $raw,
                'scan_source' => 'CAMERA',
                // Trả lời của người quét ở Mini App.
                'new_sku_type' => 'COMPONENT',
            ]],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-component-001'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.sku_code', 'SKU-MINI-COMP-001');

        $sku = DB::table('sku')->where('sku_code', 'SKU-MINI-COMP-001')->first();
        $this->assertNotNull($sku);
        $this->assertSame('COMPONENT', strtoupper((string) $sku->sku_type));
        // Linh kiện chọn từ MÃ ITEM = dán mã từng cái -> quét +1, không cần hộp.
        $this->assertTrue((bool) $sku->is_serial_tracked);
    }

    public function test_item_code_of_box_counted_component_still_requires_a_box_code(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();

        // Linh kiện ĐẾM THEO HỘP đã có sẵn trong master data.
        DB::table('sku')->insert([
            'sku_id' => (string) Str::uuid(),
            'sku_code' => 'SKU-MINI-BOXCOMP-001',
            'sku_name' => 'Vong bi 6203',
            'sku_type' => 'COMPONENT',
            'unit' => 'Cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Quet nham ma item cua linh kien dem hop',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => 'HN1|SKU=SKU-MINI-BOXCOMP-001|ITEM=BOXCOMP-0001', 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-boxcomp-001'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MINI_APP_COMPONENT_FLOW_REQUIRES_BOX');
    }

    public function test_record_accepts_resolve_code_row_verbatim_with_the_new_echo_fields(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();
        $raw = 'HN1|SKU=SKU-MINI-NEW-003|ITEM=MINI-NEW-0003|SER=MINI-NEW-0003';

        $resolved = $this->postJson('/api/v1/mini-app/inbound/resolve-code', [
            'warehouse_id' => (string) $warehouse->id,
            'raw_code' => $raw,
        ], $headers)->assertOk()->json('data');

        // Mini App chép nguyên row local (bỏ các field của riêng bước resolve) rồi
        // gửi lại: hai field MỚI is_serial_tracked/requires_new_sku_type không
        // được làm vỡ hàng rào assertRecordPayloadShape().
        unset(
            $resolved['warehouse_id'],
            $resolved['stock_effect'],
            $resolved['movement_created'],
            $resolved['scan_evidence_created'],
            $resolved['wms_document_created'],
        );
        $resolved['scan_source'] = 'CAMERA';
        $resolved['new_sku_type'] = 'PRODUCT';

        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Gui lai nguyen row resolve',
            'warehouse_id' => (string) $warehouse->id,
            'items' => [$resolved],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-echo-003'])
            ->assertCreated()
            ->assertJsonPath('data.product_info_complete', false);
    }

    public function test_record_raises_the_pending_info_event_only_when_something_is_missing(): void
    {
        Event::fake([InboundSkuInfoPending::class]);

        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();

        $record = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap Mini App bao chuong',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => 'HN1|SKU=SKU-MINI-NEW-004|ITEM=MINI-NEW-0004', 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-notify-004'])->assertCreated();

        $documentId = (string) $record->json('data.document_id');
        Event::assertDispatched(
            InboundSkuInfoPending::class,
            fn (InboundSkuInfoPending $event): bool => $event->documentId === $documentId
                && $event->skuCodes === ['SKU-MINI-NEW-004']
                && $event->actorId === $headers['__user_id']
        );

        // Gửi lại đúng Idempotency-Key: replay không được bắn chuông lần hai.
        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap Mini App bao chuong',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => 'HN1|SKU=SKU-MINI-NEW-004|ITEM=MINI-NEW-0004', 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-notify-004'])->assertCreated();
        Event::assertDispatchedTimes(InboundSkuInfoPending::class, 1);

        // SKU đã đủ thông tin thì không làm phiền ai.
        $sku = $this->productSkuFixture('SKU-MINI-OLD-004');
        $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Nhap SKU du thong tin',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM=MINI-OLD-0004', 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-notify-005'])->assertCreated();
        Event::assertDispatchedTimes(InboundSkuInfoPending::class, 1);
    }

    public function test_pending_info_notification_reaches_people_who_can_supplement(): void
    {
        // Listener là ShouldQueue + afterCommit: dưới RefreshDatabase nó không bao
        // giờ chạy qua đường HTTP (transaction của test không commit). Gọi thẳng
        // handler để kiểm đúng phần nghiệp vụ của nó: gửi cho ai, trừ ai.
        $scanner = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $office = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();

        app(SendInboundSkuInfoPendingNotification::class)->handle(new InboundSkuInfoPending(
            documentId: (string) Str::uuid(),
            docNo: 'PN-TEST-0001',
            warehouseId: (string) $warehouse->id,
            actorId: $scanner['__user_id'],
            skuCodes: ['SKU-MINI-NEW-004'],
        ));

        $notification = DB::table('notifications')->where('event_key', 'inbound.sku_info_pending')->first();
        $this->assertNotNull($notification, 'Phải có thông báo cho người bổ sung thông tin.');
        $this->assertSame('warning', (string) $notification->severity);

        $recipients = DB::table('notification_recipients')
            ->where('notification_id', $notification->id)
            ->pluck('user_id')
            ->all();

        $this->assertContains($office['__user_id'], $recipients);
        // Người quét ở Mini App không tự bắn chuông cho chính mình.
        $this->assertNotContains($scanner['__user_id'], $recipients);
    }

    public function test_web_inbound_list_flags_and_filters_documents_pending_product_info(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();

        $pending = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Phieu con thieu thong tin',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => 'HN1|SKU=SKU-MINI-NEW-005|ITEM=MINI-NEW-0005', 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-list-005'])->assertCreated();

        $sku = $this->productSkuFixture('SKU-MINI-OLD-006');
        $complete = $this->postJson('/api/v1/mini-app/inbound/record', [
            'name' => 'Phieu du thong tin',
            'dst_warehouse_id' => (string) $warehouse->id,
            'items' => [['raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM=MINI-OLD-0006', 'scan_source' => 'CAMERA']],
        ], $headers + ['Idempotency-Key' => 'mini-new-sku-list-006'])->assertCreated();

        $pendingId = (string) $pending->json('data.document_id');
        $completeId = (string) $complete->json('data.document_id');

        $flags = collect($this->getJson('/api/v1/inbound-documents', $headers)->assertOk()->json('data'))
            ->pluck('pending_product_info', 'id');
        $this->assertTrue((bool) $flags[$pendingId]);
        $this->assertFalse((bool) $flags[$completeId]);

        $onlyPending = collect($this->getJson('/api/v1/inbound-documents?pending_product_info=1', $headers)
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($pendingId, $onlyPending);
        $this->assertNotContains($completeId, $onlyPending);

        $onlyComplete = collect($this->getJson('/api/v1/inbound-documents?pending_product_info=0', $headers)
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($completeId, $onlyComplete);
        $this->assertNotContains($pendingId, $onlyComplete);
    }
}
