<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\WarrantyCase;
use Tests\Feature\Concerns\PreparesWarrantyOwnerFixtures;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class Wwhn219WarrantyTempOnlyAuditTest extends TestCase
{
    use PreparesWarrantyOwnerFixtures;
    use RefreshDatabase;

    public function test_temp_only_writes_audit_log_with_before_after_snapshot(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $case = WarrantyCase::query()->create([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => 'WARRANTY-AUDIT-TEMP-ONLY',
            'inventory_serial_id' => null,
            'product_id' => null,
            // chk_erd_warranty_identification_shape: TEMP_ONLY yêu cầu đủ bộ
            // temporary_item_code + missing_code_reason + manual_product_description.
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-AUDIT-SEED',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy mô tả thủ công (fixture)',
            // ERD bảng 45: customer_name/customer_phone NOT NULL (SCR-WAR-01 bắt buộc).
            'customer_name' => 'Khách audit test',
            'customer_phone' => '0900000004',
            'status' => WarrantyCase::STATUS_RECEIVED,
            'created_by' => $user->user_id,
            'version' => 1,
        ]);

        $before = AuditLog::query()->where('action', 'WARRANTY_TEMP_ONLY')->count();
        $this->bindWarrantyOwnerFixture($case->warranty_case_id, $user->user_id, $this->ensureActiveWarehouse('KHO-TONG-HN')->id);

        $this->postJson(
            "/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only",
            [
                'temporary_item_code' => 'TEMP-AUDIT-0001',
                'missing_code_reason' => 'Mat tem ma khi tiep nhan',
                // Annex D SCR-WAR-01: bắt buộc khi TEMP_ONLY.
                'manual_product_description' => 'May mo ta thu cong (audit)',
                'condition_status' => 'WAITING_CHECK',
            ],
            [
                'Authorization' => "Bearer {$token}",
                'Idempotency-Key' => 'warranty-temp-only-audit-0001',
            ]
        )->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'WARRANTY_TEMP_ONLY')->count());

        $log = AuditLog::query()
            ->where('action', 'WARRANTY_TEMP_ONLY')
            ->where('object_id', $case->warranty_case_id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('warranty_case', $log->object_type);
        $this->assertSame((string) $user->user_id, $log->actor_id);
        $this->assertSame(WarrantyCase::STATUS_RECEIVED, $log->before_data['status']);
        // SRS v1.12 §6: TEMP_ONLY là identification_status; case status giữ nguyên.
        $this->assertSame(WarrantyCase::STATUS_RECEIVED, $log->after_data['status']);
        $this->assertSame('TEMP_ONLY', $log->after_data['identification_status']);
        $this->assertSame('TEMP-AUDIT-0001', $log->after_data['temporary_item_code']);
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
            'username' => 'warranty-audit-temp-only-admin',
            'display_name' => 'Warranty Audit Temp Only Admin',
            'email' => 'warranty-audit-temp-only-admin@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }
}
