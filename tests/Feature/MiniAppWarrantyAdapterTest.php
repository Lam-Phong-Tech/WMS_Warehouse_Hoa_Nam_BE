<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

class MiniAppWarrantyAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_mini_app_warranty_resolves_code_creates_case_and_transitions_status(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'PROD-MINI-WAR-001',
            'sku_name' => 'Mini App warranty product',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-MINI-WAR-001',
            'item_status' => 'ISSUED',
        ]);

        $this->postJson('/api/v1/mini-app/warranty/resolve-code', [
            'raw_code' => 'SER-MINI-WAR-001',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.eligible_for_warranty', true)
            ->assertJsonPath('data.eligibility_code', 'ELIGIBLE')
            ->assertJsonPath('data.recommended_flow', 'IDENTIFIED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.wms_case_created', false);

        $create = $this->postJson('/api/v1/mini-app/warranty-cases', [
            'item_code' => 'SER-MINI-WAR-001',
            'customer_name' => 'Khach Mini Warranty',
            'customer_phone' => '0901234567',
            'customer_address' => '12 Test Street',
            'description' => 'May khong len nguon',
            'reported_defect' => 'Bam nut nguon khong chay',
            'accessories_received' => 'Sac va hop',
            'received_condition' => 'May tray nhe',
        ], $headers + ['Idempotency-Key' => 'mini-war-create-001']);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.identification_status', 'IDENTIFIED')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $caseId = (string) $create->json('data.id');

        $this->getJson("/api/v1/mini-app/warranty-cases/{$caseId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $caseId)
            ->assertJsonPath('data.version', 1);

        $this->postJson("/api/v1/mini-app/warranty-cases/{$caseId}/status", [
            'status' => 'CHECKING',
            'note' => 'Mini App bat dau kiem tra',
        ], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'mini-war-status-001',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CHECKING')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->getJson('/api/v1/mini-app/warranty-cases?status=CHECKING', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.status', 'CHECKING');

        $events = $this->getJson("/api/v1/mini-app/warranty-cases/{$caseId}/events", $headers)
            ->assertOk();
        $this->assertGreaterThanOrEqual(2, count($events->json('data')));
    }

    public function test_mini_app_warranty_resolve_recommends_temp_only_when_item_is_not_found(): void
    {
        $this->postJson('/api/v1/mini-app/warranty/resolve-code', [
            'raw_code' => 'UNKNOWN-MINI-WAR',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.eligible_for_warranty', false)
            ->assertJsonPath('data.eligibility_code', 'ITEM_NOT_FOUND')
            ->assertJsonPath('data.recommended_flow', 'TEMP_ONLY')
            ->assertJsonPath('data.stock_effect', 'NONE');
    }

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'mini-war-'.Str::lower(Str::random(6)),
            'display_name' => 'Mini Warranty Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }
}
