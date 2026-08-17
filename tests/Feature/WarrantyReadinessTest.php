<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

class WarrantyReadinessTest extends TestCase
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
            'username' => 'warranty-'.Str::lower(Str::random(6)),
            'display_name' => 'Warranty Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    /** Mỗi mutation warranty cần một Idempotency-Key riêng theo contract v1.7. */
    private function idem(): array
    {
        return ['Idempotency-Key' => 'warranty-'.(string) Str::uuid()];
    }

    public function test_identified_warranty_case_resolves_physical_code_and_enforces_versioned_lifecycle(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'PROD-WAR-001',
            'sku_name' => 'San pham bao hanh',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-WAR-001',
            'item_status' => 'ISSUED',
        ]);
        PhysicalCode::create([
            'code_value' => 'PHY-WAR-001',
            'code_type' => 'BARCODE',
            'carrier_type' => 'BARCODE',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $item->item_id,
            'encoded_payload_version' => 1,
            'status' => 'ACTIVE',
        ]);
        $defect = DefectCatalog::create([
            'defect_code' => 'DEF-WAR-001',
            'defect_name' => 'Loi dong co',
            'description' => 'Defect integration test',
            'status' => 'ACTIVE',
        ]);

        $movementBefore = DB::table('stock_movements')->count();
        $create = $this->postJson('/api/v1/warranty-cases', [
            'item_code' => 'PHY-WAR-001',
            'defect_code' => $defect->defect_code,
            'customer_name' => 'Nguyen Van A',
            'customer_phone' => '0901234567',
            'description' => 'May khong khoi dong',
        ], $headers + $this->idem());
        $create->assertCreated()
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.item_id', $item->item_id)
            ->assertJsonPath('data.defect_code', 'DEF-WAR-001')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $caseId = (string) $create->json('data.id');

        $this->assertDatabaseHas('item_instances', [
            'item_id' => $item->item_id,
            'item_status' => 'IN_WARRANTY',
        ]);
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());

        $this->getJson("/api/v1/warranty-cases/{$caseId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $caseId)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.stock_effect', 'NONE');

        // Thiếu Idempotency-Key -> 428 trước mọi hiệu ứng ghi.
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
        ], $headers + ['If-Match' => '1'])->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        // Thiếu If-Match -> 428 (optimistic concurrency bắt buộc).
        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
        ], $headers + $this->idem())->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
            'description' => 'Dang kiem tra',
        ], $headers + $this->idem() + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CHECKING')
            ->assertJsonPath('data.version', 2);

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'REPAIRING',
        ], $headers + $this->idem() + ['If-Match' => '1'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'COMPLETED',
        ], $headers + $this->idem() + ['If-Match' => '2'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_WARRANTY_TRANSITION');

        $version = 2;
        foreach (['REPAIRING', 'COMPLETED', 'RETURNED'] as $target) {
            $response = $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
                'status' => $target,
            ], $headers + $this->idem() + ['If-Match' => (string) $version]);
            $version++;
            $response->assertOk()
                ->assertJsonPath('data.status', $target)
                ->assertJsonPath('data.version', $version)
                ->assertJsonPath('data.stock_effect', 'NONE')
                ->assertJsonPath('data.movement_created', false);
        }

        $this->assertDatabaseHas('item_instances', [
            'item_id' => $item->item_id,
            'item_status' => 'RETURNED_TO_CUSTOMER',
        ]);
        $events = $this->getJson("/api/v1/warranty-cases/{$caseId}/events", $headers);
        $events->assertOk();
        $this->assertGreaterThanOrEqual(5, count($events->json('data') ?? []));
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());
    }

    public function test_temp_only_warranty_requires_evidence_and_is_stock_neutral(): void
    {
        $headers = $this->authHeaders();
        $movementBefore = DB::table('stock_movements')->count();

        $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khach TEMP',
            'customer_phone' => '0911111111',
            'description' => 'Khong doc duoc ma san pham',
        ], $headers + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TEMP_ONLY_EVIDENCE_REQUIRED');

        $create = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khach TEMP',
            'customer_phone' => '0911111111',
            'description' => 'Khong doc duoc ma san pham',
            'missing_code_reason' => 'Tem bi mo va mat ma',
            'manual_product_description' => 'May bom mau xanh, than inox',
        ], $headers + $this->idem());
        $create->assertCreated()
            ->assertJsonPath('data.identification_status', 'TEMP_ONLY')
            ->assertJsonPath('data.item_id', null)
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());
    }

    public function test_unknown_item_code_is_rejected_without_creating_case(): void
    {
        $headers = $this->authHeaders();
        $before = DB::table('warranty_case')->count();

        $this->postJson('/api/v1/warranty-cases', [
            'item_code' => 'DOES-NOT-EXIST',
            'customer_name' => 'Khach Hang',
            'customer_phone' => '0922222222',
            'description' => 'Bao hanh item khong ton tai',
        ], $headers + $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_ITEM_NOT_FOUND');

        $this->assertSame($before, DB::table('warranty_case')->count());
    }
}
