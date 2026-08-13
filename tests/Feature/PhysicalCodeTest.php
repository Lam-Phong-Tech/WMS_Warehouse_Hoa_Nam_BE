<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\PhysicalCode;
use Tests\TestCase;

class PhysicalCodeTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_physical_code_can_be_created_with_valid_chip_carrier(): void
    {
        $headers = $this->authHeaders();

        $response = $this->postJson('/api/v1/physical-codes', [
            'code_value' => 'PC-0001',
            'code_type' => 'ITEM',
            'carrier_type' => 'QR',
        ], $headers);

        $response->assertCreated()->assertJsonPath('data.code_value', 'PC-0001');
        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'physical_code',
            'object_id' => $response->json('data.physical_code_id'),
            'action' => 'CREATE',
        ]);
    }

    public function test_item_code_type_with_non_chip_carrier_is_rejected_on_create(): void
    {
        $headers = $this->authHeaders();

        $response = $this->postJson('/api/v1/physical-codes', [
            'code_value' => 'PC-BAD',
            'code_type' => 'ITEM',
            'carrier_type' => 'MANUAL',
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.details.carrier_type.0', 'Mã vật lý loại ITEM bắt buộc dùng carrier chip (QR/BARCODE/RFID/NFC).');
        $this->assertDatabaseMissing('physical_codes', ['code_value' => 'PC-BAD']);
    }

    public function test_item_code_type_with_non_chip_carrier_is_rejected_on_update_too(): void
    {
        $headers = $this->authHeaders();
        $code = PhysicalCode::create(['code_value' => 'PC-CARTON', 'code_type' => 'CARTON', 'carrier_type' => 'PLAIN_LABEL', 'status' => 'ACTIVE']);

        // Cố tình đổi code_type sang ITEM nhưng vẫn giữ carrier PLAIN_LABEL — phải bị chặn ở update.
        $response = $this->patchJson("/api/v1/physical-codes/{$code->physical_code_id}", ['code_type' => 'ITEM'], $headers);

        $response->assertStatus(422);
        $this->assertSame('CARTON', $code->fresh()->code_type);
    }

    public function test_code_value_and_hardware_uid_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        PhysicalCode::create(['code_value' => 'PC-DUP', 'code_type' => 'BOX', 'carrier_type' => 'BARCODE', 'hardware_uid' => 'HW-1', 'status' => 'ACTIVE']);

        $dupCode = $this->postJson('/api/v1/physical-codes', ['code_value' => 'PC-DUP', 'code_type' => 'BOX', 'carrier_type' => 'BARCODE'], $headers);
        $dupCode->assertStatus(422);

        $dupHw = $this->postJson('/api/v1/physical-codes', ['code_value' => 'PC-OTHER', 'code_type' => 'BOX', 'carrier_type' => 'BARCODE', 'hardware_uid' => 'HW-1'], $headers);
        $dupHw->assertStatus(422);
    }
}
