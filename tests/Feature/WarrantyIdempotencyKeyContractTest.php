<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use Tests\Feature\Concerns\PreparesWarrantyOwnerFixtures;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WarrantyIdempotencyKeyContractTest extends TestCase
{
    use PreparesWarrantyOwnerFixtures;
    use RefreshDatabase;

    public function test_missing_idempotency_key_returns_428_without_warranty_effect(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $case = $this->createWarrantyCase($user, 'WARRANTY-IDEM-MISSING');

        $this->postJson(
            "/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"]
        )
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $this->assertNoWarrantyEffect($case);
    }

    public function test_short_and_oversized_idempotency_keys_return_422_without_warranty_effect(): void
    {
        [$user, $token] = $this->createSuperAdmin();

        foreach (['short', str_repeat('k', 101)] as $index => $invalidKey) {
            $case = $this->createWarrantyCase($user, 'WARRANTY-IDEM-INVALID-'.$index);

            $this->postJson(
                "/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only",
                $this->validPayload(),
                [
                    'Authorization' => "Bearer {$token}",
                    'Idempotency-Key' => $invalidKey,
                ]
            )
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');

            $this->assertNoWarrantyEffect($case);
        }
    }

    public function test_boundary_idempotency_keys_are_accepted(): void
    {
        [$user, $token] = $this->createSuperAdmin();

        foreach ([str_repeat('a', 8), str_repeat('b', 100)] as $index => $validKey) {
            $case = $this->createWarrantyCase($user, 'WARRANTY-IDEM-BOUNDARY-'.$index);

            $this->postJson(
                "/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only",
                $this->validPayload(),
                [
                    'Authorization' => "Bearer {$token}",
                    'Idempotency-Key' => $validKey,
                ]
            )
                ->assertOk()
                ->assertJsonPath('data.stock_effect', 'NONE')
                ->assertJsonPath('data.movement_created', false);

            $this->assertSame(1, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
        }
    }

    /**
     * Annex C createWarrantyCase khai Idempotency/If-Match = "-" và §8.1 nhóm Warranty
     * không liệt kê 428; §13.3 chỉ bắt idempotency cho action đổi tồn. Vì vậy thiếu
     * header phải đi qua (case stock-neutral), còn có header thì vẫn replay.
     */
    public function test_warranty_intake_accepts_missing_key_and_replays_when_key_supplied(): void
    {
        [, $token] = $this->createSuperAdmin();
        $headers = ['Authorization' => "Bearer {$token}"];
        $payload = [
            'warehouse_id' => $this->ensureActiveWarehouse('KHO-TONG-HN')->id,
            'customer_name' => 'Khach Idem Baseline',
            'customer_phone' => '0900000001',
            'description' => 'May hong nguon',
            'missing_code_reason' => 'Mat tem ma',
            'manual_product_description' => 'May khoan idem baseline',
        ];

        $before = WarrantyCase::query()->count();
        $this->postJson('/api/v1/warranty-cases', $payload, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $this->assertSame($before + 1, WarrantyCase::query()->count());

        $before = WarrantyCase::query()->count();
        $key = ['Idempotency-Key' => 'warranty-create-replay-0001'];
        $first = $this->postJson('/api/v1/warranty-cases', $payload, $headers + $key);
        $first->assertCreated()->assertHeader('Idempotent-Replay', 'false');
        $caseId = (string) $first->json('data.id');

        $replay = $this->postJson('/api/v1/warranty-cases', $payload, $headers + $key);
        $replay->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $caseId);
        $this->assertSame($before + 1, WarrantyCase::query()->count());
    }

    /**
     * Annex C transitionWarrantyStatus khai duy nhất If-Match. Thiếu Idempotency-Key vẫn
     * chạy (If-Match là guard chống double-apply: lần hai trả 412), có key thì replay.
     */
    public function test_warranty_update_without_key_relies_on_if_match_and_replays_with_key(): void
    {
        [, $token] = $this->createSuperAdmin();
        $headers = ['Authorization' => "Bearer {$token}"];

        $create = $this->postJson('/api/v1/warranty-cases', [
            'warehouse_id' => $this->ensureActiveWarehouse('KHO-TONG-HN')->id,
            'customer_name' => 'Khach Update Idem',
            'customer_phone' => '0900000002',
            'description' => 'Loi motor',
            'missing_code_reason' => 'Mat tem ma',
            'manual_product_description' => 'May cat idem',
        ], $headers + ['Idempotency-Key' => 'warranty-update-idem-setup-0001']);
        $create->assertCreated();
        $caseId = (string) $create->json('data.id');

        // Không Idempotency-Key: đi qua bình thường, version tăng 1 -> 2.
        $this->patchJson(
            "/api/v1/warranty-cases/{$caseId}",
            ['status' => 'CHECKING'],
            $headers + ['If-Match' => '1']
        )
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.version', 2);

        // Lặp lại cùng If-Match cũ mà không có key -> 412, không double-apply.
        $this->patchJson(
            "/api/v1/warranty-cases/{$caseId}",
            ['status' => 'CHECKING'],
            $headers + ['If-Match' => '1']
        )
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        // DEC-WAR-STATE-001: rời CHECKING bắt buộc kèm confirmed_defect. Payload của lần
        // gọi đầu và lần retry phải GIỐNG HỆT, nếu không idempotency trả 409 thay vì replay.
        $key = ['Idempotency-Key' => 'warranty-update-replay-0001'];
        $repairPayload = ['status' => 'REPAIRING', 'confirmed_defect' => 'Chay cuon day motor'];
        $update = $this->patchJson(
            "/api/v1/warranty-cases/{$caseId}",
            $repairPayload,
            $headers + $key + ['If-Match' => '2']
        );
        $update->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.version', 3);

        // Retry cùng key/payload sau khi thành công phải trả snapshot cũ,
        // không tạo event mới và không tăng version (kể cả If-Match đã cũ).
        $retry = $this->patchJson(
            "/api/v1/warranty-cases/{$caseId}",
            $repairPayload,
            $headers + $key + ['If-Match' => '2']
        );
        $retry->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.status', 'REPAIRING');
        // 1 RECEIVE + 1 CHECKING + 1 REPAIRING; replay không thêm event.
        $this->assertSame(
            3,
            WarrantyEvent::query()->where('warranty_case_id', $caseId)->count(),
        );
        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $caseId,
            'version' => 3,
        ]);
    }

    private function assertNoWarrantyEffect(WarrantyCase $case): void
    {
        $this->assertSame(0, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $case->warranty_case_id,
            'status' => WarrantyCase::STATUS_RECEIVED,
            'version' => 1,
        ]);
    }

    private function validPayload(): array
    {
        return [
            // temporary_item_code unique — mỗi case một mã tạm riêng.
            'temporary_item_code' => 'TEMP-IDEM-'.Str::upper(Str::random(10)),
            'missing_code_reason' => 'Kiểm tra contract Idempotency-Key',
            'manual_product_description' => 'Máy mô tả thủ công (contract)',
            'condition_status' => 'WAITING_CHECK',
        ];
    }

    private function createSuperAdmin(): array
    {
        $role = Role::query()->create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::query()->create([
            'username' => 'warranty-idempotency-admin',
            'display_name' => 'Warranty Idempotency Admin',
            'email' => 'warranty-idempotency-admin@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createWarrantyCase(User $user, string $code): WarrantyCase
    {
        $case = WarrantyCase::query()->create([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => $code,
            'inventory_serial_id' => null,
            'product_id' => null,
            // chk_erd_warranty_identification_shape: TEMP_ONLY yêu cầu đủ bộ
            // temporary_item_code + missing_code_reason + manual_product_description.
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-'.Str::upper(Str::random(8)),
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy mô tả thủ công (fixture)',
            // ERD bảng 45: customer_name/customer_phone NOT NULL (SCR-WAR-01 bắt buộc).
            'customer_name' => 'Khách idem test',
            'customer_phone' => '0900000001',
            'status' => WarrantyCase::STATUS_RECEIVED,
            'created_by' => $user->user_id,
            'version' => 1,
        ]);
        $this->bindWarrantyOwnerFixture($case->warranty_case_id, $user->user_id, $this->ensureActiveWarehouse('KHO-TONG-HN')->id);

        return $case;
    }
}
