<?php

namespace Tests\Feature;

use App\Support\ExplicitReadFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\P18BoxReadModel;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class P18BoxReadModelTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_small_component_box_needs_no_individual_item_or_physical_codes_and_get_is_stock_neutral(): void
    {
        [, $token, $warehouse] = $this->actor();
        $sku = Sku::create(['sku_code' => 'SMALL-SCREW', 'sku_name' => 'Small component', 'sku_type' => 'COMPONENT',
            'is_serial_tracked' => false, 'status' => 'ACTIVE', 'unit' => 'cai']);
        $box = Container::create(['container_code' => 'BOX-SMALL-SCREW-001', 'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id, 'status' => 'IN_STOCK', 'location_hint' => null, 'version' => 1]);
        DB::table('container_items')->insert(['container_item_id' => (string) Str::uuid(), 'container_id' => $box->container_id,
            'sku_id' => $sku->sku_id, 'item_id' => null, 'quantity' => 120, 'status' => 'ACTIVE', 'is_active' => true,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $second = Container::create(['container_code' => 'BOX-SMALL-SCREW-002', 'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id, 'status' => 'IN_STOCK', 'version' => 1]);
        $before = DB::table('container_items')->first();
        $response = $this->get('/api/v1/containers?'.http_build_query(['warehouse_id' => $warehouse->id, 'sku_type' => 'COMPONENT', 'per_page' => 1]), $this->headers($token))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertJsonPath('meta.contract', P18BoxReadModel::CONTRACT)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.model', 'SINGLE_WAREHOUSE_WITH_BOXES')
            ->assertJsonPath('data.0.container_id', $box->container_id)->assertJsonPath('data.0.contained_qty', 120)
            ->assertJsonPath('data.0.classification', 'COMPONENT_QUANTITY')->assertJsonPath('data.0.individual_physical_code_required', false)
            ->assertJsonPath('data.0.available_qty', null)->assertJsonPath('data.0.reserved_qty', null)->assertJsonPath('data.0.capacity', null);
        $this->assertStringContainsString('Authorization', $response->headers->get('Vary'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/api/v1/containers/'.$box->container_id.'?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertOk()->assertJsonPath('data.sku.code', $sku->sku_code);
        $this->get('/api/v1/containers?'.http_build_query(['warehouse_id' => $warehouse->id, 'sku_type' => 'COMPONENT', 'per_page' => 1, 'page' => 2]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.container_id', $second->container_id)
            ->assertJsonPath('data.0.contained_qty', null);
        $this->assertEquals($before, DB::table('container_items')->first());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('physical_codes', 0);
        $this->assertDatabaseCount('item_instances', 0);
    }

    public function test_empty_unknown_and_product_are_not_reclassified_or_given_invented_capacity(): void
    {
        [, $token, $warehouse] = $this->actor();
        $sku = Sku::create(['sku_code' => 'PRODUCT-A', 'sku_name' => 'Actual product', 'sku_type' => 'PRODUCT',
            'is_serial_tracked' => true, 'status' => 'ACTIVE', 'unit' => 'cai']);
        $box = Container::create(['container_code' => 'BOX-PRODUCT-A', 'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id, 'status' => 'EMPTY', 'version' => 1]);
        $this->get('/api/v1/containers/'.$box->container_id.'?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertOk()->assertJsonPath('data.classification', 'PRODUCT')->assertJsonPath('data.contained_qty', null)
            ->assertJsonPath('data.quantity_status', 'NOT_RECORDED')->assertJsonPath('data.capacity', null);
        $this->get('/api/v1/containers?warehouse_id='.$warehouse->id.'&sku_type=COMPONENT', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->get('/api/v1/containers?warehouse_id='.$warehouse->id.'&bin=FAKE', $this->headers($token))->assertStatus(422);
        $this->assertDatabaseHas('sku', ['sku_id' => $sku->sku_id, 'sku_type' => 'PRODUCT']);
    }

    public function test_scope_auth_and_permission_revocation_are_enforced_before_reading_global_register(): void
    {
        [$user, $token, $warehouse, $role] = $this->actor();
        $url = '/api/v1/containers?warehouse_id='.$warehouse->id;
        $this->get($url, $this->headers(''))->assertUnauthorized();
        $this->get('/api/v1/containers', $this->headers($token))->assertStatus(422);
        $this->get('/api/v1/containers?warehouse_id='.Str::uuid(), $this->headers($token))->assertForbidden();
        DB::table('role_permission')->where('role_id', $role->role_id)->delete();
        // Canonical IAM revocation bumps the user version after commit. The
        // RefreshDatabase outer transaction does not run that callback here.
        $user->increment('version');
        $this->get($url, $this->headers($token))->assertForbidden();
    }

    public function test_second_inactive_warehouse_does_not_justify_global_container_ownership(): void
    {
        [, $token, $warehouse] = $this->actor();
        Warehouse::create(['code' => 'HISTORICAL-OTHER', 'name' => 'Not automatically the active warehouse', 'status' => 'INACTIVE']);
        $this->get('/api/v1/containers?warehouse_id='.$warehouse->id, $this->headers($token))->assertStatus(503)
            ->assertJsonPath('error.code', 'SINGLE_WAREHOUSE_SCOPE_UNAVAILABLE');
    }

    public function test_container_read_permission_alone_does_not_grant_master_projection(): void
    {
        [$user, $token, $warehouse, $role] = $this->actor();
        DB::table('role_permission')->where('role_id', $role->role_id)
            ->where('permission_id', Permission::where('permission_key', 'sku.view')->value('permission_id'))->delete();
        $user->increment('version');
        $this->get('/api/v1/containers?warehouse_id='.$warehouse->id, $this->headers($token))->assertForbidden();
    }

    public function test_explicit_field_representation_explains_missing_box_data_without_null_or_stock_changes(): void
    {
        [, $token, $warehouse] = $this->actor();
        $sku = Sku::create(['sku_code' => 'NO-COUNT-YET', 'sku_name' => 'Actual component', 'sku_type' => 'COMPONENT',
            'is_serial_tracked' => false, 'status' => 'ACTIVE', 'unit' => 'cai']);
        $box = Container::create(['container_code' => 'BOX-NO-COUNT-YET-001', 'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id, 'status' => 'IN_STOCK', 'version' => 1]);
        $url = '/api/v1/containers/'.$box->container_id.'?warehouse_id='.$warehouse->id;
        $response = $this->get($url, $this->headers($token) + [ExplicitReadFields::HEADER => ExplicitReadFields::CONTRACT])
            ->assertOk()->assertJsonPath('data.container_id', $box->container_id)->assertJsonMissingPath('data.contained_qty')
            ->assertJsonMissingPath('data.available_qty')->assertJsonMissingPath('data.capacity');
        $missing = collect($response->json('meta.read_fields.missing_fields'))->keyBy('path');
        $this->assertSame('NOT_RECORDED', $missing['/data/contained_qty']['status']);
        $this->assertSame('NOT_CONFIGURED', $missing['/data/capacity']['status']);
        $this->assertSame('NO_AUTHORITATIVE_CONTAINER_AVAILABILITY_MODEL', $missing['/data/available_qty']['status']);
        $assert = function (mixed $value) use (&$assert): void {
            $this->assertNotNull($value);
            if (is_array($value)) {
                foreach ($value as $child) {
                    $assert($child);
                }
            }
        };
        $assert($response->json());
        $this->get($url, $this->headers($token))->assertOk()->assertJsonPath('data.contained_qty', null);
        $this->assertDatabaseCount('container_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_missing_source_is_not_an_empty_success(): void
    {
        [, $token, $warehouse] = $this->actor();
        Schema::rename('container_items', 'isolated_unavailable_container_items');
        try {
            $this->get('/api/v1/containers?warehouse_id='.$warehouse->id, $this->headers($token))->assertStatus(503)
                ->assertJsonPath('error.code', 'CONTAINER_SOURCE_UNAVAILABLE');
        } finally {
            Schema::rename('isolated_unavailable_container_items', 'container_items');
        }
    }

    private function actor(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $user = User::create(['username' => 'isolated_boxes', 'email' => 'boxes@wms-hoanam.test', 'display_name' => 'Box reader',
            'password_hash' => Hash::make('fixture-test-only'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'BOX_READ', 'role_name' => 'Box read fixture', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['container.view', 'sku.view'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $token = $this->browserLogin($user->email, 'fixture-test-only')->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($token))->assertOk()->json('data.access_token');

        return [$user, $token, $warehouse, $role];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', P18BoxReadModel::HEADER => P18BoxReadModel::CONTRACT];
    }
}
