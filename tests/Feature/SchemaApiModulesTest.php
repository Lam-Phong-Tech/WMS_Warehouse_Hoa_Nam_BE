<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class SchemaApiModulesTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        $role = Role::create(['role_code' => 'SCHEMA_API', 'role_name' => 'Schema API', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'schema-'.Str::random(8),
            'display_name' => 'Schema API user',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        foreach (['inventory.view', 'report.view', 'warranty.manage', 'warranty.temp_only'] as $key) {
            $permission = Permission::create(['permission_key' => $key, 'permission_name' => $key, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id]);
        }

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function warrantyCase(string $status = 'RECEIVED', ?string $serialId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('warranty_case')->insert([
            'warranty_case_id' => $id,
            'warranty_case_code' => 'WRN-'.Str::upper(Str::random(10)),
            'status' => $status,
            'inventory_serial_id' => $serialId,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_report_uses_active_sku_schema_and_returns_warranty_status_counts(): void
    {
        $headers = $this->headers();
        $this->warrantyCase();

        $this->getJson('/api/v1/reports/inventory-xnt?from_date=2026-08-01&to_date=2026-08-14', $headers)
            ->assertOk()
            ->assertJsonPath('data.lines', []);

        $this->getJson('/api/v1/reports/dashboard-quick', $headers)
            ->assertOk()
            ->assertJsonPath('data.warranty_cases_by_status.RECEIVED', 1);
    }

    public function test_warranty_api_uses_singular_schema_and_records_temp_only_idempotently(): void
    {
        $headers = $this->headers();
        $warehouse = Warehouse::create(['code' => 'WH-WRN-'.Str::random(6), 'name' => 'Warranty warehouse', 'status' => 'ACTIVE']);
        $sku = Sku::create(['sku_code' => 'SKU-WRN-'.Str::random(8), 'sku_name' => 'Warranty SKU', 'unit' => 'pcs', 'status' => 'ACTIVE']);
        $serialId = (string) Str::uuid();
        DB::table('inventory_serials')->insert([
            'id' => $serialId,
            'product_id' => $sku->sku_id,
            'warehouse_id' => $warehouse->id,
            'serial_no' => 'SER-WRN-'.Str::random(10),
            'status' => 'ISSUED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $defectId = (string) Str::uuid();
        DB::table('defect_catalogs')->insert([
            'defect_id' => $defectId,
            'defect_code' => 'DF-WRN-'.Str::random(8),
            'defect_name' => 'Warranty defect',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $caseId = $this->warrantyCase(serialId: $serialId);

        // WarrantyController::tempOnly() chuyển phiếu sang chế độ "không xác định
        // được item" — chủ động NULL inventory_serial_id/product_id, thay bằng
        // temporary_item_code/missing_code_reason. Không phải API gán item_id.
        $payload = [
            'temporary_item_code' => 'TEMP-WRN-001',
            'missing_code_reason' => 'Khách không còn tem/mã sản phẩm gốc.',
            'defect_id' => $defectId,
            'condition_status' => 'WAITING_CHECK',
            'note' => 'Test temporary receipt',
        ];
        $requestHeaders = $headers + ['Idempotency-Key' => 'warranty-temp-001'];

        $this->getJson('/api/v1/warranty-cases', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.warranty_case_id', $caseId);

        $this->postJson("/api/v1/warranty-cases/{$caseId}/temp-only", $payload, $requestHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', 'TEMP_ONLY')
            ->assertJsonPath('data.temporary_item_code', 'TEMP-WRN-001');
        $this->postJson("/api/v1/warranty-cases/{$caseId}/temp-only", $payload, $requestHeaders)->assertOk();

        $this->assertDatabaseHas('warranty_case', [
            'warranty_case_id' => $caseId,
            'inventory_serial_id' => null,
            'temporary_item_code' => 'TEMP-WRN-001',
            'status' => 'TEMP_ONLY',
        ]);
        $this->assertDatabaseCount('warranty_event', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('audit_log', ['action' => 'WARRANTY_TEMP_ONLY', 'object_type' => 'warranty_case', 'object_id' => $caseId]);
        $this->getJson("/api/v1/warranty-cases/{$caseId}/events", $headers)
            ->assertOk()
            ->assertJsonPath('data.0.warranty_case_id', $caseId);
    }

    public function test_pv_profile_schema_accepts_only_one_profile_per_sku(): void
    {
        $sku = Sku::create(['sku_code' => 'SKU-PV-'.Str::random(8), 'sku_name' => 'PV SKU', 'unit' => 'pcs', 'status' => 'ACTIVE']);
        DB::table('pv_product_profile')->insert([
            'profile_id' => (string) Str::uuid(),
            'product_id' => $sku->sku_id,
            'display_name' => 'PV Profile',
            'published' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('pv_product_profile')->insert([
            'profile_id' => (string) Str::uuid(),
            'product_id' => $sku->sku_id,
            'display_name' => 'Duplicate PV Profile',
            'published' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_schema_integrity_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $migrationPath = base_path('Modules/Warehouse/database/migrations/2026_08_14_000001_add_catalog_report_warranty_pv_integrity_indexes.php');
        $migration = require $migrationPath;

        $migration->down();
        $migration->up();

        $sku = Sku::create(['sku_code' => 'SKU-PV-RB-'.Str::random(8), 'sku_name' => 'PV rollback SKU', 'unit' => 'pcs', 'status' => 'ACTIVE']);
        DB::table('pv_product_profile')->insert([
            'profile_id' => (string) Str::uuid(),
            'product_id' => $sku->sku_id,
            'display_name' => 'PV profile after rollback',
            'published' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('pv_product_profile')->insert([
            'profile_id' => (string) Str::uuid(),
            'product_id' => $sku->sku_id,
            'display_name' => 'Duplicate after rollback',
            'published' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
