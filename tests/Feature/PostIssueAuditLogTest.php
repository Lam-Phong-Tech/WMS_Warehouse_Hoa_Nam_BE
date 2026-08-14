<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195: đối xứng với PostReceiptAuditLogTest — verify AuditLogger::record() thực
 * sự persist audit_log row (không chỉ assert call-site tồn tại trong source) cho
 * CREATE_OUTBOUND (lúc tạo document từ toa) và POST_ISSUE (lúc post thành công), và
 * idempotent replay không được ghi thêm audit row thứ hai.
 */
class PostIssueAuditLogTest extends TestCase
{
    use RefreshDatabase;
    use PreparesOutboundFixtures;

    public function test_create_outbound_writes_audit_log_row_with_outbound_request_id(): void
    {
        $auth = $this->superAdminHeaders();
        $actorId = $auth['__user_id'];
        $headers = ['Authorization' => $auth['Authorization']];

        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('AUDIT-ISSUE-CREATE-001');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-AUDIT-CREATE-001');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');

        $auditRow = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'CREATE_OUTBOUND')
            ->first();

        $this->assertNotNull($auditRow, 'Expected a CREATE_OUTBOUND audit_log row after creating the outbound document.');
        $this->assertSame($actorId, $auditRow->actor_id);
        $this->assertNotNull($auditRow->request_id);

        $afterData = json_decode((string) $auditRow->after_data, true);
        $this->assertSame('DRAFT', $afterData['status']);
        $this->assertSame($requestId, $afterData['outbound_request_id']);
    }

    public function test_post_issue_writes_single_audit_log_row_and_replay_does_not_duplicate_it(): void
    {
        $auth = $this->superAdminHeaders();
        $actorId = $auth['__user_id'];
        $headers = ['Authorization' => $auth['Authorization']];

        $doc = $this->readyToPostIssueDocument($headers, 'AUDIT-ISSUE-POST-001');
        $documentId = $doc['document_id'];

        $auditBefore = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'POST_ISSUE')
            ->count();
        $this->assertSame(0, $auditBefore, 'Should not have a POST_ISSUE audit row before posting.');

        $postHeaders = $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'issue-audit-log-001',
        ];
        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $postHeaders)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.status', 'POSTED');

        $auditRow = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'POST_ISSUE')
            ->first();

        $this->assertNotNull($auditRow, 'Expected a POST_ISSUE audit_log row after posting.');
        $this->assertSame($actorId, $auditRow->actor_id);
        $this->assertNotNull($auditRow->request_id);

        $afterData = json_decode((string) $auditRow->after_data, true);
        $this->assertSame('POSTED', $afterData['status']);
        $this->assertNotEmpty($afterData['movement_ids']);

        $beforeData = json_decode((string) $auditRow->before_data, true);
        $this->assertSame('SCANNING', $beforeData['status']);

        // Idempotent replay của cùng key/payload không được tạo thêm audit row.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $postHeaders)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');

        $auditAfterReplay = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'POST_ISSUE')
            ->count();
        $this->assertSame(1, $auditAfterReplay, 'Idempotent replay must not duplicate the audit_log row.');
    }

    public function test_cancel_outbound_writes_audit_log_row_with_reason(): void
    {
        $auth = $this->superAdminHeaders();
        $actorId = $auth['__user_id'];
        $headers = ['Authorization' => $auth['Authorization']];

        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('AUDIT-ISSUE-CANCEL-001');
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-AUDIT-CANCEL-001');

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');

        $cancel = $this->postJson("/api/v1/outbound-documents/{$documentId}/cancel", [
            'reason' => 'Khách hủy đơn trước khi xuất',
        ], $headers + ['If-Match' => '1']);
        $cancel->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $auditRow = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $documentId)
            ->where('action', 'CANCEL_STOCK_DOCUMENT')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertSame($actorId, $auditRow->actor_id);
        $this->assertSame('Khách hủy đơn trước khi xuất', $auditRow->reason);

        // Toa phải trả về OPEN vì không còn phiếu active/POSTED nào dùng toa.
        $this->assertDatabaseHas('outbound_requests', ['id' => $requestId, 'status' => 'OPEN']);
    }
}
