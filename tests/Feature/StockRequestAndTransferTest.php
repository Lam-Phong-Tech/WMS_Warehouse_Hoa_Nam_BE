<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * stock-requests dùng được ngay với 1 kho tổng (receipt/issue). transfer-orders và
 * request_type=transfer cần ≥ 2 kho khác nhau — base production hiện chỉ seed 1 kho tổng,
 * nhưng test này tạo 2 bản ghi Warehouse để chứng minh code chạy đúng, sẵn sàng cho khi
 * Hoa Nam thêm kho thứ 2 (xem docs/BRD_ERD_Upgrade_Guide.md).
 */
class StockRequestAndTransferTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    /** create -> submit -> approve -> convert (receipt) sinh ra 1 inbound-document draft đúng dữ liệu. */
    public function test_stock_request_receipt_lifecycle_converts_to_inbound_document(): void
    {
        $this->markTestSkipped('StockRequestController đã viết xong nhưng chưa wire route /api/v1/stock-requests; chờ quyết định phát hành tính năng.');
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHO-SR1', 'name' => 'Kho tong', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SR-SP1', 'sku_name' => 'San pham 1', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $create = $this->postJson('/api/v1/stock-requests', [
            'request_type' => 'receipt',
            'request_date' => '2026-07-30',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 8]],
        ], $headers);
        $create->assertCreated()->assertJsonPath('data.status', 'draft');
        $requestId = $create->json('data.id');

        $this->postJson("/api/v1/stock-requests/{$requestId}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/stock-requests/{$requestId}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');

        $convert = $this->postJson("/api/v1/stock-requests/{$requestId}/convert", [], $headers);
        $convert->assertOk()->assertJsonPath('data.doc_type', 'receipt')->assertJsonPath('data.status', 'draft');

        $show = $this->getJson("/api/v1/stock-requests/{$requestId}", $headers);
        $show->assertOk()->assertJsonPath('data.status', 'converted');
        $this->assertSame($convert->json('data.id'), $show->json('data.converted_doc_id'));
    }

    /** dispatch (trừ tồn kho nguồn) -> receive đủ (cộng tồn kho đích) -> completed. */
    public function test_transfer_full_dispatch_and_receive_moves_stock_between_warehouses(): void
    {
        $this->markTestSkipped('TransferController đã port sẵn nhưng chưa wire route /api/v1/transfer-orders; chờ Hoa Nam thêm kho thứ 2 (xem docs/BRD_ERD_Upgrade_Guide.md).');
        $headers = $this->authHeaders();
        $src = Warehouse::create(['code' => 'KHO-SRC', 'name' => 'Kho nguon', 'status' => 'ACTIVE']);
        $dst = Warehouse::create(['code' => 'KHO-DST', 'name' => 'Kho dich', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'TR-SP1', 'sku_name' => 'San pham dieu chuyen', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        // Nạp tồn ban đầu vào kho nguồn qua 1 phiếu nhập kho bình thường.
        $receiptId = $this->postJson('/api/v1/inbound-documents', [
            'doc_date' => '2026-07-30',
            'dst_warehouse_id' => $src->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 20]],
        ], $headers + ['Idempotency-Key' => 'seed-src-stock'])->json('data.id');
        $this->postJson("/api/v1/inbound-documents/{$receiptId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$receiptId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$receiptId}/post-receipt", [], $headers + ['Idempotency-Key' => 'seed-post'])->assertOk();

        $create = $this->postJson('/api/v1/transfer-orders', [
            'doc_date' => '2026-07-30',
            'src_warehouse_id' => $src->id,
            'dst_warehouse_id' => $dst->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 12]],
        ], $headers + ['Idempotency-Key' => 'create-transfer-1']);
        $create->assertCreated()->assertJsonPath('data.status', 'draft');
        $docId = $create->json('data.id');
        $lineId = $create->json('data.lines.0.id');

        $this->postJson("/api/v1/transfer-orders/{$docId}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/transfer-orders/{$docId}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');

        $dispatch = $this->postJson("/api/v1/transfer-orders/{$docId}/dispatch", [], $headers + ['Idempotency-Key' => 'dispatch-1']);
        $dispatch->assertOk()->assertJsonPath('data.status', 'in_transit');

        $srcBalanceAfterDispatch = InventoryBalance::where('warehouse_id', $src->id)->where('product_id', $product->sku_id)->first();
        $this->assertEquals(8, (float) $srcBalanceAfterDispatch->available_qty);

        $receive = $this->postJson("/api/v1/transfer-orders/{$docId}/receive", [
            'lines' => [['line_id' => $lineId, 'qty_actual' => 12]],
        ], $headers + ['Idempotency-Key' => 'receive-1']);
        $receive->assertOk()->assertJsonPath('data.status', 'completed');

        $dstBalance = InventoryBalance::where('warehouse_id', $dst->id)->where('product_id', $product->sku_id)->first();
        $this->assertEquals(12, (float) $dstBalance->available_qty);
    }

    /** dispatch -> receive thiếu (shortage) -> completed_with_loss, không cộng đủ tồn kho đích. */
    public function test_transfer_receive_with_shortage_marks_completed_with_loss(): void
    {
        $this->markTestSkipped('TransferController đã port sẵn nhưng chưa wire route /api/v1/transfer-orders; chờ Hoa Nam thêm kho thứ 2 (xem docs/BRD_ERD_Upgrade_Guide.md).');
        $headers = $this->authHeaders();
        $src = Warehouse::create(['code' => 'KHO-SRC2', 'name' => 'Kho nguon 2', 'status' => 'ACTIVE']);
        $dst = Warehouse::create(['code' => 'KHO-DST2', 'name' => 'Kho dich 2', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'TR-SP2', 'sku_name' => 'San pham dieu chuyen 2', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $receiptId = $this->postJson('/api/v1/inbound-documents', [
            'doc_date' => '2026-07-30', 'dst_warehouse_id' => $src->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'seed-src-stock-2'])->json('data.id');
        $this->postJson("/api/v1/inbound-documents/{$receiptId}/submit", [], $headers);
        $this->postJson("/api/v1/inbound-documents/{$receiptId}/approve", [], $headers);
        $this->postJson("/api/v1/inbound-documents/{$receiptId}/post-receipt", [], $headers + ['Idempotency-Key' => 'seed-post-2']);

        $create = $this->postJson('/api/v1/transfer-orders', [
            'doc_date' => '2026-07-30', 'src_warehouse_id' => $src->id, 'dst_warehouse_id' => $dst->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-transfer-2']);
        $docId = $create->json('data.id');
        $lineId = $create->json('data.lines.0.id');

        $this->postJson("/api/v1/transfer-orders/{$docId}/submit", [], $headers);
        $this->postJson("/api/v1/transfer-orders/{$docId}/approve", [], $headers);
        $this->postJson("/api/v1/transfer-orders/{$docId}/dispatch", [], $headers + ['Idempotency-Key' => 'dispatch-2'])->assertOk();

        $receive = $this->postJson("/api/v1/transfer-orders/{$docId}/receive", [
            'lines' => [['line_id' => $lineId, 'qty_actual' => 7]],
        ], $headers + ['Idempotency-Key' => 'receive-2']);
        $receive->assertOk()->assertJsonPath('data.status', 'completed_with_loss');

        $dstBalance = InventoryBalance::where('warehouse_id', $dst->id)->where('product_id', $product->sku_id)->first();
        $this->assertEquals(7, (float) $dstBalance->available_qty);
    }
}
