<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

class FoundationInboundPutawayReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'readiness-'.Str::lower(Str::random(6)),
            'display_name' => 'Readiness Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_products_write_routes_are_reachable_on_canonical_products_contract(): void
    {
        $headers = $this->authHeaders();
        $create = $this->postJson('/api/v1/products', [
            'code' => 'COMP-ROUTE-001',
            'name' => 'Linh kien test route',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
        ], $headers);
        $create->assertCreated()->assertJsonPath('data.sku_code', 'COMP-ROUTE-001');

        $id = $create->json('data.sku_id');
        $this->patchJson("/api/v1/products/{$id}", [
            'name' => 'Linh kien da cap nhat',
            'status' => 'INACTIVE',
        ], $headers)->assertOk()
            ->assertJsonPath('data.sku_name', 'Linh kien da cap nhat')
            ->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_putaway_confirm_is_stock_neutral_and_updates_box_location(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'COMP-PUT-001',
            'sku_name' => 'Component putaway',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-PUT-001',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-PUT-001',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $movementBefore = DB::table('stock_movements')->count();
        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
            'location_hint' => 'A-01-01',
            'note' => 'PTW contract test',
        ], $headers);
        $create->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $eventId = $create->json('data.id');
        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.box_status', 'STORED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
            'location_hint' => 'A-01-01',
        ]);
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());

        $this->getJson('/api/v1/putaway/history', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.box_code', 'BOX-PUT-001')
            ->assertJsonPath('data.0.new_tray_code', 'TRAY-PUT-001')
            ->assertJsonPath('data.0.stock_effect', 'NONE');
    }
}
