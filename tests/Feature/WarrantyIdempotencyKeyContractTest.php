<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WarrantyIdempotencyKeyContractTest extends TestCase
{
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
            'temporary_item_code' => 'TEMP-IDEMPOTENCY-CONTRACT',
            'missing_code_reason' => 'Kiểm tra contract Idempotency-Key',
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
        return WarrantyCase::query()->create([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => $code,
            'inventory_serial_id' => null,
            'product_id' => null,
            'identification_status' => 'UNIDENTIFIED',
            'status' => WarrantyCase::STATUS_RECEIVED,
            'created_by' => $user->user_id,
            'version' => 1,
        ]);
    }
}
