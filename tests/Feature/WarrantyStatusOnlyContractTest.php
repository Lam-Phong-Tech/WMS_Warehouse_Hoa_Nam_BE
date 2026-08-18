<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'manual_product_description' => 'Máy mô tả thủ công (contract)',
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
            // SRS v1.12 §6: TEMP_ONLY là identification_status; case status giữ RECEIVED.
            ->assertJsonPath('data.status', WarrantyCase::STATUS_RECEIVED)
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
            'status' => WarrantyCase::STATUS_RECEIVED,
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
            'manual_product_description' => 'Máy mô tả thủ công (contract)',
            'condition_status' => 'WAITING_CHECK',
        ], $headers)->assertOk();

        $this->postJson("/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only", [
            'temporary_item_code' => 'TEMP-B',
            'missing_code_reason' => 'Không đọc được tem',
            'manual_product_description' => 'Máy mô tả thủ công (contract)',
            'condition_status' => 'WAITING_CHECK',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

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
            'manual_product_description' => 'Máy mô tả thủ công (contract)',
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
        if (DB::getDriverName() !== 'pgsql') {
            // Partial unique ux_erd_warranty_one_active_case_item nằm trên
            // warranty_cases(item_id) của nhánh ERD PostgreSQL, nơi trigger legacy
            // ánh xạ inventory_serial_id → item_id. Nhánh sqlite không có ánh xạ đó
            // nên index tương ứng không bao được case tạo qua inventory_serial_id.
            $this->markTestSkipped('One-active-case guard chỉ được materialize trên PostgreSQL.');
        }

        [$user, $token] = $this->createSuperAdmin();
        [$warehouse, $product] = $this->createInventoryFixture();
        $serial = InventorySerial::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_no' => 'SERIAL-ACTIVE-WARRANTY-001',
            'status' => InventorySerial::STATUS_ISSUED,
        ]);
        $case = $this->createWarrantyCase($user, $serial->id, $product->id, 'WARRANTY-ACTIVE-001');

        // AC-SRS-016 (one active case) được enforce từ tầng DB bằng partial unique
        // ux_erd_warranty_one_active_case_item — case active thứ hai cho cùng item
        // không thể tồn tại, kể cả khi bypass API.
        try {
            DB::transaction(function () use ($user, $serial, $product): void {
                $this->createWarrantyCase($user, $serial->id, $product->id, 'WARRANTY-ACTIVE-002');
            });
            $this->fail('Case active thứ hai cho cùng item phải bị one-active-case guard từ chối.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('ux_erd_warranty_one_active_case_item', $exception->getMessage());
        }

        $this->assertSame(0, WarrantyEvent::query()->where('warranty_case_id', $case->warranty_case_id)->count());
        $this->assertDatabaseMissing('warranty_case', ['warranty_case_code' => 'WARRANTY-ACTIVE-002']);
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
            // chk_erd_warranty_identification_shape: TEMP_ONLY yêu cầu đủ bộ temp
            // fields; IDENTIFIED yêu cầu item_id thật.
            'identification_status' => $serialId === null ? 'TEMP_ONLY' : 'IDENTIFIED',
            'temporary_item_code' => $serialId === null ? 'TEMP-'.Str::upper(Str::random(8)) : null,
            'missing_code_reason' => $serialId === null ? 'Mất tem mã' : null,
            'manual_product_description' => $serialId === null ? 'Máy mô tả thủ công (fixture)' : null,
            // ERD bảng 45: customer_name/customer_phone NOT NULL (SCR-WAR-01 bắt buộc).
            'customer_name' => 'Khách temp-only test',
            'customer_phone' => '0900000002',
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
