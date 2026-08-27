<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * "Chứng từ nhập/xuất/hoàn trả" — ảnh/scan/PDF đính kèm phiếu nhập, phiếu xuất,
 * hoàn trả/điều chỉnh (Modules\Warehouse\Http\Controllers\DocumentAttachmentController,
 * bảng document_attachments). Cùng khuôn mẫu test warranty attachment
 * (tests/Feature/WarrantyFlow04ApiSurfaceTest.php).
 */
class DocumentAttachmentTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_inbound_attachment_upload_list_download_and_logical_delete(): void
    {
        Storage::fake('local');
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $document = $this->createInboundHeader($headers, $warehouse->id);
        $documentId = $document['document_id'];

        $list = $this->getJson("/api/v1/inbound-documents/{$documentId}/attachments", $headers)->assertOk();
        // source_name mặc định "NCC scan-driven test" (createInboundHeader) nên không
        // bắt buộc chứng từ.
        $this->assertSame('NOT_REQUIRED', $list->json('meta.evidence_status'));

        $upload = $this->post("/api/v1/inbound-documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->image('phieu-giao-hang.jpg'),
            'attachment_type' => 'SOURCE_DOCUMENT',
        ], $headers);
        $upload->assertCreated()
            ->assertJsonPath('data.document_type', 'INBOUND')
            ->assertJsonPath('data.attachment_type', 'SOURCE_DOCUMENT')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $attachmentId = (string) $upload->json('data.id');

        $listAfter = $this->getJson("/api/v1/inbound-documents/{$documentId}/attachments", $headers)->assertOk();
        $this->assertSame('PROVIDED', $listAfter->json('meta.evidence_status'));
        $this->assertCount(1, $listAfter->json('data'));

        $this->get("/api/v1/document-attachments/{$attachmentId}/download", $headers)->assertOk();

        $this->deleteJson("/api/v1/document-attachments/{$attachmentId}", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'DELETED');

        $listFinal = $this->getJson("/api/v1/inbound-documents/{$documentId}/attachments", $headers)->assertOk();
        $this->assertCount(0, $listFinal->json('data'));
        $this->get("/api/v1/document-attachments/{$attachmentId}/download", $headers)->assertNotFound();
    }

    public function test_inbound_attachment_rejects_disguised_file_content_type(): void
    {
        Storage::fake('local');
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $document = $this->createInboundHeader($headers, $warehouse->id);

        // §13.8-style content-type check: đổi tên .php thành .jpg vẫn phải bị chặn vì
        // finfo đọc magic bytes thật, không tin phần mở rộng client gửi lên.
        $fake = UploadedFile::fake()->createWithContent('malware.jpg', '<?php echo "not-an-image"; ?>');

        $this->post("/api/v1/inbound-documents/{$document['document_id']}/attachments", [
            'file' => $fake,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTACHMENT_CONTENT_TYPE_INVALID');
    }

    public function test_pending_evidence_list_surfaces_inbound_document_without_source_name_or_attachment(): void
    {
        Storage::fake('local');
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();

        // "nhập tay" không kèm chứng từ nguồn: source_name rỗng.
        $document = $this->createInboundHeader($headers, $warehouse->id, ['source_name' => '']);
        $documentId = $document['document_id'];

        $pending = $this->getJson('/api/v1/document-attachments/pending-evidence?per_page=100', $headers)->assertOk();
        $ids = collect($pending->json('data'))->pluck('document_id')->all();
        $this->assertContains($documentId, $ids, 'Phiếu nhập không có source_name/chứng từ phải xuất hiện trong danh sách cần bổ sung.');

        $this->post("/api/v1/inbound-documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->image('chung-tu-nguon.jpg'),
        ], $headers)->assertCreated();

        $pendingAfter = $this->getJson('/api/v1/document-attachments/pending-evidence?per_page=100', $headers)->assertOk();
        $idsAfter = collect($pendingAfter->json('data'))->pluck('document_id')->all();
        $this->assertNotContains($documentId, $idsAfter, 'Sau khi đính kèm chứng từ, phiếu phải rời khỏi danh sách cần bổ sung.');
    }

    /**
     * Bug đã fix: trước đây tạo reversal loại `receipt` (điều chỉnh phiếu nhập) qua
     * `POST inbound-documents/{id}/reverse` sẽ Post NGAY trong cùng request
     * (CurrentBaselineReversalService::execute() gọi postDraft(..., requireEvidence:
     * false)) — bỏ qua hoàn toàn guard bắt buộc chứng từ của REVERSAL vì lúc tạo
     * chưa có reversal_document_id để đính kèm trước. Test này khoá lại hành vi
     * đúng: request tạo LUÔN dừng ở DRAFT, và Post riêng vẫn bị chặn tới khi có
     * chứng từ đính kèm.
     */
    public function test_receipt_reversal_always_stops_at_draft_and_post_still_requires_evidence(): void
    {
        Storage::fake('local');
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-REV-RECEIPT-001');

        $fixture = $this->scanDrivenInboundFixture($headers, (string) $warehouse->id, [
            ['sku_code' => $sku->sku_code, 'qty' => 1],
        ]);
        $posted = $this->postInboundReceipt($headers, $fixture['document_id'], $fixture['version'])->assertOk();
        $postedVersion = (int) $posted->json('data.version');

        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $fixture['document_id'])
            ->where('movement_type', 'RECEIPT')
            ->value('id');
        $itemId = (string) DB::table('item_instances')->where('sku_id', $sku->sku_id)->value('item_id');
        $this->assertNotEmpty($movementId);
        $this->assertNotEmpty($itemId);

        $created = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Nhập nhầm số lượng, điều chỉnh giảm',
            'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $itemId]],
        ], $headers + ['If-Match' => (string) $postedVersion, 'Idempotency-Key' => 'rev-receipt-draft-001'])->assertOk();

        // Trước fix: 'reversal_status' trả về 'POSTED' ngay tại đây, không có cửa sổ
        // nào để đính kèm chứng từ trước khi tồn kho đã bị ghi đè.
        $created->assertJsonPath('data.reversal_status', 'DRAFT')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $reversalId = (string) $created->json('data.reversal_document_id');
        $this->assertDatabaseHas('reversal_document', ['reversal_document_id' => $reversalId, 'status' => 'DRAFT']);
        $this->assertSame(0, DB::table('stock_movements')->where('source_id', $reversalId)->count());

        $blocked = $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'rev-receipt-post-blocked-001',
        ]);
        $blocked->assertStatus(422)->assertJsonPath('error.code', 'EVIDENCE_REQUIRED');

        $this->post("/api/v1/reversal-documents/{$reversalId}/attachments", [
            'file' => UploadedFile::fake()->image('phieu-dieu-chinh.jpg'),
        ], $headers)->assertCreated();

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'rev-receipt-post-ok-001',
        ])->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');
    }

    public function test_outbound_attachment_upload_is_optional_and_state_conflict_after_post(): void
    {
        Storage::fake('local');
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-ATT-OUTBOUND-001');
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 5);
        $requestId = $this->outboundRequestFixture((string) $sku->sku_id, 1, 'TOA-ATT-001');
        $document = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $document['document_id'];

        $list = $this->getJson("/api/v1/outbound-documents/{$documentId}/attachments", $headers)->assertOk();
        // Outbound luôn có outbound_request_imports bắt buộc làm chứng từ nguồn nên
        // không bị chặn Post dù chưa đính kèm gì thêm ở đây.
        $this->assertSame('NOT_REQUIRED', $list->json('meta.evidence_status'));

        $this->post("/api/v1/outbound-documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->image('bien-ban-giao-nhan.jpg'),
            'attachment_type' => 'DELIVERY_NOTE',
        ], $headers)->assertCreated()->assertJsonPath('data.document_type', 'OUTBOUND');

        DB::table('stock_documents')->where('id', $documentId)->update(['status' => 'POSTED']);
        $this->post("/api/v1/outbound-documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->image('too-late.jpg'),
        ], $headers)->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    }
}
