<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * Bổ sung 2 lớp phòng thủ chưa được cover ở StockDocumentIdempotencyTest (vốn luôn dùng
 * SUPER_ADMIN nên không bao giờ chạm nhánh 403): (1) middleware permission:<CODE> thực sự
 * chặn user thiếu quyền và mở cho user đủ quyền; (2) các lỗi validate/trạng thái 401/404/409/422
 * ở biên của Product/Warehouse/StockDocument.
 */
class PermissionAndValidationTest extends TestCase
{
    use RefreshDatabase;

    private function authHeadersWithPermissions(array $permissionKeys, string $username = 'limited'): array
    {
        $role = Role::create(['role_code' => Str::upper($username), 'role_name' => 'Test role', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }

        $user = User::create([
            'username' => $username,
            'display_name' => 'Limited user',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_request_without_token_returns_401(): void
    {
        $this->getJson('/api/v1/warehouses')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_user_without_required_permission_gets_403(): void
    {
        $headers = $this->authHeadersWithPermissions(['warehouse.view'], 'viewer_only');
        $warehouse = Warehouse::create(['code' => 'KHO-PERM1', 'name' => 'Kho perm 1', 'status' => 'ACTIVE']);

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Kho moi'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_user_with_required_permission_is_allowed(): void
    {
        $headers = $this->authHeadersWithPermissions(['warehouse.view', 'warehouse.manage'], 'manager_only');
        $warehouse = Warehouse::create(['code' => 'KHO-PERM2', 'name' => 'Kho perm 2', 'status' => 'ACTIVE']);

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Kho moi'], $headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Kho moi');
    }

    public function test_user_without_any_permission_is_blocked_from_creating_product(): void
    {
        $headers = $this->authHeadersWithPermissions([], 'no_perms');

        $this->postJson('/api/v1/products', ['sku_code' => 'SP-X', 'sku_name' => 'San pham X'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_product_and_sku_routes_share_canonical_sku_permission(): void
    {
        $skuHeaders = $this->authHeadersWithPermissions(['sku.view'], 'sku_view_only');
        $noSkuHeaders = $this->authHeadersWithPermissions(['warehouse.view'], 'no_sku_permission');

        Sku::create(['code' => 'SP-PROD-PERM', 'name' => 'San pham product permission', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $this->getJson('/api/v1/products', $skuHeaders)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/products', $noSkuHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->getJson('/api/v1/skus', $skuHeaders)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_product_create_with_duplicate_code_returns_422(): void
    {
        $headers = $this->authHeadersWithPermissions(['sku.manage'], 'product_manager');
        Sku::create(['sku_code' => 'SP-DUP', 'sku_name' => 'San pham goc', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/products', ['sku_code' => 'SP-DUP', 'sku_name' => 'San pham trung code'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    public function test_product_detail_for_unknown_id_returns_404(): void
    {
        $headers = $this->authHeadersWithPermissions(['sku.view'], 'product_viewer');

        $this->getJson('/api/v1/products/'.Str::uuid(), $headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_document_status_history_uses_inventory_view_permission(): void
    {
        $inventoryHeaders = $this->authHeadersWithPermissions(['inventory.view'], 'inventory_viewer');
        $warehouseHeaders = $this->authHeadersWithPermissions(['warehouse.view'], 'warehouse_viewer');

        $this->getJson('/api/v1/document-status-history', $inventoryHeaders)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/document-status-history', $warehouseHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    /**
     * Hợp đồng GĐ1 scan-driven (2026-08-25): tạo phiếu HEADER-ONLY là luồng
     * canonical — không còn bắt buộc lines (422 BUSINESS_RULE_ERROR cũ). Gửi
     * lines có nội dung mới là lỗi nghiệp vụ: 422 INBOUND_SCAN_DRIVEN.
     */
    public function test_inbound_document_create_is_header_only_and_rejects_manual_lines(): void
    {
        $headers = $this->authHeadersWithPermissions(['inbound.create'], 'creator');
        $warehouse = Warehouse::create(['code' => 'KHO-TONG-HN', 'name' => 'Kho tong Hoa Nam', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP-PERM3', 'sku_name' => 'San pham perm 3', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        // Header-only (lines rỗng) được chấp nhận — dòng materialize từ scan.
        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [],
        ], $headers + ['Idempotency-Key' => 'perm-create-no-lines'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.lines', []);

        // Client cũ gửi lines/qty_planned bị chặn bằng lỗi nghiệp vụ rõ ràng.
        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 1]],
        ], $headers + ['Idempotency-Key' => 'perm-create-with-lines'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INBOUND_SCAN_DRIVEN');
    }

    public function test_inbound_document_create_is_idempotent_when_key_reused(): void
    {
        $headers = $this->authHeadersWithPermissions(['inbound.create'], 'creator2');
        $warehouse = Warehouse::create(['code' => 'KHO-TONG-HN', 'name' => 'Kho tong Hoa Nam', 'status' => 'ACTIVE']);

        // GĐ1 scan-driven: payload header-only (không còn lines/qty_planned).
        $payload = [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC idempotent create',
        ];

        $first = $this->postJson('/api/v1/inbound-documents', $payload, $headers + ['Idempotency-Key' => 'perm-create-idem']);
        $second = $this->postJson('/api/v1/inbound-documents', $payload, $headers + ['Idempotency-Key' => 'perm-create-idem']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }
}
