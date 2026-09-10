<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\AuditLog\Services\AuditObjectReferenceResolver;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Nhật ký hệ thống phải trả MÃ NGHIỆP VỤ của đối tượng (`object_code`) để giao diện
 * không phải hiện UUID `object_id`.
 *
 * Phản ánh 10/09/2026: "Chi tiết nhật ký hệ thống — trường Đối tượng Phiếu nhập/xuất
 * kho hiển thị chuỗi 48a72bc5-5577-4681-b5f9-f4258e37b747". Nguyên nhân: nhiều nơi ghi
 * nhật ký (vd StockDocumentService::post) chỉ ghi trạng thái vào after_data, không có
 * số phiếu, và API trả nguyên object_id.
 */
class AuditLogObjectReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_list_returns_business_code_even_when_after_data_has_none(): void
    {
        $this->seedRoleWithPermissions('AUDIT_REF_VIEWER', ['audit_log.view', 'audit_log.view_all']);
        $auditor = $this->createUser('auditor.ref', 'AUDIT_REF_VIEWER');
        $target = $this->createUser('target.ref', null);

        $documentId = $this->insertStockDocument('PN-AUDIT-REF-0001', $auditor->user_id);
        $missingDocumentId = (string) Str::uuid();
        $exportId = (string) Str::uuid();

        // Đúng hình dạng StockDocumentService::post(): after_data chỉ có trạng thái.
        AuditLogger::record('POST', 'stock_document', $documentId, $auditor->user_id, afterData: ['status' => 'POSTED']);
        // Tên bảng số nhiều vẫn phải quy về đúng nguồn.
        AuditLogger::record('UPDATE', 'users', $target->user_id, $auditor->user_id);
        // Loại đối tượng không có mã nghiệp vụ.
        AuditLogger::record('CREATE', 'report_export', $exportId, $auditor->user_id);
        // Phiếu gốc không còn.
        AuditLogger::record('CANCEL', 'stock_document', $missingDocumentId, $auditor->user_id);
        // object_id không phải UUID không được làm hỏng truy vấn của cả trang.
        AuditLogger::record('UPDATE', 'stock_document', 'not-a-uuid', $auditor->user_id);

        $response = $this->getJson('/api/v1/audit-logs?per_page=50', [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($auditor),
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $byObject = collect($response->json('data'))->keyBy('object_id');

        $this->assertSame('PN-AUDIT-REF-0001', $byObject[$documentId]['object_code']);
        $this->assertSame('target.ref@wms-hoanam.test', $byObject[$target->user_id]['object_code']);
        foreach ([$exportId, $missingDocumentId, 'not-a-uuid'] as $objectId) {
            $this->assertArrayHasKey('object_code', $byObject[$objectId]);
            $this->assertNull($byObject[$objectId]['object_code'], "object_code của {$objectId} phải là null");
        }
    }

    public function test_code_lookup_runs_one_query_per_source_table_not_per_row(): void
    {
        $actor = $this->createUser('actor.batch', null);
        for ($index = 1; $index <= 5; $index++) {
            $documentId = $this->insertStockDocument(sprintf('PX-AUDIT-BATCH-%04d', $index), $actor->user_id);
            AuditLogger::record('POST', 'stock_document', $documentId, $actor->user_id);
        }

        $logs = AuditLog::query()->where('object_type', 'stock_document')->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AuditObjectReferenceResolver::class)->attach($logs);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Tra mã phải gom cả trang vào MỘT truy vấn cho mỗi bảng nguồn.');
        $this->assertSame(
            ['PX-AUDIT-BATCH-0001', 'PX-AUDIT-BATCH-0002', 'PX-AUDIT-BATCH-0003', 'PX-AUDIT-BATCH-0004', 'PX-AUDIT-BATCH-0005'],
            $logs->pluck('object_code')->sort()->values()->all(),
        );
    }

    public function test_object_type_normalization_matches_real_audit_values(): void
    {
        $this->assertSame('user', AuditObjectReferenceResolver::normalizeType('users'));
        $this->assertSame('warehouse', AuditObjectReferenceResolver::normalizeType('warehouses'));
        $this->assertSame('agency', AuditObjectReferenceResolver::normalizeType('agencies'));
        $this->assertSame('stock_document', AuditObjectReferenceResolver::normalizeType('stock_document'));
        $this->assertSame('label_print_event', AuditObjectReferenceResolver::normalizeType('label-print-events'));
    }

    private function insertStockDocument(string $docNo, string $actorId): string
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-AUDIT-REF', 'Kho audit ref');
        $documentId = (string) Str::uuid();
        $now = now();

        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'receipt',
            'doc_no' => $docNo,
            'doc_date' => $now->toDateString(),
            'status' => 'POSTED',
            'dst_warehouse_id' => $warehouse->id,
            'posted_by' => $actorId,
            'posted_at' => $now,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
            ...(Schema::hasColumn('stock_documents', 'version') ? ['version' => 1] : []),
        ]);

        return $documentId;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function seedRoleWithPermissions(string $roleCode, array $permissionKeys): Role
    {
        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            ['role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );

            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                [
                    'role_permission_id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $role;
    }

    private function createUser(string $username, ?string $roleCode): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'phone' => '0901234567',
            'password_hash' => Hash::make(Str::random(24)),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        if ($roleCode !== null) {
            $role = Role::where('role_code', $roleCode)->firstOrFail();
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }

        return $user;
    }
}
