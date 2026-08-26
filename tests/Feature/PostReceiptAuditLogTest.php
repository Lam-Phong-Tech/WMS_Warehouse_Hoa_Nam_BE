<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-190: verifies AuditLogger::record() actually persists a POST_RECEIPT row
 * (not just that the call-site exists in source) when /post-receipt succeeds,
 * and that the idempotent replay does not write a second audit row.
 */
class PostReceiptAuditLogTest extends TestCase
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
            'username' => 'audit-'.Str::lower(Str::random(6)),
            'display_name' => 'Audit Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"] + ['__user_id' => $user->user_id];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'KHO-TONG-HN',
            'name' => 'Kho tổng Hoa Nam',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_post_receipt_writes_single_audit_log_row_and_replay_does_not_duplicate_it(): void
    {
        $auth = $this->authHeaders();
        $actorId = $auth['__user_id'];
        $headers = ['Authorization' => $auth['Authorization']];

        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-AUDIT-001',
            'sku_name' => 'Component audit test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        // Hợp đồng GĐ1 scan-driven: header-only + materialize dòng COMPONENT qua
        // /component-lines (không còn tạo phiếu kèm lines/qty_planned).
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Nha cung cap Audit',
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $sku->sku_id,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-AUDIT-001',
        ], $headers);
        $boxId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 3,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $auditBefore = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'POST_RECEIPT')
            ->count();
        $this->assertSame(0, $auditBefore, 'Should not have a POST_RECEIPT audit row before posting.');

        $postHeaders = $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'receipt-audit-log-001',
        ];
        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $postHeaders)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.status', 'POSTED');

        $auditRow = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'POST_RECEIPT')
            ->first();

        $this->assertNotNull($auditRow, 'Expected a POST_RECEIPT audit_log row after posting.');
        $this->assertSame($actorId, $auditRow->actor_id);
        $this->assertNotNull($auditRow->request_id);

        $afterData = json_decode((string) $auditRow->after_data, true);
        $this->assertSame('POSTED', $afterData['status']);
        $this->assertNotEmpty($afterData['movement_ids']);

        $beforeData = json_decode((string) $auditRow->before_data, true);
        $this->assertSame('SCANNING', $beforeData['status']);

        // Idempotent replay of the same key/payload must not create a second audit row.
        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $postHeaders)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');

        $auditAfterReplay = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'POST_RECEIPT')
            ->count();
        $this->assertSame(1, $auditAfterReplay, 'Idempotent replay must not duplicate the audit_log row.');
    }
}
