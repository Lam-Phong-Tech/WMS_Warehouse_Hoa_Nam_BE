<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\WarrantyCase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class Wwhn219WarrantyTempOnlyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_temp_only_writes_audit_log_with_before_after_snapshot(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $case = WarrantyCase::query()->create([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => 'WARRANTY-AUDIT-TEMP-ONLY',
            'inventory_serial_id' => null,
            'product_id' => null,
            'identification_status' => 'UNIDENTIFIED',
            'status' => WarrantyCase::STATUS_RECEIVED,
            'created_by' => $user->user_id,
            'version' => 1,
        ]);

        $before = AuditLog::query()->where('action', 'WARRANTY_TEMP_ONLY')->count();

        $this->postJson(
            "/api/v1/warranty-cases/{$case->warranty_case_id}/temp-only",
            [
                'temporary_item_code' => 'TEMP-AUDIT-0001',
                'missing_code_reason' => 'Mat tem ma khi tiep nhan',
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
        $this->assertSame('TEMP_ONLY', $log->after_data['status']);
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
