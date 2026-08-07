<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * Bổ sung 2 lớp phòng thủ chưa được cover ở StockDocumentIdempotencyTest (vốn luôn dùng
 * SUPER_ADMIN nên không bao giờ chạm nhánh 403): (1) middleware permission:<CODE> thực sự
 * chặn user thiếu quyền và mở cho user đủ quyền; (2) các lỗi validate/trạng thái 401/404/409/422
 * ở biên của Product/Warehouse/StockDocument.
 */
class PermissionAndValidationTest extends TestCase
{
    use RefreshDatabase;

    /** User gắn 1 role tuỳ ý chỉ có đúng tập permission_key truyền vào (rỗng = không quyền gì). */
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
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_request_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/warehouses');

        $response->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_user_without_required_permission_gets_403(): void
    {
        // viewer chỉ có warehouse.view, không có warehouse.manage.
        $headers = $this->authHeadersWithPermissions(['warehouse.view'], 'viewer_only');
        $warehouse = Warehouse::create(['code' => 'KHO-PERM1', 'name' => 'Kho perm 1', 'status' => 'ACTIVE']);

        $response = $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Kho moi'], $headers);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_user_with_required_permission_is_allowed(): void
    {
        $headers = $this->authHeadersWithPermissions(['warehouse.view', 'warehouse.manage'], 'manager_only');
        $warehouse = Warehouse::create(['code' => 'KHO-PERM2', 'name' => 'Kho perm 2', 'status' => 'ACTIVE']);

        $response = $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['name' => 'Kho moi'], $headers);

        $response->assertOk()->assertJsonPath('data.name', 'Kho moi');
    }

    public function test_user_without_any_permission_is_blocked_from_creating_product(): void
    {
        $headers = $this->authHeadersWithPermissions([], 'no_perms');

        $response = $this->postJson('/api/v1/products', ['sku_code' => 'SP-X', 'sku_name' => 'San pham X'], $headers);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_product_routes_use_product_permissions_not_sku_permissions(): void
    {
        $productHeaders = $this->authHeadersWithPermissions(['product.view'], 'product_view_only');
        $skuHeaders = $this->authHeadersWithPermissions(['sku.manage'], 'sku_only');

        Product::create(['code' => 'SP-PROD-PERM', 'name' => 'San pham product permission', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $this->getJson('/api/v1/products', $productHeaders)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/products', $skuHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->getJson('/api/v1/skus', $skuHeaders)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_product_create_with_duplicate_code_returns_422(): void
    {
        $headers = $this->authHeadersWithPermissions(['product.manage'], 'product_manager');
        Sku::create(['sku_code' => 'SP-DUP', 'sku_name' => 'San pham goc', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/products', ['sku_code' => 'SP-DUP', 'sku_name' => 'San pham trung code'], $headers);

        // 422 chung không phân biệt validate-rule vs business-rule — ApiConventionMiddleware
        // luôn suy ra BUSINESS_RULE_ERROR cho status 422 trừ khi message chứa từ khoá tồn kho.
        $response->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    public function test_product_detail_for_unknown_id_returns_404(): void
    {
        $headers = $this->authHeadersWithPermissions(['product.view'], 'product_viewer');

        $response = $this->getJson('/api/v1/products/'.Str::uuid(), $headers);

        $response->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_document_status_history_uses_stock_document_view_permission(): void
    {
        $stockDocumentHeaders = $this->authHeadersWithPermissions(['stock_document.view'], 'stock_doc_viewer');
        $warehouseHeaders = $this->authHeadersWithPermissions(['warehouse.view'], 'warehouse_viewer');

        $this->getJson('/api/v1/document-status-history', $stockDocumentHeaders)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/document-status-history', $warehouseHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_inbound_document_create_without_lines_returns_422(): void
    {
        $headers = $this->authHeadersWithPermissions(['stock_document.create'], 'creator');
        $warehouse = Warehouse::create(['code' => 'KHO-PERM3', 'name' => 'Kho perm 3', 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [],
        ], $headers + ['Idempotency-Key' => 'perm-create-no-lines']);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    /**
     * Idempotency-Key trên store() là tuỳ chọn (chỉ bắt buộc ở post-receipt/post-issue/reverse vì
     * những action đó đổi tồn thật) — nhưng nếu client có gửi, gọi lại đúng key phải trả về
     * đúng 1 chứng từ duy nhất thay vì tạo bản ghi thứ hai.
     */
    public function test_inbound_document_create_is_idempotent_when_key_reused(): void
    {
        $headers = $this->authHeadersWithPermissions(['stock_document.create'], 'creator2');
        $warehouse = Warehouse::create(['code' => 'KHO-PERM4', 'name' => 'Kho perm 4', 'status' => 'ACTIVE']);
        $product = Sku::create(['sku_code' => 'SP-PERM4', 'sku_name' => 'San pham perm 4', 'unit' => 'cai', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        $payload = [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->sku_id, 'qty_planned' => 1]],
        ];

        $first = $this->postJson('/api/v1/inbound-documents', $payload, $headers + ['Idempotency-Key' => 'perm-create-idem']);
        $second = $this->postJson('/api/v1/inbound-documents', $payload, $headers + ['Idempotency-Key' => 'perm-create-idem']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }
}
