<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

/**
 * 2026-08-26 — trước fix này, `warranty_case.updated_by` không bao giờ được
 * set khi đổi trạng thái/sửa case (cột tồn tại trong schema nhưng câu UPDATE
 * ở CurrentBaselineWarrantyController thiếu key này). Muốn biết ai sửa case
 * gần nhất phải suy gián tiếp qua warranty_event.created_by mới nhất — không
 * sai nhưng thiếu nhất quán với created_by/stock_documents.*_by.
 *
 * Test này khoá lại bất biến: sau PATCH warranty-cases/{id}, updated_by phải
 * bằng actor thực hiện request.
 */
class WarrantyCaseActorTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function actorHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'war-actor-'.Str::lower(Str::random(6)),
            'display_name' => 'Warranty Actor Tracking Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'user_id' => (string) $user->user_id,
        ];
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => 'war-actor-'.(string) Str::uuid()];
    }

    public function test_updated_by_is_stamped_when_warranty_case_status_changes(): void
    {
        $actor = $this->actorHeaders();

        $sku = Sku::create([
            'sku_code' => 'PROD-ACTOR-1',
            'sku_name' => 'San pham theo doi actor',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-ACTOR-1',
            'item_status' => 'ISSUED',
        ]);

        $created = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => $this->ensureActiveWarehouse('KHO-TONG-HN')->id,
            'item_id' => $item->item_id,
            'customer_name' => 'Nguyen Van Actor',
            'customer_phone' => '0901234567',
            'description' => 'May khong khoi dong',
        ], $actor['headers'] + $this->idem());
        $created->assertCreated();
        $caseId = (string) $created->json('data.id');

        // created_by phải đúng actor ngay từ lúc tạo, và updated_by chưa set.
        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $caseId,
            'created_by' => $actor['user_id'],
        ]);
        $row = DB::table('warranty_case')->where('warranty_case_id', $caseId)->first();
        self::assertNull($row->updated_by);

        $patched = $this->patchJson(
            "/api/v1/warranty-cases/{$caseId}",
            ['status' => 'CHECKING'],
            $actor['headers'] + $this->idem() + ['If-Match' => '1']
        );
        $patched->assertOk()->assertJsonPath('data.status', 'CHECKING');

        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $caseId,
            'status' => 'CHECKING',
            'updated_by' => $actor['user_id'],
        ]);
    }
}
