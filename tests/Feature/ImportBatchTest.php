<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportBatch;
use Tests\TestCase;

class ImportBatchTest extends TestCase
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

    public function test_import_batch_can_be_created_listed_and_updated(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/import-batches', [
            'import_batch_code' => 'IB-2026-08',
            'supplier_name' => 'Bosch Vietnam',
        ], $headers);

        $create->assertCreated()->assertJsonPath('data.import_batch_code', 'IB-2026-08');
        $id = $create->json('data.import_batch_id');

        $this->getJson('/api/v1/import-batches', $headers)->assertOk();

        $update = $this->patchJson("/api/v1/import-batches/{$id}", ['supplier_name' => 'Bosch VN Co.'], $headers);
        $update->assertOk()->assertJsonPath('data.supplier_name', 'Bosch VN Co.');
    }

    public function test_import_batch_code_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        ImportBatch::create(['import_batch_code' => 'IB-DUP', 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/import-batches', ['import_batch_code' => 'IB-DUP'], $headers);

        $response->assertStatus(422);
    }

    public function test_import_batch_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/import-batches', ['import_batch_code' => 'IB-AUDIT'], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'import_batch',
            'object_id' => $create->json('data.import_batch_id'),
            'action' => 'CREATE',
        ]);
    }
}
