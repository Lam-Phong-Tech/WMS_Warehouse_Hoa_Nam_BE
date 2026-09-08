<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;

class ErdRuntimeMirrorParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_iam_runtime_writes_are_projected_with_full_canonical_data(): void
    {
        $this->requirePostgres();

        $userId = (string) Str::uuid();
        $roleId = (string) Str::uuid();
        $permissionId = (string) Str::uuid();
        $now = now();

        DB::table('app_user')->insert([
            'user_id' => $userId,
            'username' => 'erd-runtime-user',
            'display_name' => 'ERD Runtime User',
            'email' => 'erd-runtime@example.test',
            'phone' => null,
            'password_hash' => 'test-only-hash',
            'status' => 'ACTIVE',
            'version' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role')->insert([
            'role_id' => $roleId,
            'role_code' => 'ERD_RUNTIME_ROLE',
            'role_name' => 'ERD Runtime Role',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
            'version' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('permission')->insert([
            'permission_id' => $permissionId,
            'permission_key' => 'erd.runtime.verify',
            'permission_name' => 'Verify ERD runtime mirror',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
            'version' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_role')->insert([
            'user_role_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_ref' => null,
            'effective_from' => null,
            'effective_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_permission')->insert([
            'role_permission_id' => (string) Str::uuid(),
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = DB::table('app_users')->where('id', $userId)->first();
        $this->assertNotNull($user);
        $this->assertSame('test-only-hash', $user->password_hash);
        $this->assertSame(3, (int) $user->version);

        $role = DB::table('roles')->where('id', $roleId)->first();
        $this->assertNotNull($role);
        $this->assertSame('ERD_RUNTIME_ROLE', $role->role_code);
        $this->assertSame(4, (int) $role->version);

        $permission = DB::table('permissions')->where('id', $permissionId)->first();
        $this->assertNotNull($permission);
        $this->assertSame('Verify ERD runtime mirror', $permission->description);
        $this->assertSame(5, (int) $permission->version);

        $this->assertTrue(DB::table('user_roles')->where(['user_id' => $userId, 'role_id' => $roleId])->exists());
        $this->assertTrue(DB::table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists());
    }

    public function test_warranty_runtime_write_populates_required_erd_fields(): void
    {
        $this->requirePostgres();

        $id = (string) Str::uuid();
        $now = now();

        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $id,
            'warranty_case_code' => 'WC-ERD-RUNTIME-001',
            'inventory_serial_id' => null,
            'product_id' => null,
            'item_id' => null,
            'temporary_item_code' => 'TEMP-ERD-RUNTIME-001',
            'identification_status' => 'TEMP_ONLY',
            'missing_code_reason' => 'MISSING_PHYSICAL_CODE',
            'manual_product_description' => 'Thiết bị test chưa nhận diện',
            'defect_id' => null,
            'customer_name' => 'Khach Hang Test',
            'customer_phone' => '0900000000',
            'customer_address' => 'Ha Noi',
            'purchase_date' => now()->subDay()->toDateString(),
            'sales_trace_status' => 'NOT_TRACEABLE',
            'status' => 'RECEIVED',
            'condition_status' => 'DEFECTIVE',
            'description' => 'Không khởi động được',
            'created_by' => null,
            'version' => 1,
            'closed_at' => null,
            'retention_until' => null,
            'legal_hold_flag' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $canonical = DB::table('warranty_cases')->where('id', $id)->first();
        $this->assertNotNull($canonical);
        $this->assertSame('WC-ERD-RUNTIME-001', $canonical->case_no);
        $this->assertSame('TEMP_ONLY', $canonical->identification_status);
        $this->assertSame('NOT_TRACEABLE', $canonical->sales_trace_status);
        $this->assertSame('Không khởi động được', $canonical->reported_defect);
        $this->assertSame('Ha Noi', $canonical->customer_address);
        $this->assertSame('RECEIVED', $canonical->status);
        $this->assertNotNull($canonical->received_at);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Runtime/canonical parity is certified on the PostgreSQL reference database.');
        }
    }
}
