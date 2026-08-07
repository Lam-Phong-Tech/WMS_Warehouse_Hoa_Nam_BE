<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WarrantyStatusOnlyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_temp_only_is_status_only_and_replay_does_not_duplicate_event(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        [$warehouse, $product, $balance] = $this->createInventoryFixture();
        $case = $this->createWarrantyCase($user, null, null, 'WARRANTY-TEMP-001');
        $payload = [
            'temporary_item_code' => 'TEMP-UNKNOWN-001',
            'missing_code_reason' => 'Mất tem serial khi khách bàn giao',
            'condition_status' => 'WAITING_CHECK',
            'note' => 'Tiếp nhận kiểm tra ngoại quan',
        ];
        $headers = $this->headers($token, 'warranty-temp-only-001');
        $movementCountBefore = StockMovement::query()->count();
        $balanceBefore = $balance->fresh()->only(['on_hand_qty', 'available_qty', 'reserved_qty', 'version']);

        $first = $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.status', WarrantyCase::STATUS_TEMP_ONLY)
            ->assertJsonPath('data.identification_status', 'TEMP_ONLY');

        $firstBody = $first->json();

        $second = $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", $payload, $headers)
            ->assertOk();

        $this->assertSame($firstBody, $second->json());
        $this->assertSame($movementCountBefore, StockMovement::query()->count());
        $this->assertSame($balanceBefore, $balance->fresh()->only(['on_hand_qty', 'available_qty', 'reserved_qty', 'version']));
        $this->assertSame(1, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $case->warranty_case_id,
            'inventory_serial_id' => null,
            'product_id' => null,
            'identification_status' => 'TEMP_ONLY',
            'status' => WarrantyCase::STATUS_TEMP_ONLY,
            'version' => 2,
        ]);
        $this->assertSame($warehouse->id, $balance->fresh()->warehouse_id);
        $this->assertSame($product->id, $balance->fresh()->product_id);
    }

    public function test_same_key_with_different_payload_returns_idempotency_conflict(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $case = $this->createWarrantyCase($user, null, null, 'WARRANTY-TEMP-002');
        $headers = $this->headers($token, 'warranty-temp-only-002');

        $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", [
            'temporary_item_code' => 'TEMP-A',
            'missing_code_reason' => 'Không đọc được tem',
            'condition_status' => 'WAITING_CHECK',
        ], $headers)->assertOk();

        $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", [
            'temporary_item_code' => 'TEMP-B',
            'missing_code_reason' => 'Không đọc được tem',
            'condition_status' => 'WAITING_CHECK',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_ERROR');

        $this->assertSame(1, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
    }

    public function test_in_stock_serial_is_rejected_without_stock_or_case_effect(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        [$warehouse, $product, $balance] = $this->createInventoryFixture();
        $serial = InventorySerial::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_no' => 'SERIAL-IN-STOCK-001',
            'status' => InventorySerial::STATUS_IN_STOCK,
        ]);
        $case = $this->createWarrantyCase($user, $serial->id, $product->id, 'WARRANTY-IN-STOCK-001');
        $movementCountBefore = StockMovement::query()->count();
        $balanceBefore = $balance->fresh()->only(['on_hand_qty', 'available_qty', 'reserved_qty', 'version']);

        $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", [
            'temporary_item_code' => 'TEMP-SHOULD-NOT-APPLY',
            'missing_code_reason' => 'Yêu cầu sai luồng',
            'condition_status' => 'WAITING_CHECK',
        ], $this->headers($token, 'warranty-in-stock-001'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_ITEM_NOT_ELIGIBLE')
            ->assertJsonPath('error.details.serial_status', InventorySerial::STATUS_IN_STOCK)
            ->assertJsonPath('error.details.required_action', 'POST_ISSUE');

        $this->assertSame($movementCountBefore, StockMovement::query()->count());
        $this->assertSame($balanceBefore, $balance->fresh()->only(['on_hand_qty', 'available_qty', 'reserved_qty', 'version']));
        $this->assertSame(0, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $case->warranty_case_id,
            'inventory_serial_id' => $serial->id,
            'product_id' => $product->id,
            'status' => WarrantyCase::STATUS_RECEIVED,
            'version' => 1,
        ]);
    }

    public function test_serial_with_another_active_warranty_case_is_rejected(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        [$warehouse, $product] = $this->createInventoryFixture();
        $serial = InventorySerial::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_no' => 'SERIAL-ACTIVE-WARRANTY-001',
            'status' => InventorySerial::STATUS_ISSUED,
        ]);
        $case = $this->createWarrantyCase($user, $serial->id, $product->id, 'WARRANTY-ACTIVE-001');
        $this->createWarrantyCase($user, $serial->id, $product->id, 'WARRANTY-ACTIVE-002');

        $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", [
            'temporary_item_code' => 'TEMP-DUPLICATE',
            'missing_code_reason' => 'Kiểm thử trùng active warranty',
            'condition_status' => 'WAITING_CHECK',
        ], $this->headers($token, 'warranty-active-duplicate-001'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACTIVE_WARRANTY_EXISTS');

        $this->assertSame(0, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
    }

    private function createSuperAdmin(): array
    {
        $role = Role::query()->create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::query()->create([
            'username' => 'warranty-contract-admin',
            'display_name' => 'Warranty Contract Admin',
            'email' => 'warranty-contract-admin@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createInventoryFixture(): array
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'KHO-WAR-'.strtoupper(Str::random(6)),
            'name' => 'Kho kiểm thử bảo hành',
            'status' => 'ACTIVE',
        ]);
        $product = Product::query()->create([
            'code' => 'SP-WAR-'.strtoupper(Str::random(6)),
            'name' => 'Sản phẩm kiểm thử bảo hành',
            'status' => 'ACTIVE',
        ]);
        $balance = InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => 5,
            'available_qty' => 5,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        return [$warehouse, $product, $balance];
    }

    private function createWarrantyCase(
        User $user,
        ?string $serialId,
        ?string $productId,
        string $code
    ): WarrantyCase {
        return WarrantyCase::query()->create([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => $code,
            'inventory_serial_id' => $serialId,
            'product_id' => $productId,
            'identification_status' => $serialId === null ? 'UNIDENTIFIED' : 'IDENTIFIED',
            'status' => WarrantyCase::STATUS_RECEIVED,
            'created_by' => $user->user_id,
            'version' => 1,
        ]);
    }

    private function headers(string $token, string $idempotencyKey): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => $idempotencyKey,
        ];
    }
}
