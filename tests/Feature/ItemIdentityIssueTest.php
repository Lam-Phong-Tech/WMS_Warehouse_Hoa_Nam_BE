<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * POST /api/v1/skus/{id}/items — trước khi có endpoint này, hệ thống không có
 * cách nào (UI lẫn API) tạo Item_Instance kèm Physical_Code MỚI cùng lúc:
 * CurrentBaselineItemIdentityController@assign và ItemInstanceController@store
 * đều đòi hỏi physical_code_id/item ĐÃ tồn tại sẵn. Test này khoá lại hành vi
 * tạo mới atomically + stock-neutral + RBAC qua permission physical_code.create.
 */
class ItemIdentityIssueTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(array $permissions = []): array
    {
        $role = Role::create(['role_code' => 'TEST_ROLE_'.Str::upper(Str::random(6)), 'role_name' => 'Test Role', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);

        if ($permissions !== []) {
            foreach ($permissions as $key) {
                $permission = Permission::firstOrCreate(
                    ['permission_key' => $key],
                    ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
                );
                $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
            }
        }

        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'idem-'.Str::random(20)];
    }

    private function createSerialTrackedSku(): string
    {
        $skuId = (string) Str::uuid();
        \DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'SKU-ISSUE-'.Str::upper(Str::random(4)),
            'sku_name' => 'Máy khoan test',
            'sku_type' => 'PRODUCT',
            'unit' => 'cái',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $skuId;
    }

    public function test_issue_creates_item_instance_and_physical_code_atomically(): void
    {
        $headers = $this->authHeaders(['physical_code.create']);
        $skuId = $this->createSerialTrackedSku();

        $response = $this->postJson("/api/v1/skus/{$skuId}/items", ['carrier_type' => 'QR'], $headers);

        $response->assertCreated();
        $response->assertJsonPath('data.sku_id', $skuId);
        $response->assertJsonPath('data.carrier_type', 'QR');
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.item_status', 'NEW');
        $response->assertJsonPath('data.stock_effect', 'NONE');

        $itemId = $response->json('data.item_id');
        $physicalCodeId = $response->json('data.physical_code_id');
        $codeValue = $response->json('data.code_value');

        $this->assertDatabaseHas('item_instances', [
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'physical_code_id' => $physicalCodeId,
            'item_status' => 'NEW',
        ]);
        $this->assertDatabaseHas('physical_codes', [
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'QR',
            'item_id' => $itemId,
            'is_primary' => 1,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'item_instance',
            'object_id' => $itemId,
            'action' => 'ITEM_ISSUE_NEW_CODE',
        ]);
    }

    public function test_issue_accepts_custom_code_value_and_serial_number(): void
    {
        $headers = $this->authHeaders(['physical_code.create']);
        $skuId = $this->createSerialTrackedSku();

        $response = $this->postJson("/api/v1/skus/{$skuId}/items", [
            'carrier_type' => 'BARCODE',
            'code_value' => 'CUSTOM-CODE-0001',
            'serial_number' => 'CUSTOM-SERIAL-0001',
        ], $headers);

        $response->assertCreated();
        $response->assertJsonPath('data.code_value', 'CUSTOM-CODE-0001');
        $response->assertJsonPath('data.serial_number', 'CUSTOM-SERIAL-0001');
        $response->assertJsonPath('data.carrier_type', 'BARCODE');
    }

    public function test_issue_rejects_duplicate_code_value(): void
    {
        $headers = $this->authHeaders(['physical_code.create']);
        $skuId = $this->createSerialTrackedSku();

        $first = $this->postJson("/api/v1/skus/{$skuId}/items", ['code_value' => 'DUP-CODE-0001'], $headers);
        $first->assertCreated();

        $second = $this->postJson("/api/v1/skus/{$skuId}/items", ['code_value' => 'DUP-CODE-0001'], $this->authHeaders(['physical_code.create']));
        $second->assertStatus(422);
    }

    public function test_issue_rejects_sku_not_serial_tracked(): void
    {
        $headers = $this->authHeaders(['physical_code.create']);
        $skuId = (string) Str::uuid();
        \DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'COMP-'.Str::upper(Str::random(4)),
            'sku_name' => 'Linh kiện test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cái',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/skus/{$skuId}/items", [], $headers);

        $response->assertStatus(422)->assertJsonPath('error.code', 'SKU_NOT_SERIAL_TRACKED');
    }

    public function test_issue_rejects_inactive_sku_with_404(): void
    {
        $headers = $this->authHeaders(['physical_code.create']);
        $skuId = (string) Str::uuid();
        \DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'INACTIVE-'.Str::upper(Str::random(4)),
            'sku_name' => 'SKU inactive',
            'sku_type' => 'PRODUCT',
            'unit' => 'cái',
            'is_serial_tracked' => true,
            'status' => 'INACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/skus/{$skuId}/items", [], $headers);

        $response->assertStatus(404)->assertJsonPath('error.code', 'SKU_NOT_FOUND');
    }

    public function test_issue_requires_permission(): void
    {
        $headers = $this->authHeaders([]); // no permission granted
        $skuId = $this->createSerialTrackedSku();

        $response = $this->postJson("/api/v1/skus/{$skuId}/items", [], $headers);

        $response->assertStatus(403);
    }

    public function test_issue_requires_idempotency_key(): void
    {
        $headers = $this->authHeaders(['physical_code.create']);
        unset($headers['Idempotency-Key']);
        $skuId = $this->createSerialTrackedSku();

        $response = $this->postJson("/api/v1/skus/{$skuId}/items", [], $headers);

        $response->assertStatus(428);
    }

    public function test_issue_replays_same_response_for_same_idempotency_key(): void
    {
        // Không truyền code_value tường minh: nếu gửi cùng code_value ở lần
        // retry, validate() unique chạy TRƯỚC IdempotencyStore::handle() nên sẽ
        // 422 vì code đã được insert ở lần gọi đầu — cùng hành vi với
        // CurrentBaselineItemIdentityController::assign() hiện có (không phải
        // regression của issue()). Bỏ trống để server tự sinh, đúng path replay
        // thực tế mà client sẽ dùng khi gọi lại nguyên request cũ.
        $headers = $this->authHeaders(['physical_code.create']);
        $skuId = $this->createSerialTrackedSku();

        $first = $this->postJson("/api/v1/skus/{$skuId}/items", ['carrier_type' => 'QR'], $headers);
        $first->assertCreated();

        $second = $this->postJson("/api/v1/skus/{$skuId}/items", ['carrier_type' => 'QR'], $headers);
        $second->assertCreated();
        $this->assertSame($first->json('data.item_id'), $second->json('data.item_id'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        $this->assertDatabaseCount('item_instances', 1);
    }
}
