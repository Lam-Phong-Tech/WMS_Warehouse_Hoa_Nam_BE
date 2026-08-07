<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthRbacAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_access_token_is_rejected_with_standard_401(): void
    {
        $user = $this->createUser('expired_access');
        $factory = JWTAuth::factory();
        $originalTtl = $factory->getTTL();

        try {
            $factory->setTTL(1);
            $expiredToken = JWTAuth::fromUser($user);
            Carbon::setTestNow(now()->addMinutes(2));

            $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$expiredToken}"])
                ->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'UNAUTHENTICATED')
                ->assertJsonStructure(['meta' => ['request_id', 'timestamp']]);
        } finally {
            Carbon::setTestNow();
            $factory->setTTL($originalTtl);
        }
    }

    public function test_refresh_replay_revokes_token_family_and_creates_failure_audit(): void
    {
        $user = $this->createUser('refresh_replay');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $oldRefreshToken = $login->json('data.refresh_token');
        $firstRotate = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefreshToken,
        ])->assertOk();
        $newRefreshToken = $firstRotate->json('data.refresh_token');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefreshToken])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $newRefreshToken])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertFalse(RefreshToken::where('user_id', $user->user_id)->active()->exists());
        $this->assertDatabaseHas('audit_log', [
            'action' => 'TOKEN_REFRESH_FAILED',
            'object_type' => 'refresh_token',
        ]);

        $serializedAudit = AuditLog::where('action', 'TOKEN_REFRESH_FAILED')->get()->toJson();
        $this->assertStringNotContainsString($oldRefreshToken, $serializedAudit);
        $this->assertStringNotContainsString($newRefreshToken, $serializedAudit);
    }

    public function test_warehouse_scope_filters_list_and_denies_detail_and_mutation_outside_scope(): void
    {
        $warehouseA = Warehouse::create(['code' => 'KHO-A', 'name' => 'Kho A', 'status' => 'ACTIVE']);
        $warehouseB = Warehouse::create(['code' => 'KHO-B', 'name' => 'Kho B', 'status' => 'ACTIVE']);
        $role = $this->createRole('KHO_A_MANAGER', ['warehouse.view', 'warehouse.manage']);
        $user = $this->createUser('scope_a', $role, ['scope_ref' => $warehouseA->getKey()]);
        $token = JWTAuth::fromUser($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/warehouses', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $warehouseA->getKey());

        $this->getJson("/api/v1/warehouses/{$warehouseA->getKey()}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $warehouseA->getKey());

        $this->getJson("/api/v1/warehouses/{$warehouseB->getKey()}", $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->patchJson("/api/v1/warehouses/{$warehouseB->getKey()}", ['name' => 'Không được sửa'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->patchJson("/api/v1/warehouses/{$warehouseA->getKey()}", ['name' => 'Kho A đã cập nhật'], $headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Kho A đã cập nhật');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouseB->getKey(), 'name' => 'Kho B']);
    }

    public function test_audit_logger_redacts_nested_secrets(): void
    {
        AuditLogger::record(
            'SECURITY_TEST',
            'app_user',
            null,
            null,
            (string) Str::uuid(),
            afterData: [
                'email' => 'user@example.com',
                'password' => 'plain-secret',
                'nested' => [
                    'access_token' => 'jwt-secret',
                    'profile' => ['api_key' => 'api-secret'],
                ],
            ],
        );

        $audit = AuditLog::where('action', 'SECURITY_TEST')->firstOrFail();
        $this->assertSame('[REDACTED]', $audit->after_data['password']);
        $this->assertSame('[REDACTED]', $audit->after_data['nested']['access_token']);
        $this->assertSame('[REDACTED]', $audit->after_data['nested']['profile']['api_key']);
        $this->assertStringNotContainsString('plain-secret', $audit->toJson());
        $this->assertStringNotContainsString('jwt-secret', $audit->toJson());
    }

    public function test_audit_primary_failure_is_persisted_to_outbox_instead_of_silently_lost(): void
    {
        Schema::rename('audit_log', 'audit_log_unavailable');

        try {
            AuditLogger::record(
                'OUTBOX_TEST', 'warehouse', (string) Str::uuid(), null,
                (string) Str::uuid(), afterData: ['password' => 'must-not-leak'],
            );

            $this->assertDatabaseHas('audit_outbox', ['attempts' => 0]);
            $outbox = DB::table('audit_outbox')->first();
            $this->assertNotNull($outbox);
            $this->assertStringContainsString('OUTBOX_TEST', $outbox->payload);
            $this->assertStringContainsString('[REDACTED]', $outbox->payload);
            $this->assertStringNotContainsString('must-not-leak', $outbox->payload);
        } finally {
            Schema::rename('audit_log_unavailable', 'audit_log');
        }
    }

    public function test_negative_api_contract_for_malformed_json_and_missing_refresh_token(): void
    {
        $this->call(
            'POST', '/api/v1/auth/login', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"email":"broken@example.com",'
        )->assertStatus(400)
            ->assertJsonPath('error.code', 'MALFORMED_JSON');

        $this->postJson('/api/v1/auth/refresh', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['error' => ['code', 'details'], 'meta' => ['request_id', 'timestamp']]);
    }

    private function createRole(string $code, array $permissions): Role
    {
        $role = Role::create([
            'role_code' => $code,
            'role_name' => $code,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE'],
            );
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $role;
    }

    private function createUser(string $username, ?Role $role = null, array $pivot = []): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        if ($role !== null) {
            $user->roles()->attach($role->role_id, array_merge([
                'user_role_id' => (string) Str::uuid(),
            ], $pivot));
        }

        return $user;
    }
}
