<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

class SupplierPermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_08_000100_register_supplier_permissions.php');
    }

    public function test_catalog_registers_three_enforced_permissions(): void
    {
        foreach (['supplier.view', 'supplier.create', 'supplier.update'] as $key) {
            $this->assertDatabaseHas('permission', ['permission_key' => $key, 'status' => 'ACTIVE']);
        }
    }

    public function test_migration_copies_role_and_direct_grants_without_resetting_unrelated_permissions(): void
    {
        // Simulate an upgrade from the pre-supplier catalog.
        DB::table('permission')->whereIn('permission_key', ['supplier.view', 'supplier.create', 'supplier.update'])->delete();
        $legacy = Permission::firstOrCreate(['permission_key' => 'catalog.manage'], ['permission_name' => 'Legacy manager', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $read = Permission::firstOrCreate(['permission_key' => 'inbound.view'], ['permission_name' => 'Inbound reader', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $role = Role::create(['role_code' => 'SUPPLIER_LEGACY', 'role_name' => 'Legacy', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $role->permissions()->attach($legacy->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        $reader = Role::create(['role_code' => 'SUPPLIER_READER', 'role_name' => 'Reader', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $reader->permissions()->attach($read->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        $user = User::create(['username' => 'supplier_direct', 'display_name' => 'Direct manager', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $user->grantedPermissions()->attach($legacy->permission_id, ['user_permission_id' => (string) Str::uuid(), 'granted_at' => now()]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => null]);

        $migration = $this->migration();
        $migration->up();

        $keys = $role->permissions()->pluck('permission_key')->all();
        foreach (['catalog.manage', 'supplier.view', 'supplier.create', 'supplier.update'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertEqualsCanonicalizing(['inbound.view', 'supplier.view'], $reader->permissions()->pluck('permission_key')->all());
        $this->assertCount(4, $user->grantedPermissions()->get());
        $this->assertGreaterThan(1, (int) $user->fresh()->version);
        $before = DB::table('role_permission')->count();
        $directBefore = DB::table('user_permission')->count();
        $version = $user->fresh()->version;
        $migration->up();
        $this->assertSame($before, DB::table('role_permission')->count());
        $this->assertSame($directBefore, DB::table('user_permission')->count());
        $this->assertSame($version, $user->fresh()->version);
    }

    public function test_new_permissions_are_available_to_iam_and_can_be_assigned_by_role_and_individually(): void
    {
        $adminRole = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $admin = User::create(['username' => 'supplier_permission_admin', 'display_name' => 'Admin', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $admin->roles()->attach($adminRole->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => null]);
        $token = auth('api')->login($admin);
        $headers = ['Authorization' => 'Bearer '.$token];
        $catalog = $this->getJson('/api/v1/permissions?keyword=supplier.&per_page=100', $headers)->assertOk();
        $this->assertCount(3, $catalog->json('data'));
        $ids = Permission::whereIn('permission_key', ['supplier.view', 'supplier.create', 'supplier.update'])->pluck('permission_id')->all();
        $role = Role::create(['role_code' => 'SUPPLIER_NEW_ROLE', 'role_name' => 'New supplier manager', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $this->putJson('/api/v1/roles/'.$role->role_id.'/permissions', ['permission_ids' => $ids, 'reason' => 'Grant supplier workflow access'], $headers)->assertOk();
        $this->assertCount(3, $role->permissions()->get());
        $target = User::create(['username' => 'supplier_grant_target', 'display_name' => 'Target', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $this->postJson('/api/v1/users/'.$target->user_id.'/permissions', ['permission_id' => $ids[0], 'reason' => 'Grant supplier access individually'], $headers)->assertCreated();
        $this->assertDatabaseHas('user_permission', ['user_id' => $target->user_id, 'permission_id' => $ids[0]]);
    }

    public function test_migration_preserves_a_customized_inactive_definition(): void
    {
        $permission = Permission::where('permission_key', 'supplier.view')->firstOrFail();
        $permission->update(['permission_name' => 'Custom supplier lookup policy', 'status' => 'INACTIVE']);
        $before = $permission->fresh()->getAttributes();
        $this->migration()->up();
        $this->assertSame($before, $permission->fresh()->getAttributes());
        $this->assertSame(1, Permission::where('permission_key', 'supplier.view')->count());
    }
}
