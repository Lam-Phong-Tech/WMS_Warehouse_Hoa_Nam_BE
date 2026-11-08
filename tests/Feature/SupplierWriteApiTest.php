<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class SupplierWriteApiTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const URL = '/api/v1/mini-app/inbound/suppliers';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_create_assigns_codes_and_returns_directory_fields(): void
    {
        $headers = $this->actor();
        $first = $this->postJson(self::URL, ['name' => '  Honda  '], $headers)->assertCreated()
            ->assertJsonPath('data.code', 'NCC-001')->assertJsonPath('data.name', 'Honda')
            ->assertJsonPath('data.display_name', 'NCC-001 - Honda')->assertJsonPath('data.code_status', 'ASSIGNED')
            ->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.version', 1)
            ->assertJsonPath('meta.contract', 'mini-app-p04-v1')->assertHeader('ETag', '"1"');
        $this->assertTrue(Str::isUuid($first->json('data.id')));
        $this->postJson(self::URL, ['name' => 'Yanmar', 'status' => 'INACTIVE'], $headers)->assertCreated()
            ->assertJsonPath('data.code', 'NCC-002')->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson(self::URL.'?q=NCC-001', $headers)->assertOk()->assertJsonPath('data.0.id', $first->json('data.id'));
        $this->assertDatabaseCount('inbound_suppliers', 2);
    }

    public function test_codes_continue_after_highest_numeric_inactive_code_and_expand_past_999(): void
    {
        $headers = $this->actor();
        $this->supplier(['code' => 'NCC-999', 'status' => 'INACTIVE']);
        $this->supplier(['code' => 'NCC-010']);
        $this->supplier(['code' => 'CUSTOM-9000']);
        $this->postJson(self::URL, ['name' => 'Next'], $headers)->assertCreated()->assertJsonPath('data.code', 'NCC-1000');
        $this->postJson(self::URL, ['name' => 'Next again'], $headers)->assertCreated()->assertJsonPath('data.code', 'NCC-1001');
    }

    public function test_create_rejects_invalid_and_caller_controlled_fields_without_writes(): void
    {
        $headers = $this->actor();
        foreach ([[], ['name' => ' '], ['name' => str_repeat('a', 201)], ['name' => null], ['name' => ['nested']],
            ['name' => 'A', 'status' => 'UNKNOWN'], ['name' => 'A', 'status' => null]] as $payload) {
            $this->postJson(self::URL, $payload, $headers)->assertUnprocessable();
        }
        foreach (['id', 'code', 'version', 'warehouse_id', 'display_name', 'actor_id'] as $field) {
            $this->postJson(self::URL, ['name' => 'A', $field => 'override'], $headers)->assertUnprocessable();
        }
        $this->postJson(self::URL.'?name=override', ['name' => 'A'], $headers)->assertUnprocessable();
        $this->assertDatabaseCount('inbound_suppliers', 0);
    }

    public function test_update_preserves_identity_and_legacy_fields_and_rejects_stale_versions(): void
    {
        $headers = $this->actor();
        $id = $this->supplier();
        $before = (array) DB::table('inbound_suppliers')->where('id', $id)->first();
        $this->patchJson(self::URL.'/'.$id, ['name' => '  New name  ', 'status' => 'INACTIVE'], $headers + ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.code', 'NCC-001')
            ->assertJsonPath('data.name', 'New name')->assertJsonPath('data.status', 'INACTIVE')
            ->assertJsonPath('data.version', 2)->assertHeader('ETag', '"2"');
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $id, 'code' => $before['code'],
            'warehouse_id' => $before['warehouse_id'], 'created_at' => $before['created_at'], 'version' => 2]);
        $after = (array) DB::table('inbound_suppliers')->where('id', $id)->first();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Stale'], $headers + ['If-Match' => '"1"'])
            ->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertSame($after, (array) DB::table('inbound_suppliers')->where('id', $id)->first());
        $this->patchJson(self::URL.'/'.$id, ['status' => 'ACTIVE'], $headers + ['If-Match' => '"2"'])
            ->assertOk()->assertJsonPath('data.name', 'New name')->assertJsonPath('data.version', 3);
    }

    public function test_update_requires_valid_precondition_and_payload(): void
    {
        $headers = $this->actor();
        $id = $this->supplier();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'New'], $headers)->assertStatus(428);
        foreach (['*', 'W/"1"', '"1", "2"', 'garbage', '0', '999999999999999999999999999'] as $version) {
            $this->patchJson(self::URL.'/'.$id, ['name' => 'New'], $headers + ['If-Match' => $version])->assertUnprocessable();
        }
        foreach ([[], ['name' => ' '], ['status' => null], ['code' => 'NCC-002'], ['id' => (string) Str::uuid()],
            ['version' => 2], ['warehouse_id' => (string) Str::uuid()]] as $payload) {
            $this->patchJson(self::URL.'/'.$id, $payload, $headers + ['If-Match' => '"1"'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $id, 'name' => 'Original', 'version' => 1, 'code' => 'NCC-001']);
    }

    public function test_missing_supplier_and_non_uuid_are_not_found(): void
    {
        $headers = $this->actor() + ['If-Match' => '"1"'];
        foreach ([(string) Str::uuid(), 'invalid'] as $id) {
            $this->patchJson(self::URL.'/'.$id, ['name' => 'New'], $headers)->assertNotFound();
        }
    }

    public function test_mutations_require_authentication_and_supplier_action_grants(): void
    {
        $this->postJson(self::URL, ['name' => 'A'])->assertUnauthorized();
        $this->patchJson(self::URL.'/'.Str::uuid(), ['name' => 'A'])->assertUnauthorized();
        $headers = $this->actor(false);
        $id = $this->supplier();
        $this->postJson(self::URL, ['name' => 'A'], $headers)->assertForbidden();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'A'], $headers + ['If-Match' => '"1"'])->assertForbidden();
        $this->assertDatabaseCount('inbound_suppliers', 1);
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $id, 'version' => 1]);
    }

    public function test_missing_canonical_warehouse_and_namespace_overflow_do_not_create_suppliers(): void
    {
        $headers = $this->actor();
        $this->supplier(['code' => 'NCC-99999999999999999999999999']);
        $this->postJson(self::URL, ['name' => 'A'], $headers)->assertStatus(409)
            ->assertJsonPath('error.code', 'SUPPLIER_CODE_NAMESPACE_EXHAUSTED');
        DB::table('warehouses')->where('code', 'KHO-TONG-HN')->update(['code' => 'OTHER-WAREHOUSE']);
        $this->postJson(self::URL, ['name' => 'A'], $headers)->assertStatus(409)
            ->assertJsonPath('error.code', 'WAREHOUSE_NOT_AVAILABLE');
        $this->assertDatabaseCount('inbound_suppliers', 1);
    }

    public function test_revoked_session_and_inactive_actor_cannot_write(): void
    {
        $headers = $this->actor();
        $id = $this->supplier();
        $userId = DB::table('app_user')->where('email', 'like', 'writer_%@example.test')->value('user_id');
        DB::table('refresh_tokens')->where('user_id', $userId)->update(['revoked_at' => now()]);
        $this->postJson(self::URL, ['name' => 'Forbidden'], $headers)->assertUnauthorized();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Forbidden'], $headers + ['If-Match' => '"1"'])->assertUnauthorized();
        $this->assertDatabaseCount('inbound_suppliers', 1);
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $id, 'version' => 1, 'name' => 'Original']);
    }

    public function test_inactive_actor_cannot_create_or_edit(): void
    {
        $headers = $this->actor();
        $id = $this->supplier();
        DB::table('app_user')->where('email', 'like', 'writer_%@example.test')->update(['status' => 'INACTIVE']);
        $this->postJson(self::URL, ['name' => 'Forbidden'], $headers)->assertForbidden();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Forbidden'], $headers + ['If-Match' => '"1"'])->assertForbidden();
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $id, 'version' => 1, 'name' => 'Original']);
    }

    private function supplier(array $overrides = []): string
    {
        $row = array_replace(['id' => (string) Str::uuid(), 'warehouse_id' => DB::table('warehouses')->where('code', 'KHO-TONG-HN')->value('id'),
            'code' => 'NCC-001', 'name' => 'Original', 'status' => 'ACTIVE', 'version' => 1,
            'created_at' => '2026-10-01 01:02:03', 'updated_at' => '2026-10-01 01:02:03'], $overrides);
        DB::table('inbound_suppliers')->insert($row);

        return $row['id'];
    }

    public function test_supplier_create_and_update_are_separate_grants(): void
    {
        $headers = $this->actor(false, ['supplier.create']);
        $created = $this->postJson(self::URL, ['name' => 'Creator only'], $headers)->assertCreated();
        $this->patchJson(self::URL.'/'.$created->json('data.id'), ['name' => 'Forbidden'], $headers + ['If-Match' => '"1"'])->assertForbidden();
        $headers = $this->actor(false, ['supplier.update']);
        $this->postJson(self::URL, ['name' => 'Forbidden'], $headers)->assertForbidden();
        $this->patchJson(self::URL.'/'.$created->json('data.id'), ['name' => 'Updater only'], $headers + ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('data.name', 'Updater only');
    }

    public function test_supplier_view_reads_without_inbound_view_and_catalog_manage_no_longer_bypasses_supplier_writes(): void
    {
        $headers = $this->actor(false, ['supplier.view']);
        $this->getJson(self::URL, $headers)->assertOk();
        $this->postJson(self::URL, ['name' => 'Denied'], $headers)->assertForbidden();
        $headers = $this->actor(false, ['catalog.manage']);
        $this->postJson(self::URL, ['name' => 'Denied'], $headers)->assertForbidden();
    }

    private function actor(bool $canManage = true, ?array $keys = null): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'supplier_write_'.$suffix, 'display_name' => 'Supplier writer',
            'email' => 'writer_'.$suffix.'@example.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'SUPPLIER_WRITE_'.$suffix, 'role_name' => 'Supplier writer', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) $warehouse->id]);
        foreach ($keys ?? ($canManage ? ['supplier.view', 'supplier.create', 'supplier.update'] : ['inbound.view']) as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $token = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');

        return ['Authorization' => 'Bearer '.$token];
    }
}
