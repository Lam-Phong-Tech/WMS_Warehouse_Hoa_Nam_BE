<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

class ContainerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(?int $ifMatch = null): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);
        $headers = ['Authorization' => "Bearer {$token}"];
        if ($ifMatch !== null) {
            $headers['If-Match'] = (string) $ifMatch;
        }

        return $headers;
    }

    private function withIfMatch(array $headers, int $version): array
    {
        return [...$headers, 'If-Match' => (string) $version];
    }

    private function sku(): Sku
    {
        return Sku::create([
            'sku_code' => 'SKU-'.Str::random(8),
            'sku_name' => 'SKU test',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
        ]);
    }

    private function code(string $value, string $type = 'BOX'): PhysicalCode
    {
        return PhysicalCode::create([
            'code_value' => $value,
            'code_type' => $type,
            'carrier_type' => 'BARCODE',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_carton_and_box_require_primary_sku_id_but_tray_does_not(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->sku();

        $boxWithoutSku = $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-NOSKU',
            'container_type' => 'BOX',
            'physical_code_id' => $this->code('CT-1')->physical_code_id,
        ], $headers);
        $boxWithoutSku->assertStatus(422);
        $this->assertDatabaseMissing('containers', ['container_code' => 'BOX-NOSKU']);

        $boxWithSku = $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-OK',
            'container_type' => 'BOX',
            'physical_code_id' => $this->code('CT-2')->physical_code_id,
            'primary_sku_id' => $sku->sku_id,
        ], $headers);
        $boxWithSku->assertCreated()->assertJsonPath('data.status', 'EMPTY');

        $tray = $this->postJson('/api/v1/containers', [
            'container_code' => 'TRAY-OK',
            'container_type' => 'TRAY',
            'physical_code_id' => $this->code('CT-3', 'TRAY')->physical_code_id,
        ], $headers);
        $tray->assertCreated()->assertJsonPath('data.status', 'EMPTY');
    }

    public function test_physical_code_must_be_active_to_assign_to_container(): void
    {
        $headers = $this->authHeaders();
        $lostCode = PhysicalCode::create([
            'code_value' => 'CT-LOST',
            'code_type' => 'BOX',
            'carrier_type' => 'BARCODE',
            'status' => 'LOST',
        ]);

        $response = $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-LOST',
            'container_type' => 'TRAY',
            'physical_code_id' => $lostCode->physical_code_id,
        ], $headers);

        $response->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_ACTIVE');
    }

    public function test_container_update_uses_if_match_and_stale_version_returns_412(): void
    {
        $headers = $this->authHeaders();
        $container = Container::create([
            'container_code' => 'TRAY-VER',
            'container_type' => 'TRAY',
            'physical_code_id' => $this->code('CT-VER', 'TRAY')->physical_code_id,
            'status' => 'EMPTY',
            'version' => 3,
        ]);

        $stale = $this->patchJson(
            "/api/v1/containers/{$container->container_id}",
            ['zone_code' => 'A1'],
            $this->withIfMatch($headers, 1)
        );
        $stale->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertNull($container->fresh()->zone_code);

        $ok = $this->patchJson(
            "/api/v1/containers/{$container->container_id}",
            ['zone_code' => 'A1'],
            $this->withIfMatch($headers, 3)
        );
        $ok->assertOk()->assertJsonPath('data.container.version', 4)->assertJsonPath('data.container.zone_code', 'A1');
    }

    public function test_container_item_xor_rule_rejects_both_and_neither(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->sku();
        $container = Container::create([
            'container_code' => 'TRAY-XOR',
            'container_type' => 'TRAY',
            'physical_code_id' => $this->code('CT-XOR', 'TRAY')->physical_code_id,
            'status' => 'EMPTY',
            'version' => 1,
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
        ]);

        $neither = $this->postJson(
            "/api/v1/containers/{$container->container_id}/items",
            [],
            $this->withIfMatch($headers, 1)
        );
        $neither->assertStatus(422)->assertJsonPath('error.code', 'CONTAINER_ITEM_XOR');

        $both = $this->postJson(
            "/api/v1/containers/{$container->container_id}/items",
            ['item_id' => $item->item_id, 'sku_id' => $sku->sku_id, 'quantity' => 5],
            $this->withIfMatch($headers, 1)
        );
        $both->assertStatus(422)->assertJsonPath('error.code', 'CONTAINER_ITEM_XOR');

        $skuOnlyMissingQty = $this->postJson(
            "/api/v1/containers/{$container->container_id}/items",
            ['sku_id' => $sku->sku_id],
            $this->withIfMatch($headers, 1)
        );
        $skuOnlyMissingQty->assertStatus(422)->assertJsonPath('error.code', 'QUANTITY_REQUIRED');

        $validItem = $this->postJson(
            "/api/v1/containers/{$container->container_id}/items",
            ['item_id' => $item->item_id],
            $this->withIfMatch($headers, 1)
        );
        $validItem->assertCreated();

        $validSku = $this->postJson(
            "/api/v1/containers/{$container->container_id}/items",
            ['sku_id' => $sku->sku_id, 'quantity' => 3],
            $this->withIfMatch($headers, 2)
        );
        $validSku->assertCreated();
    }

    public function test_remove_item_marks_removed_instead_of_deleting_row(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->sku();
        $container = Container::create([
            'container_code' => 'TRAY-RM',
            'container_type' => 'TRAY',
            'physical_code_id' => $this->code('CT-RM', 'TRAY')->physical_code_id,
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $add = $this->postJson(
            "/api/v1/containers/{$container->container_id}/items",
            ['sku_id' => $sku->sku_id, 'quantity' => 2],
            $this->withIfMatch($headers, 1)
        );
        $add->assertCreated();
        $containerItemId = $add->json('data.container_item_id');

        $remove = $this->deleteJson(
            "/api/v1/containers/{$container->container_id}/items/{$containerItemId}",
            ['reason' => 'Test remove mapping'],
            $this->withIfMatch($headers, 2)
        );

        $remove->assertOk()->assertJsonPath('data.status', 'REMOVED')->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('container_items', [
            'container_item_id' => $containerItemId,
            'status' => 'REMOVED',
            'is_active' => false,
        ]);
    }

    public function test_container_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/containers', [
            'container_code' => 'TRAY-AUDIT',
            'container_type' => 'TRAY',
            'physical_code_id' => $this->code('CT-AUDIT', 'TRAY')->physical_code_id,
        ], $headers);
        $create->assertCreated();

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'container',
            'object_id' => $create->json('data.container_id'),
            'action' => 'CREATE_CONTAINER',
        ]);
    }
}
