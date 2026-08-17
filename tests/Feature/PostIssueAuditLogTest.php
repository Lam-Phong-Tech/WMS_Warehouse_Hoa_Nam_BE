<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195: đối xứng với PostReceiptAuditLogTest — verify AuditLogger::record() thực
 * sự persist audit_log row (không chỉ assert call-site tồn tại trong source) cho
 * OUTBOUND_IMPORT_CONSUME (Confirm toa sinh phiếu xuất, §8.1) và POST_ISSUE (lúc post
 * thành công), và idempotent replay không được ghi thêm audit row thứ hai.
 */
class PostIssueAuditLogTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_confirming_toa_writes_audit_log_row_with_created_outbound_document(): void
    {
        $auth = $this->superAdminHeaders();
        $actorId = $auth['__user_id'];
        $headers = ['Authorization' => $auth['Authorization']];

        $this->warehouseFixture();
        $sku = $this->productSkuFixture('AUDIT-ISSUE-CREATE-001');

        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'TOA-AUDIT-CREATE-001',
                'customer_name' => 'Đại lý test',
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers);
        $import->assertCreated();
        $importId = (string) $import->json('data.import.id');

        $confirm = $this->postJson("/api/v1/outbound-request-imports/{$importId}/confirm", [], $headers + [
            'If-Match' => (string) ((int) $import->json('data.import.version')),
            'Idempotency-Key' => 'audit-confirm-001',
        ]);
        $confirm->assertOk();
        $documentId = (string) $confirm->json('data.outbound_document_ids.0');

        $auditRow = DB::table('audit_log')
            ->where('object_type', 'outbound_request_import')
            ->where('object_id', $importId)
            ->where('action', 'OUTBOUND_IMPORT_CONSUME')
            ->first();

        $this->assertNotNull($auditRow, 'Confirm toa phải ghi audit_log OUTBOUND_IMPORT_CONSUME.');
        $this->assertSame($actorId, $auditRow->actor_id);
        $this->assertNotNull($auditRow->request_id);

        $afterData = json_decode((string) $auditRow->after_data, true);
        $this->assertSame('CONSUMED', $afterData['status']);
        $this->assertContains($documentId, $afterData['outbound_document_ids']);
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'DRAFT']);
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

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
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
