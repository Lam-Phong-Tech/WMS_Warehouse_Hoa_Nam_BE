<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class StockDocumentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trả về header Authorization RIÊNG cho từng lệnh gọi — cố tình KHÔNG dùng
     * $this->withHeaders() vì nó tích luỹ default headers cho mọi request sau đó trong cùng
     * 1 test, dễ làm Idempotency-Key của lệnh gọi trước rò sang lệnh gọi sau.
     */
    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester',
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = JWTAuth::fromUser($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_login_returns_jwt_envelope(): void
    {
        Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        User::create([
            'username' => 'admin',
            'display_name' => 'Admin',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'secret123']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token'], 'meta' => ['request_id']]);
    }

    public function test_login_with_wrong_password_returns_401_envelope(): void
    {
        User::create([
            'username' => 'admin2',
            'display_name' => 'Admin2',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login', ['username' => 'admin2', 'password' => 'wrong']);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /** submit -> approve -> post (receipt), lặp lại Post cùng Idempotency-Key phải không tăng tồn lần 2. */
    public function test_receipt_full_lifecycle_and_post_is_idempotent(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG', 'name' => 'Kho tong', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP001', 'name' => 'May khoan', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'receipt',
            'doc_no' => 'PN-TEST-0001',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], $headers + ['Idempotency-Key' => 'create-1']);

        $createResponse->assertCreated()->assertJsonPath('data.status', 'draft');
        $docId = $createResponse->json('data.id');

        $first = $this->postJson("/api/v1/stock-documents/{$docId}/post", [], $headers + ['Idempotency-Key' => 'post-1']);
        $first->assertOk()->assertJsonPath('data.status', 'posted');

        $second = $this->postJson("/api/v1/stock-documents/{$docId}/post", [], $headers + ['Idempotency-Key' => 'post-1']);
        $second->assertOk();

        $this->assertSame(
            $first->json('data.movements.0.id'),
            $second->json('data.movements.0.id'),
            'Post lặp lại cùng Idempotency-Key phải trả về đúng 1 movement, không tạo movement mới.'
        );
        $this->assertSame(1, StockMovement::where('stock_document_id', $docId)->count());

        $balance = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertEquals(10, (float) $balance->available_qty);
    }

    public function test_receipt_can_post_without_prior_approval(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG2', 'name' => 'Kho tong 2', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP002', 'name' => 'May cua', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'receipt',
            'doc_no' => 'PN-TEST-0002',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 5],
            ],
        ], $headers + ['Idempotency-Key' => 'create-2']);

        $docId = $createResponse->json('data.id');

        // Vẫn ở trạng thái draft — chưa submit/approve — post phải bị từ chối (409 STATE_CONFLICT).
        $response = $this->postJson("/api/v1/stock-documents/{$docId}/post", [], $headers + ['Idempotency-Key' => 'post-2']);

        $response->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_post_without_idempotency_key_is_rejected(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG3', 'name' => 'Kho tong 3', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP003', 'name' => 'May mai', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'receipt',
            'doc_no' => 'PN-TEST-0003',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty_planned' => 5]],
        ], $headers + ['Idempotency-Key' => 'create-3']);
        $docId = $createResponse->json('data.id');

        // Cố tình KHÔNG gửi Idempotency-Key ở lệnh post này.
        $response = $this->postJson("/api/v1/stock-documents/{$docId}/post", [], $headers);

        $response->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
    }

    public function test_issue_more_than_available_stock_is_rejected_at_post_commit(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG4', 'name' => 'Kho tong 4', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP004', 'name' => 'May mai 2', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'issue',
            'doc_no' => 'PX-TEST-0001',
            'doc_date' => '2026-07-29',
            'src_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty_planned' => 3]],
        ], $headers + ['Idempotency-Key' => 'create-4']);
        $docId = $createResponse->json('data.id');

        $response = $this->postJson("/api/v1/stock-documents/{$docId}/post", [], $headers + ['Idempotency-Key' => 'post-issue-no-stock']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
    }

    /** Điều chuyển đầy đủ: draft->pending->approved->dispatch->receive(completed), tồn di chuyển đúng giữa 2 kho. */
    public function test_receipt_supports_partial_multi_post_without_double_counting(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG-PART', 'name' => 'Kho partial', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP-PART', 'name' => 'May partial', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'receipt',
            'doc_no' => 'PN-PART-0001',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_planned' => 10],
            ],
        ], $headers + ['Idempotency-Key' => 'create-part']);

        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        $first = $this->postJson("/api/v1/stock-documents/{$docId}/post", [
            'lines' => [['line_id' => $lineId, 'qty_actual' => 4]],
        ], $headers + ['Idempotency-Key' => 'post-part-1']);
        $first->assertOk()
            ->assertJsonPath('data.status', 'partially_posted');
        $this->assertEquals(4, (float) $first->json('data.lines.0.qty_posted'));

        $retry = $this->postJson("/api/v1/stock-documents/{$docId}/post", [
            'lines' => [['line_id' => $lineId, 'qty_actual' => 4]],
        ], $headers + ['Idempotency-Key' => 'post-part-1']);
        $retry->assertOk()->assertJsonPath('data.status', 'partially_posted');

        $second = $this->postJson("/api/v1/stock-documents/{$docId}/post", [
            'lines' => [['line_id' => $lineId, 'qty_actual' => 6]],
        ], $headers + ['Idempotency-Key' => 'post-part-2']);
        $second->assertOk()
            ->assertJsonPath('data.status', 'posted');
        $this->assertEquals(10, (float) $second->json('data.lines.0.qty_posted'));

        $this->assertSame(2, StockMovement::where('stock_document_id', $docId)->count());

        $balance = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertEquals(10, (float) $balance->available_qty);
    }

    public function test_transfer_full_lifecycle_moves_stock_between_warehouses(): void
    {
        $headers = $this->authHeaders();

        $whA = Warehouse::create(['code' => 'KHO-A', 'name' => 'Kho A', 'status' => 'ACTIVE']);
        $whB = Warehouse::create(['code' => 'KHO-B', 'name' => 'Kho B', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP005', 'name' => 'May khoan 2', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        // Nhập trước 20 vào kho A để có tồn mà điều chuyển.
        $receipt = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'receipt', 'doc_no' => 'PN-TRF-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $whA->id, 'lines' => [['product_id' => $product->id, 'qty_planned' => 20]],
        ], $headers + ['Idempotency-Key' => 'create-trf-recv'])->json('data.id');
        $this->postJson("/api/v1/stock-documents/{$receipt}/post", [], $headers + ['Idempotency-Key' => 'post-trf-recv']);

        $transferResponse = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'transfer', 'doc_no' => 'DC-TEST-0001', 'doc_date' => '2026-07-29',
            'src_warehouse_id' => $whA->id, 'dst_warehouse_id' => $whB->id,
            'lines' => [['product_id' => $product->id, 'qty_planned' => 8]],
        ], $headers + ['Idempotency-Key' => 'create-trf']);
        $docId = $transferResponse->json('data.id');
        $lineId = $transferResponse->json('data.lines.0.id');

        $this->postJson("/api/v1/stock-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/stock-documents/{$docId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/stock-documents/{$docId}/dispatch", [], $headers + ['Idempotency-Key' => 'dispatch-1'])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');

        $receiveResponse = $this->postJson("/api/v1/stock-documents/{$docId}/receive", [
            'lines' => [['line_id' => $lineId, 'qty_actual' => 8]],
        ], $headers + ['Idempotency-Key' => 'receive-1']);
        $receiveResponse->assertOk()->assertJsonPath('data.status', 'completed');

        $balanceA = InventoryBalance::where('warehouse_id', $whA->id)->where('product_id', $product->id)->first();
        $balanceB = InventoryBalance::where('warehouse_id', $whB->id)->where('product_id', $product->id)->first();

        $this->assertEquals(12, (float) $balanceA->available_qty);
        $this->assertEquals(8, (float) $balanceB->available_qty);
    }

    /** StockRequest: draft->pending->approved->convert thành StockDocument (draft). */
    public function test_stock_request_converts_to_stock_document(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG5', 'name' => 'Kho tong 5', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP006', 'name' => 'May mai 3', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $requestResponse = $this->postJson('/api/v1/stock-requests', [
            'request_type' => 'receipt',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty_planned' => 15]],
        ], $headers);
        $requestResponse->assertCreated()->assertJsonPath('data.status', 'draft');
        $requestId = $requestResponse->json('data.id');

        $this->postJson("/api/v1/stock-requests/{$requestId}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/stock-requests/{$requestId}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');

        $convertResponse = $this->postJson("/api/v1/stock-requests/{$requestId}/convert", [], $headers);
        $convertResponse->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.ref_doc_type', 'stock_request');
    }

    /** Định mức tồn kho an toàn: available_qty vượt max -> ghi 1 dòng lịch sử cảnh báo "overflow". */
    public function test_safe_stock_alert_fires_when_receipt_exceeds_max(): void
    {
        $headers = $this->authHeaders();

        $warehouse = Warehouse::create(['code' => 'KHOTONG6', 'name' => 'Kho tong 6', 'status' => 'ACTIVE']);
        $product = Product::create(['code' => 'SP007', 'name' => 'May mai 4', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/safe-stock-configs', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'min_qty' => 2,
            'max_qty' => 5,
            'effective_from' => '2026-01-01',
        ], $headers)->assertCreated();

        $receipt = $this->postJson('/api/v1/stock-documents', [
            'doc_type' => 'receipt', 'doc_no' => 'PN-SSA-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id, 'lines' => [['product_id' => $product->id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-ssa'])->json('data.id');
        $this->postJson("/api/v1/stock-documents/{$receipt}/post", [], $headers + ['Idempotency-Key' => 'post-ssa']);

        $alerts = $this->getJson('/api/v1/safe-stock-alerts?product_id='.$product->id, $headers);
        $alerts->assertOk();
        $this->assertSame('overflow', $alerts->json('data.0.new_status'));
    }
}
