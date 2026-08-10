<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class StockDocumentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester',
            'display_name' => 'Tester',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_login_returns_jwt_envelope(): void
    {
        Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        User::create([
            'username' => 'admin',
            'display_name' => 'Admin',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'Cntt@1234'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token'], 'meta' => ['request_id']]);
    }

    public function test_login_with_wrong_password_returns_401_envelope(): void
    {
        User::create([
            'username' => 'admin2',
            'display_name' => 'Admin2',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $this->postJson('/api/v1/auth/login', ['username' => 'admin2', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_warehouse_index_returns_single_warehouse_without_pagination(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG', 'name' => 'Kho tong', 'status' => 'ACTIVE']);

        $this->getJson('/api/v1/warehouses', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $warehouse->id);
    }

    public function test_warehouse_update_changes_contact_info(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG-U', 'name' => 'Kho tong', 'status' => 'ACTIVE']);

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", [
            'address' => 'Số 1 Đường ABC',
            'contact_person' => 'Nguyễn Văn A',
            'phone' => '0900000000',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.address', 'Số 1 Đường ABC')
            ->assertJsonPath('data.contact_person', 'Nguyễn Văn A');
    }

    public function test_inbound_full_lifecycle_and_post_is_idempotent(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG', 'name' => 'Kho tong', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP001', 'sku_name' => 'May khoan', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-0001',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-1']);

        $createResponse->assertCreated()->assertJsonPath('data.status', 'draft');
        $docId = $createResponse->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/inbound-documents/{$docId}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');

        $postHeaders = $headers + ['Idempotency-Key' => 'post-receipt-0001'];
        $first = $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], $postHeaders);
        $first->assertOk()->assertJsonPath('data.status', 'received');

        $second = $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], $postHeaders);
        $second->assertOk();

        $this->assertSame($first->json('data.movements.0.id'), $second->json('data.movements.0.id'));
        $this->assertSame(1, StockMovement::where('stock_document_id', $docId)->count());
        $balance = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->sku_id)->firstOrFail();
        $this->assertEquals(10, (float) $balance->available_qty);
    }

    public function test_post_receipt_without_prior_approval_is_rejected(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG2', 'name' => 'Kho tong 2', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP002', 'sku_name' => 'May cua', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $docId = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-0002',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 5]],
        ], $headers + ['Idempotency-Key' => 'create-2'])->json('data.id');

        $this->postJson(
            "/api/v1/inbound-documents/{$docId}/post-receipt",
            [],
            $headers + ['Idempotency-Key' => 'post-receipt-0002']
        )->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    public function test_post_receipt_without_idempotency_key_is_rejected(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG3', 'name' => 'Kho tong 3', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP003', 'sku_name' => 'May mai', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $docId = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-TEST-0003',
            'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 5]],
        ], $headers + ['Idempotency-Key' => 'create-3'])->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], $headers)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
    }

    public function test_issue_more_than_available_stock_is_rejected_at_approve(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG4', 'name' => 'Kho tong 4', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP004', 'sku_name' => 'May mai 2', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $docId = $this->postJson('/api/v1/outbound-documents', [
            'doc_no' => 'PX-TEST-0001',
            'doc_date' => '2026-07-29',
            'src_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 3]],
        ], $headers + ['Idempotency-Key' => 'create-4'])->json('data.id');

        $this->postJson("/api/v1/outbound-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/outbound-documents/{$docId}/approve", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
    }

    public function test_scan_is_read_only_until_explicit_post_receipt(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHO-SCAN', 'name' => 'Kho scan', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP008', 'sku_name' => 'May khoan 3', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-SCAN-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-scan']);
        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/approve", [], $headers)->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$docId}/scan", ['line_id' => $lineId, 'qty' => 4], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.scanned_qty_after', 4);

        $this->postJson("/api/v1/inbound-documents/{$docId}/scan", ['line_id' => $lineId, 'qty' => 6], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.scanned_qty_after', 10);

        $this->assertSame(0, StockMovement::where('stock_document_id', $docId)->count());
        $this->assertNull(InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->sku_id)->first());

        $this->postJson(
            "/api/v1/inbound-documents/{$docId}/post-receipt",
            [],
            $headers + ['Idempotency-Key' => 'scan-post-receipt']
        )->assertOk()->assertJsonPath('data.status', 'received');

        $balance = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->sku_id)->firstOrFail();
        $this->assertEquals(10, (float) $balance->available_qty);
        $this->assertSame(1, StockMovement::where('stock_document_id', $docId)->count());
    }

    public function test_close_remainder_route_is_not_exposed_in_full_post_baseline(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHO-CLOSE', 'name' => 'Kho close', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP009', 'sku_name' => 'May khoan 4', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-CLOSE-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-close']);
        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/scan", [
            'line_id' => $lineId, 'qty' => 6,
        ], $headers)->assertOk()->assertJsonPath('data.stock_effect', 'NONE');

        $closeResponse = $this->postJson("/api/v1/inbound-documents/{$docId}/close-remainder", [
            'reason' => 'Nhà cung cấp thiếu hàng',
        ], $headers);

        $closeResponse->assertNotFound();
        $this->assertDatabaseHas('stock_documents', ['id' => $docId, 'status' => 'approved']);
        $this->assertSame(0, StockMovement::where('stock_document_id', $docId)->count());
    }

    public function test_reverse_document_restores_balance(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHO-REV', 'name' => 'Kho reverse', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP010', 'sku_name' => 'May khoan 5', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $docId = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-REV-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-rev'])->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/post-receipt", [], $headers + ['Idempotency-Key' => 'post-rev'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$docId}/reverse", [
            'reason' => 'Nhập sai chứng từ',
        ], $headers + ['Idempotency-Key' => 'reverse-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $balance = InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_id', $product->sku_id)->firstOrFail();
        $this->assertEquals(0, (float) $balance->available_qty);
        $this->assertSame(2, StockMovement::where('stock_document_id', $docId)->count());
    }

    public function test_safe_stock_alert_fires_when_receipt_exceeds_max(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG6', 'name' => 'Kho tong 6', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP007', 'sku_name' => 'May mai 4', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/safe-stock-configs', [
            'product_id' => $product->sku_id,
            'warehouse_id' => $warehouse->id,
            'min_qty' => 2,
            'max_qty' => 5,
            'effective_from' => '2026-01-01',
        ], $headers)->assertCreated();

        $receipt = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-SSA-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-ssa'])->json('data.id');
        $this->postJson("/api/v1/inbound-documents/{$receipt}/submit", [], $headers);
        $this->postJson("/api/v1/inbound-documents/{$receipt}/approve", [], $headers);
        $this->postJson("/api/v1/inbound-documents/{$receipt}/post-receipt", [], $headers + ['Idempotency-Key' => 'post-ssa']);

        $alerts = $this->getJson('/api/v1/safe-stock-alerts?product_id='.$product->sku_id, $headers);
        $alerts->assertOk();
        $this->assertSame('overflow', $alerts->json('data.0.new_status'));
    }

    public function test_inventory_balances_and_stock_movements_endpoints(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG7', 'name' => 'Kho tong 7', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP011', 'sku_name' => 'May khoan 6', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $receipt = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-INV-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 7]],
        ], $headers + ['Idempotency-Key' => 'create-inv'])->json('data.id');
        $this->postJson("/api/v1/inbound-documents/{$receipt}/submit", [], $headers);
        $this->postJson("/api/v1/inbound-documents/{$receipt}/approve", [], $headers);
        $this->postJson("/api/v1/inbound-documents/{$receipt}/post-receipt", [], $headers + ['Idempotency-Key' => 'post-inv']);

        $balances = $this->getJson('/api/v1/inventory/balances?product_id='.$product->sku_id, $headers);
        $balances->assertOk()->assertJsonPath('data.0.status', 'normal');
        $this->assertEquals(7, (float) $balances->json('data.0.available_qty'));

        $movements = $this->getJson('/api/v1/stock-movements?product_id='.$product->sku_id, $headers);
        $movements->assertOk()->assertJsonCount(1, 'data');
        $movementId = $movements->json('data.0.id');

        $this->getJson("/api/v1/stock-movements/{$movementId}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.document.id', $receipt);
    }

    public function test_dashboard_quick_does_not_count_read_only_scan_as_stock_effect(): void
    {
        $headers = $this->authHeaders();
        $warehouse = Warehouse::create(['code' => 'KHOTONG8', 'name' => 'Kho tong 8', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP012', 'sku_name' => 'May khoan 7', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $createResponse = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-DASH-0001', 'doc_date' => '2026-07-29',
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 10]],
        ], $headers + ['Idempotency-Key' => 'create-dash']);
        $docId = $createResponse->json('data.id');
        $lineId = $createResponse->json('data.lines.0.id');

        $this->postJson("/api/v1/inbound-documents/{$docId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$docId}/scan", [
            'line_id' => $lineId, 'qty' => 4,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->getJson('/api/v1/reports/dashboard-quick?from_date=2026-07-01&to_date=2026-07-31', $headers)
            ->assertOk()
            ->assertJsonPath('data.receipt_qty', 0)
            ->assertJsonPath('data.documents_partial.inbound', 0)
            ->assertJsonPath('data.documents_partial.outbound', 0)
            ->assertJsonStructure(['data' => ['warranty_cases_by_status', 'reconciliation_warnings']]);
    }
}
