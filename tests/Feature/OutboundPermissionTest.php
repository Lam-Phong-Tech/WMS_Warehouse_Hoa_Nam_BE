<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195: xác nhận RBAC thật sự enforce trên từng endpoint outbound bằng HTTP,
 * không chỉ dựa vào route middleware string tồn tại trong source. Dùng đúng role
 * matrix thật từ AuthDatabaseSeeder:
 * - WAREHOUSE_STAFF: outbound.view/scan + tiếp nhận-Confirm toa (không có post/cancel).
 * - WAREHOUSE_APPROVER: outbound.view/post/cancel (không có intake/scan).
 * - VIEWER: chỉ outbound.view.
 * - SUPER_ADMIN: bypass mọi permission.
 *
 * SRS §8.1: không còn POST /outbound-documents — phiếu xuất chỉ sinh từ Confirm toa,
 * nên quyền "tạo phiếu" được kiểm qua outbound.request.import/confirm.
 */
class OutboundPermissionTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->postJson('/api/v1/outbound-request-imports', [])
            ->assertStatus(401);

        $this->getJson('/api/v1/outbound-documents')
            ->assertStatus(401);
    }

    public function test_viewer_role_can_read_but_not_intake_or_scan_or_post(): void
    {
        $headers = $this->authHeadersWithRole('VIEWER');
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-VIEWER-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-PERM-VIEWER-001');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        $this->getJson('/api/v1/outbound-documents', $headers)->assertOk();

        $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-PERM-VIEWER-002',
                'customer_name' => 'Khách A',
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers)->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->postJson("/api/v1/outbound-documents/{$created['document_id']}/scan", [
            'line_id' => $created['line_ids'][0],
            'quantity' => 1,
        ], $headers + ['If-Match' => '1'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_warehouse_staff_can_intake_and_scan_but_not_post_or_cancel(): void
    {
        $staffHeaders = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-STAFF-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);

        // Tiếp nhận + Confirm toa: đây là con đường duy nhất tạo phiếu xuất (§8.1).
        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-PERM-STAFF-001',
                'customer_name' => 'Khách A',
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $staffHeaders);
        $import->assertCreated();
        $importId = (string) $import->json('data.import.id');
        $confirm = $this->postJson("/api/v1/outbound-request-imports/{$importId}/confirm", [], $staffHeaders + [
            'If-Match' => (string) ((int) $import->json('data.import.version')),
            'Idempotency-Key' => 'perm-staff-confirm-001',
        ]);
        $confirm->assertOk();
        $documentId = (string) $confirm->json('data.outbound_document_ids.0');
        $lineId = (string) DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->value('id');
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

    public function test_warehouse_approver_can_post_and_cancel_but_not_intake_or_scan(): void
    {
        $approverHeaders = $this->authHeadersWithRole('WAREHOUSE_APPROVER');
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PERM-APPROVER-001');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-PERM-APPROVER-001');

        // WAREHOUSE_APPROVER không có quyền tiếp nhận toa.
        $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-PERM-APPROVER-002',
                'customer_name' => 'Khách A',
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $approverHeaders)->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];

        // WAREHOUSE_APPROVER không có outbound.scan.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $created['line_ids'][0],
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
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);

        $this->getJson('/api/v1/outbound-documents', $headers)->assertOk();
        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-PERM-SA-001',
                'customer_name' => 'Khách A',
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers);
        $import->assertCreated();
        $this->postJson(
            "/api/v1/outbound-request-imports/{$import->json('data.import.id')}/confirm",
            [],
            $headers + [
                'If-Match' => (string) ((int) $import->json('data.import.version')),
                'Idempotency-Key' => 'perm-sa-confirm-001',
            ]
        )->assertOk()->assertJsonCount(1, 'data.outbound_document_ids');
    }

    public function test_403_response_does_not_leak_stack_trace_or_secrets(): void
    {
        $headers = $this->authHeadersWithRole('VIEWER');

        $response = $this->postJson('/api/v1/outbound-request-imports', [], $headers);

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
