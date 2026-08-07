<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class ItemInstanceTest extends TestCase
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

    private function serialTrackedSku(): Sku
    {
        return Sku::create([
            'sku_code' => 'SKU-SERIAL-'.Str::random(6), 'sku_name' => 'May co serial', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => true, 'status' => 'ACTIVE',
        ]);
    }

    private function nonSerialSku(): Sku
    {
        return Sku::create([
            'sku_code' => 'SKU-NOSERIAL-'.Str::random(6), 'sku_name' => 'Linh kien', 'sku_type' => 'COMPONENT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);
    }

    private function chipCode(string $value): PhysicalCode
    {
        return PhysicalCode::create(['code_value' => $value, 'code_type' => 'ITEM', 'carrier_type' => 'QR', 'status' => 'ACTIVE']);
    }

    public function test_serial_tracked_product_requires_physical_code_id_on_create(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->serialTrackedSku();

        $withoutCode = $this->postJson('/api/v1/item-instances', ['sku_id' => $sku->sku_id], $headers);
        $withoutCode->assertStatus(422);
        $this->assertDatabaseMissing('item_instances', ['sku_id' => $sku->sku_id]);

        $code = $this->chipCode('IT-0001');
        $withCode = $this->postJson('/api/v1/item-instances', ['sku_id' => $sku->sku_id, 'physical_code_id' => $code->physical_code_id], $headers);
        $withCode->assertCreated()->assertJsonPath('data.item_status', 'NEW');
    }

    public function test_non_serial_component_does_not_require_physical_code_id(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->nonSerialSku();

        $response = $this->postJson('/api/v1/item-instances', ['sku_id' => $sku->sku_id], $headers);

        $response->assertCreated();
    }

    public function test_physical_code_cannot_be_assigned_to_two_items_via_create_or_update(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->serialTrackedSku();
        $code = $this->chipCode('IT-SHARED');

        $first = $this->postJson('/api/v1/item-instances', ['sku_id' => $sku->sku_id, 'physical_code_id' => $code->physical_code_id], $headers);
        $first->assertCreated();

        // Bypass qua create thứ 2 dùng lại physical_code_id đã gán.
        $second = $this->postJson('/api/v1/item-instances', ['sku_id' => $sku->sku_id, 'physical_code_id' => $code->physical_code_id], $headers);
        $second->assertStatus(422);

        // Bypass qua update: tạo item khác không có code, rồi PATCH gán code đã bị chiếm.
        $other = ItemInstance::create(['sku_id' => $sku->sku_id, 'item_status' => 'NEW', 'item_condition' => 'NORMAL', 'version' => 1]);
        $viaUpdate = $this->patchJson("/api/v1/item-instances/{$other->item_id}", ['physical_code_id' => $code->physical_code_id, 'version' => 1], $headers);
        $viaUpdate->assertStatus(422);
    }

    public function test_update_with_matching_version_succeeds_and_bumps_version(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->nonSerialSku();
        $item = ItemInstance::create(['sku_id' => $sku->sku_id, 'item_status' => 'NEW', 'item_condition' => 'NORMAL', 'version' => 1]);

        $response = $this->patchJson("/api/v1/item-instances/{$item->item_id}", ['item_status' => 'IN_STOCK', 'version' => 1], $headers);

        $response->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.item_status', 'IN_STOCK');
    }

    public function test_update_with_stale_version_returns_409_and_does_not_partially_write(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->nonSerialSku();
        $item = ItemInstance::create(['sku_id' => $sku->sku_id, 'item_status' => 'NEW', 'item_condition' => 'NORMAL', 'version' => 5]);

        $response = $this->patchJson("/api/v1/item-instances/{$item->item_id}", [
            'item_status' => 'ISSUED', 'item_condition' => 'DEFECTIVE', 'version' => 1,
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        $fresh = $item->fresh();
        $this->assertSame('NEW', $fresh->item_status);
        $this->assertSame('NORMAL', $fresh->item_condition);
        $this->assertSame(5, $fresh->version);
    }

    public function test_trace_endpoint_returns_full_chain(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->serialTrackedSku();
        $code = $this->chipCode('IT-TRACE');
        $importBatch = ImportBatch::create(['import_batch_code' => 'IB-TRACE', 'status' => 'ACTIVE']);
        $tray = Container::create(['container_code' => 'TRAY-01', 'container_type' => 'TRAY', 'physical_code_id' => $this->chipCode('CT-TRAY')->physical_code_id, 'status' => 'ACTIVE', 'version' => 1]);
        $box = Container::create([
            'container_code' => 'BOX-01', 'container_type' => 'BOX',
            'physical_code_id' => $this->chipCode('CT-BOX')->physical_code_id,
            'primary_sku_id' => $sku->sku_id, 'parent_container_id' => $tray->container_id,
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id, 'physical_code_id' => $code->physical_code_id,
            'container_id' => $box->container_id, 'import_batch_id' => $importBatch->import_batch_id,
            'item_status' => 'IN_STOCK', 'item_condition' => 'NORMAL', 'version' => 1,
        ]);

        $response = $this->getJson("/api/v1/item-instances/{$item->item_id}/trace", $headers);

        $response->assertOk()
            ->assertJsonPath('data.sku.sku_id', $sku->sku_id)
            ->assertJsonPath('data.physical_code.code_value', 'IT-TRACE')
            ->assertJsonPath('data.container.container_code', 'BOX-01')
            ->assertJsonPath('data.parent_container.container_code', 'TRAY-01')
            ->assertJsonPath('data.import_batch.import_batch_code', 'IB-TRACE');
    }

    public function test_trace_endpoint_includes_stock_movements_for_the_same_sku(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->nonSerialSku();
        $item = ItemInstance::create(['sku_id' => $sku->sku_id, 'item_status' => 'IN_STOCK', 'item_condition' => 'NORMAL', 'version' => 1]);

        $warehouse = Warehouse::create(['code' => 'WH-TRACE', 'name' => 'Kho trace', 'status' => 'ACTIVE']);
        $creator = User::first();

        $movement = StockMovement::create([
            'movement_no' => 'SM-TRACE-0001',
            'business_key' => 'SM-TRACE-KEY-0001',
            'movement_type' => StockMovement::RECEIPT_IN,
            'warehouse_id' => $warehouse->id,
            'product_id' => $sku->sku_id,
            'qty_before' => 0,
            'qty_delta' => 10,
            'qty_after' => 10,
            'created_by' => $creator->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/item-instances/{$item->item_id}/trace", $headers);

        $response->assertOk()
            ->assertJsonCount(1, 'data.stock_movements')
            ->assertJsonPath('data.stock_movements.0.id', $movement->id)
            ->assertJsonPath('data.stock_movements.0.movement_type', StockMovement::RECEIPT_IN);
    }

    public function test_item_instance_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->nonSerialSku();

        $create = $this->postJson('/api/v1/item-instances', ['sku_id' => $sku->sku_id], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'item_instance',
            'object_id' => $create->json('data.item_id'),
            'action' => 'CREATE',
        ]);
    }
}
