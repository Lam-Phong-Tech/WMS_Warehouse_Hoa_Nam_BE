<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195: xác nhận RBAC thật sự enforce trên từng endpoint outbound bằng HTTP,
 * không chỉ dựa vào route middleware string tồn tại trong source. Dùng đúng role
 * matrix thật từ AuthDatabaseSeeder:
 * - WAREHOUSE_STAFF: outbound.view/create/scan (không có post/cancel).
 * - WAREHOUSE_APPROVER: outbound.view/post/cancel (không có create/scan).
 * - VIEWER: chỉ outbound.view.
 * - SUPER_ADMIN: bypass mọi permission.
 */
class OutboundPermissionTest extends TestCase
{
    use RefreshDatabase;
    use PreparesOutboundFixtures;

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->postJson('/api/v1/outbound-documents', [])
            ->assertStatus(401);

        $this->getJson('/api/v1/outbound-documents')
            ->assertStatus(401);
    }

    public function test_viewer_role_can_read_but_not_create_or_scan_or_post(): void
    {
        $headers = $this->authHeadersWithRole('VIEWER');
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-VIEWER-001');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-PERM-VIEWER-001');

        $this->getJson('/api/v1/outbound-documents', $headers)->assertOk();

        $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_warehouse_staff_can_create_and_scan_but_not_post_or_cancel(): void
    {
        $staffHeaders = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-STAFF-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-PERM-STAFF-001');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $staffHeaders);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');
        $itemId = $this->seedItemInStock($sku->sku_id);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $staffHeaders + ['If-Match' => '1'])->assertOk();

        // WAREHOUSE_STAFF không có outbound.post/outbound.cancel.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $staffHeaders + [
            'If-Match' => '2',
            'Idempotency-Key' => 'perm-staff-post-001',
        ])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->postJson("/api/v1/outbound-documents/{$documentId}/cancel", [
            'reason' => 'test',
        ], $staffHeaders + ['If-Match' => '2'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_warehouse_approver_can_post_and_cancel_but_not_create_or_scan(): void
    {
        $approverHeaders = $this->authHeadersWithRole('WAREHOUSE_APPROVER');
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-APPROVER-001');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-PERM-APPROVER-001');

        // WAREHOUSE_APPROVER không có outbound.create.
        $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $approverHeaders)->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        // Dựng document bằng SUPER_ADMIN rồi thử post/cancel bằng APPROVER.
        $adminHeaders = $this->superAdminHeaders();
        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $adminHeaders)->assertCreated();
        $documentId = (string) $create->json('data.id');

        // WAREHOUSE_APPROVER không có outbound.scan.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => (string) $create->json('data.lines.0.id'),
            'quantity' => 1,
        ], $approverHeaders + ['If-Match' => '1'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $cancel = $this->postJson("/api/v1/outbound-documents/{$documentId}/cancel", [
            'reason' => 'Approver hủy phiếu',
        ], $approverHeaders + ['If-Match' => '1']);
        $cancel->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_super_admin_bypasses_all_outbound_permissions(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-SA-001');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-PERM-SA-001');

        $this->getJson('/api/v1/outbound-documents', $headers)->assertOk();
        $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
    }

    public function test_403_response_does_not_leak_stack_trace_or_secrets(): void
    {
        $headers = $this->authHeadersWithRole('VIEWER');

        $response = $this->postJson('/api/v1/outbound-documents', [], $headers);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('stack')
            ->assertJsonStructure([
                'success', 'message', 'error_code', 'errors',
                'error' => ['code', 'details'],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
