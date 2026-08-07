<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Agency;
use Tests\TestCase;

class AgencyTest extends TestCase
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

    public function test_agency_can_be_created_listed_and_updated(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-0001',
            'agency_name' => 'Đại lý Hoà Nam Q1',
        ], $headers);

        $create->assertCreated()->assertJsonPath('data.agency_code', 'DL-0001');
        $id = $create->json('data.agency_id');

        $list = $this->getJson('/api/v1/agencies?keyword=Hoa+Nam', $headers);
        $list->assertOk();

        $update = $this->patchJson("/api/v1/agencies/{$id}", ['agency_name' => 'Đại lý Hoà Nam Q3'], $headers);
        $update->assertOk()->assertJsonPath('data.agency_name', 'Đại lý Hoà Nam Q3');
    }

    public function test_agency_code_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        Agency::create(['agency_code' => 'DL-DUP', 'agency_name' => 'Đại lý gốc', 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-DUP',
            'agency_name' => 'Đại lý trùng code',
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    public function test_agency_detail_for_unknown_id_returns_404(): void
    {
        $headers = $this->authHeaders();

        $response = $this->getJson('/api/v1/agencies/'.Str::uuid(), $headers);

        $response->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_agency_delete_succeeds_when_not_referenced(): void
    {
        $headers = $this->authHeaders();
        $agency = Agency::create(['agency_code' => 'DL-DEL', 'agency_name' => 'Đại lý xoá được', 'status' => 'ACTIVE']);

        $response = $this->deleteJson("/api/v1/agencies/{$agency->agency_id}", [], $headers);

        $response->assertOk();
        $this->assertNull(Agency::find($agency->agency_id));
    }

    public function test_agency_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-AUDIT',
            'agency_name' => 'Đại lý có audit',
        ], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'agency',
            'object_id' => $create->json('data.agency_id'),
            'action' => 'CREATE',
        ]);
    }
}
