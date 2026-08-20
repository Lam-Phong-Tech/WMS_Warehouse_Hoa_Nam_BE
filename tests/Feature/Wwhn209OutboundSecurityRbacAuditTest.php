<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-209 (review 14186, P1-01 + P1-03): chứng minh authorization của luồng xuất
 * bằng direct API call — độc lập hoàn toàn với FE hide/redirect — và bịt 4 khoảng
 * trống mà OutboundPermissionTest/Wwhn217RuntimeErrorContractTest chưa phủ:
 *
 * 1. Token expired 401 trên chính endpoint outbound (trước đây chỉ test /auth/me).
 * 2. 401/403 trên các bề mặt chưa có negative test: /unmatch, alias Annex C /scans,
 *    /reversals, PUT .../items/{itemId}/rfid và cả 4 packing-label operations.
 * 3. DATA_SCOPE_DENIED runtime trên endpoint outbound — EnforceOutboundWarehouseScope
 *    trước đây chưa có test nào chạm tới (chỉ được suy ra từ inbound/reversal tương tự).
 * 4. Positive control: actor scoped đúng kho canonical phải đi qua được scope gate,
 *    chứng minh cú deny ở trên không phải false-positive.
 *
 * SRS §8.1 "Enforcement standard": authentication → permission → scope, default deny,
 * denial không được thay đổi state/stock/movement (§8.2). Ca "ngoài phạm vi" dựng bằng
 * user_role.scope_ref trỏ warehouse id KHÁC (đúng cơ chế User::warehouseScopeIds() đọc),
 * không dựng kho ACTIVE thứ hai — REQ-SRS-001 chỉ cho phép 01 kho tổng.
 */
class Wwhn209OutboundSecurityRbacAuditTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** Warehouse id nằm ngoài mọi phạm vi hợp lệ — dùng làm scope_ref của actor "kho khác". */
    private const FOREIGN_WAREHOUSE_ID = '00000000-0000-4000-8000-000000000209';

    /**
     * Actor bị giới hạn đúng một kho qua user_role.scope_ref (cùng pattern
     * Wwhn242ReversalScopeAndProjectionTest — cơ chế duy nhất mà
     * User::warehouseScopeIds() công nhận).
     *
     * @param  list<string>  $permissionKeys
     */
    private function scopedHeaders(string $warehouseId, array $permissionKeys): array
    {
        $roleCode = 'WWHN209_SCOPED_'.Str::upper(Str::random(6));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $user = User::create([
            'username' => 'wwhn209-scoped-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-209 Scoped Actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseId,
        ]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /**
     * Dựng 1 phiếu xuất DRAFT trong kho canonical kèm label PRINTED + item IN_STOCK,
     * trả về đủ id cho các bề mặt cần test. Gọi SAU khi actor đã login để
     * outboundDocumentFixture() lấy được created_by từ auth('api').
     *
     * @return array{document_id: string, line_id: string, label_id: string, item_id: string, warehouse_id: string, sku_id: string}
     */
    private function outboundSurfaceFixture(string $skuCode, string $requestNo): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, $requestNo);
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $this->markLabelsPrinted($created['document_id']);
        $labelId = (string) DB::table('label_snapshots')
            ->where('outbound_document_id', $created['document_id'])
            ->value('id');

        return [
            'document_id' => $created['document_id'],
            'line_id' => $created['line_ids'][0],
            'label_id' => $labelId,
            'item_id' => $this->seedItemInStock($sku->sku_id),
            'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id,
        ];
    }

    /** Gap 1: token hết hạn phải 401 UNAUTHENTICATED trên chính endpoint outbound. */
    public function test_expired_access_token_is_rejected_with_401_on_outbound_surface(): void
    {
        $headers = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $user = User::query()->findOrFail($headers['__user_id']);
        $factory = JWTAuth::factory();
        $originalTtl = $factory->getTTL();

        try {
            $factory->setTTL(1);
            $expiredToken = JWTAuth::fromUser($user);
            Carbon::setTestNow(now()->addMinutes(2));

            // authHeadersWithRole() đã login() nên guard đang cache user trong
            // process test — phải quên guard đi để middleware auth:api buộc phải
            // parse lại bearer token hết hạn thay vì dùng user cache.
            auth('api')->logout();
            app('auth')->forgetGuards();

            $this->getJson('/api/v1/outbound-documents', [
                'Authorization' => "Bearer {$expiredToken}",
            ])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

            $this->postJson('/api/v1/outbound-documents/'.Str::uuid().'/post-issue', [], [
                'Authorization' => "Bearer {$expiredToken}",
                'If-Match' => '1',
                'Idempotency-Key' => 'wwhn209-expired-post-001',
            ])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        } finally {
            Carbon::setTestNow();
            $factory->setTTL($originalTtl);
        }
    }

    /** Gap 2a: các bề mặt outbound chưa từng có negative test đều phải 401 khi vô danh. */
    public function test_unauthenticated_requests_are_rejected_on_untested_outbound_surfaces(): void
    {
        $documentId = (string) Str::uuid();
        $labelId = (string) Str::uuid();
        $itemId = (string) Str::uuid();

        $this->postJson("/api/v1/outbound-documents/{$documentId}/unmatch", [])->assertStatus(401);
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scans", [])->assertStatus(401);
        $this->postJson("/api/v1/outbound-documents/{$documentId}/reversals", [])->assertStatus(401);
        $this->putJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/rfid", [])->assertStatus(401);

        foreach (['preview', 'ready', 'print', 'reprint'] as $operation) {
            $this->postJson(
                "/api/v1/outbound-documents/{$documentId}/packing-labels/{$labelId}/{$operation}",
                []
            )->assertStatus(401);
        }
    }

    /**
     * Gap 2b: VIEWER (chỉ outbound.view) phải bị 403 ACCESS_DENIED trên alias /scans,
     * /unmatch, RFID và cả 4 packing-label operations — và deny không để lại hiệu ứng phụ.
     */
    public function test_viewer_receives_403_access_denied_on_label_scan_and_rfid_surfaces(): void
    {
        $headers = $this->authHeadersWithRole('VIEWER');
        $fixture = $this->outboundSurfaceFixture('WWHN209-VIEWER-001', 'TOA-209-VIEWER-001');
        $documentId = $fixture['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scans", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/unmatch", [
            'line_id' => $fixture['line_id'],
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->putJson(
            "/api/v1/outbound-documents/{$documentId}/items/{$fixture['item_id']}/rfid",
            ['rfid' => 'RFID-209-DENY'],
            $headers
        )->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        foreach (['preview', 'ready', 'print', 'reprint'] as $operation) {
            $this->postJson(
                "/api/v1/outbound-documents/{$documentId}/packing-labels/{$fixture['label_id']}/{$operation}",
                [],
                $headers + ['If-Match' => '1', 'Idempotency-Key' => "wwhn209-viewer-{$operation}-001"]
            )->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        }

        // §8.2: denial không thay đổi state/stock/movement.
        $this->assertSame(0, (int) DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)->value('scanned_qty'));
        $this->assertSame('PRINTED', (string) DB::table('label_snapshots')
            ->where('id', $fixture['label_id'])->value('status'));
        $this->assertSame(1, (int) DB::table('stock_documents')
            ->where('id', $documentId)->value('version'));
        $this->assertSame(0, DB::table('stock_movements')
            ->where('source_id', $documentId)->count());
    }

    /**
     * Gap 3: actor CÓ ĐỦ permission nhưng scope_ref trỏ kho khác phải bị
     * 403 DATA_SCOPE_DENIED trên mọi bề mặt outbound — đọc lẫn ghi — và
     * stock/movement/status giữ nguyên (SRS §8.1 permission ≠ scope).
     */
    public function test_foreign_scope_actor_with_permissions_receives_403_data_scope_denied(): void
    {
        $headers = $this->scopedHeaders(self::FOREIGN_WAREHOUSE_ID, [
            'outbound.view',
            'outbound.scan',
            'outbound.post',
            'outbound.cancel',
            'physical_code.assign_rfid',
            'packing_label.print',
        ]);
        $fixture = $this->outboundSurfaceFixture('WWHN209-SCOPE-001', 'TOA-209-SCOPE-001');
        $documentId = $fixture['document_id'];

        $this->getJson("/api/v1/outbound-documents/{$documentId}", $headers)
            ->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->getJson('/api/v1/outbound-documents?warehouse_id='.$fixture['warehouse_id'], $headers)
            ->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scans", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'wwhn209-scope-post-001',
        ])->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/cancel", [
            'reason' => 'scope probe',
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->postJson(
            "/api/v1/outbound-documents/{$documentId}/packing-labels/{$fixture['label_id']}/print",
            [],
            $headers + ['If-Match' => '1', 'Idempotency-Key' => 'wwhn209-scope-print-001']
        )->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->putJson(
            "/api/v1/outbound-documents/{$documentId}/items/{$fixture['item_id']}/rfid",
            ['rfid' => 'RFID-209-SCOPE-DENY'],
            $headers
        )->assertStatus(403)->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        // Ghi vượt biên bị chặn hoàn toàn: DRAFT giữ nguyên, không movement, tồn không đổi.
        $this->assertSame('DRAFT', (string) DB::table('stock_documents')
            ->where('id', $documentId)->value('status'));
        $this->assertSame(0, DB::table('stock_movements')
            ->where('source_id', $documentId)->count());
        $this->assertSame(0, (int) DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)->value('scanned_qty'));
        $this->assertSame(1, (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('available_qty'));
    }

    /**
     * Positive control cho Gap 3: cùng cơ chế scope_ref nhưng trỏ ĐÚNG kho canonical
     * thì đi qua scope gate bình thường — chứng minh cú deny phía trên không phải
     * false-positive của middleware.
     */
    public function test_actor_scoped_to_canonical_warehouse_passes_outbound_scope_gate(): void
    {
        // Login một actor bất kỳ trước để outboundSurfaceFixture() có created_by.
        $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $fixture = $this->outboundSurfaceFixture('WWHN209-POSCTL-001', 'TOA-209-POSCTL-001');

        $headers = $this->scopedHeaders($fixture['warehouse_id'], ['outbound.view']);

        $this->getJson("/api/v1/outbound-documents/{$fixture['document_id']}", $headers)
            ->assertOk();
        $this->getJson('/api/v1/outbound-documents?warehouse_id='.$fixture['warehouse_id'], $headers)
            ->assertOk();
    }
}
