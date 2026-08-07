<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\DefectCatalog;
use Tests\TestCase;

class DefectCatalogTest extends TestCase
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

    public function test_defect_catalog_can_be_created_listed_and_updated(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => 'LOI-0001',
            'defect_name' => 'Chập cháy động cơ',
        ], $headers);

        $create->assertCreated()->assertJsonPath('data.defect_code', 'LOI-0001');
        $id = $create->json('data.defect_id');

        $list = $this->getJson('/api/v1/defect-catalogs?keyword=Chập', $headers);
        $list->assertOk();

        $update = $this->patchJson("/api/v1/defect-catalogs/{$id}", ['status' => 'INACTIVE'], $headers);
        $update->assertOk()->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_defect_code_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        DefectCatalog::create(['defect_code' => 'LOI-DUP', 'defect_name' => 'Lỗi gốc', 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => 'LOI-DUP',
            'defect_name' => 'Lỗi trùng code',
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR');
    }

    public function test_defect_catalog_delete_succeeds_when_not_referenced(): void
    {
        $headers = $this->authHeaders();
        $defect = DefectCatalog::create(['defect_code' => 'LOI-DEL', 'defect_name' => 'Lỗi xoá được', 'status' => 'ACTIVE']);

        $response = $this->deleteJson("/api/v1/defect-catalogs/{$defect->defect_id}", [], $headers);

        $response->assertOk();
        $this->assertNull(DefectCatalog::find($defect->defect_id));
    }

    public function test_defect_catalog_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => 'LOI-AUDIT',
            'defect_name' => 'Lỗi có audit',
        ], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'defect_catalog',
            'object_id' => $create->json('data.defect_id'),
            'action' => 'CREATE',
        ]);
    }
}
