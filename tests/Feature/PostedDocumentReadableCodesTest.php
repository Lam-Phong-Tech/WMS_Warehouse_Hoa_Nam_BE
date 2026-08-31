<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Bug thật người dùng gặp: card "Kết quả hoàn tất nhập kho" hiển thị UUID thô
 * (document_id / movement_ids) cho nhân viên kho, trong khi mã đọc được đã tồn
 * tại sẵn trong DB ngay từ lúc tạo chứng từ (`stock_documents.doc_no` = "PN-…")
 * và lúc ghi biến động (`stock_movements.movement_no` = "MV-…") — chỉ là response
 * Post không trả ra nên FE không có gì để hiển thị ngoài id.
 *
 * Test này khoá hợp đồng response: post-receipt/post-issue PHẢI trả kèm
 * `document_no` và `movements[].movement_no`. `movement_ids` (mảng id thô) giữ
 * nguyên vì payload idempotency/audit đang dùng đúng hình dạng đó.
 */
class PostedDocumentReadableCodesTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_post_receipt_tra_kem_ma_phieu_va_ma_bien_dong_doc_duoc(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $this->productSkuFixture('RD-CODES-01');

        $documentId = (string) $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC readable-codes',
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'quantity' => 1,
            'code_value' => 'HN1|SKU=RD-CODES-01|ITEM=SN-RD-CODES-0001',
        ], $headers + ['If-Match' => '1'])->assertOk();

        $post = $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'rd-codes-post-'.Str::random(8),
        ])->assertOk();

        $post->assertJsonPath('data.status', 'POSTED');

        // Mã phiếu nhập đọc được, đúng tiền tố nghiệp vụ — KHÔNG phải UUID.
        $documentNo = (string) $post->json('data.document_no');
        $this->assertNotSame('', $documentNo, 'post-receipt phải trả document_no.');
        $this->assertStringStartsWith('PN-', $documentNo);
        $this->assertNotSame($documentId, $documentNo, 'document_no không được là UUID.');

        // Mỗi biến động kèm movement_no đọc được, và vẫn khớp 1-1 với movement_ids.
        $movements = $post->json('data.movements');
        $this->assertIsArray($movements);
        $this->assertNotEmpty($movements, 'post-receipt phải trả mảng movements.');
        $this->assertSame(
            $post->json('data.movement_ids'),
            array_column($movements, 'id'),
            'movements phải khớp 1-1 và đúng thứ tự với movement_ids.'
        );
        foreach ($movements as $movement) {
            $this->assertArrayHasKey('movement_no', $movement);
            $this->assertStringStartsWith('MV-', (string) $movement['movement_no']);
            $this->assertNotSame(
                (string) $movement['id'],
                (string) $movement['movement_no'],
                'movement_no không được là UUID.'
            );
        }
    }
}
