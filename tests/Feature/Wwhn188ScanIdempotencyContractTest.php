<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-188 (HN-IN-002, BE1) — đóng reviewer finding DEV188-P1-03R2
 * ("OPEN — VERIFIED DEFECT: quantity-mode retry double-count") theo
 * DEC-SCAN-IDEMP-001.
 *
 * Reviewer yêu cầu đúng 4 nhóm case và một ràng buộc cuối:
 *
 *   1. lost-response retry     — client không nhận được response, gửi lại y hệt;
 *   2. stale retry             — gửi lại với version cũ đã lỗi thời;
 *   3. re-fetch retry          — tải lại phiếu, thấy version mới, gửi lại;
 *   4. same key/different payload;
 *   5. "final retry must not double-count".
 *
 * Mỗi case dưới đây chạy thật qua HTTP route production
 * POST /api/v1/inbound-documents/{id}/scan và kiểm chứng ở **tầng DB**
 * (stock_document_lines.scanned_qty, stock_documents.version,
 * inbound_scan_entries), không chỉ ở HTTP status.
 *
 * Nhánh COMPONENT quantity-mode (không có item_id) là nhánh từng thủng: nhánh có
 * item_id đã được assertNoDuplicateItemInDocument() chặn tuyệt đối từ trước
 * (409 DUPLICATE_ITEM_IN_DOCUMENT).
 */
class Wwhn188ScanIdempotencyContractTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function scanKey(string $tag): string
    {
        return 'wwhn188-scan-'.$tag.'-'.Str::lower(Str::random(10));
    }

    /**
     * Phiếu nhập COMPONENT quantity-mode: 1 line qty_planned=5 + 1 BOX hợp lệ
     * (middleware EnsureInboundComponentBox bắt buộc container cho COMPONENT).
     *
     * @return array{headers: array<string,string>, document_id: string, line_id: string, box_id: string}
     */
    private function draftComponentQuantityDocument(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'WWHN-188 scan idempotency component',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC WWHN-188 Scan Idempotency',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 5]],
        ], $headers)->assertCreated();

        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(10)),
        ], $headers)->assertCreated();

        return [
            'headers' => $headers,
            'document_id' => $documentId,
            'line_id' => $lineId,
            'box_id' => (string) $box->json('data.container_id'),
        ];
    }

    private function assertLineState(string $lineId, int $scannedQty): void
    {
        $this->assertSame(
            $scannedQty,
            (int) DB::table('stock_document_lines')->where('id', $lineId)->value('scanned_qty'),
            'scanned_qty phải đúng bằng số lần quét thật, không cộng dồn theo số lần gửi lại.'
        );
    }

    private function assertDocumentVersion(string $documentId, int $version): void
    {
        $this->assertSame(
            $version,
            (int) DB::table('stock_documents')->where('id', $documentId)->value('version'),
            'Replay không được bump version — chỉ lần áp dụng thật mới bump.'
        );
    }

    /**
     * Case 1 — LOST-RESPONSE RETRY. Client gửi request, server áp dụng xong nhưng
     * response rơi trên đường về. Client gửi lại y hệt (cùng key, cùng payload,
     * cùng If-Match cũ vì nó chưa biết version đã nhảy).
     *
     * Trước DEC-SCAN-IDEMP-001 kết quả là 412 STALE_VERSION — không double-count
     * nhưng client không lấy lại được kết quả và buộc phải re-fetch (chính là bước
     * dẫn tới case 3). Sau khi có key, retry trả lại đúng response cũ.
     */
    public function test_lost_response_retry_with_same_key_replays_and_does_not_double_count(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-LOST-001');
        $key = $this->scanKey('lost');
        $body = ['line_id' => $ctx['line_id'], 'quantity' => 2, 'container_id' => $ctx['box_id']];

        $first = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]);
        $first->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.replayed', false);
        $this->assertSame('false', $first->headers->get('Idempotent-Replay'));

        $retry = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key]);
        $retry->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.replayed', true);
        $this->assertSame('true', $retry->headers->get('Idempotent-Replay'));

        $this->assertLineState($ctx['line_id'], 2);
        $this->assertDocumentVersion($ctx['document_id'], 2);
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('inbound_line_id', $ctx['line_id'])->count());
    }

    /**
     * Case 2 + 3 — RE-FETCH RETRY (chính là defect DEV188-P1-03 reviewer xác nhận)
     * và STALE RETRY nằm chung một dòng thời gian: client mất response, **tải lại
     * phiếu**, thấy version đã là 2, và gửi lại đúng hành động quét đó với version
     * mới hợp lệ. If-Match không thể phát hiện vì version hoàn toàn đúng.
     *
     * Bằng chứng cũ (InboundScanConcurrencyBehaviorTest) từng ghi nhận
     * scanned_qty = 4. Sau DEC-SCAN-IDEMP-001 phải là 2.
     */
    public function test_refetch_retry_with_same_key_and_current_version_does_not_double_count(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-REFETCH-001');
        $key = $this->scanKey('refetch');
        $body = ['line_id' => $ctx['line_id'], 'quantity' => 2, 'container_id' => $ctx['box_id']];

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key])
            ->assertOk()->assertJsonPath('data.scanned_quantity', 2);

        // Client re-fetch: version hiện tại là 2 — retry mang version đúng.
        $refetched = (int) DB::table('stock_documents')->where('id', $ctx['document_id'])->value('version');
        $this->assertSame(2, $refetched);

        $retry = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
            $ctx['headers'] + ['If-Match' => (string) $refetched, 'Idempotency-Key' => $key]);
        $retry->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.replayed', true);

        $this->assertLineState($ctx['line_id'], 2);
        $this->assertDocumentVersion($ctx['document_id'], 2);
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('inbound_line_id', $ctx['line_id'])->count());
    }

    /**
     * Case 5 — "final retry must not double-count": chuỗi retry dài, xen kẽ version
     * cũ và version mới, tất cả cùng key/cùng payload. Sau toàn bộ chuỗi
     * scanned_qty vẫn phải đúng bằng một lần quét.
     */
    public function test_repeated_mixed_version_retries_apply_the_scan_exactly_once(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-CHAIN-001');
        $key = $this->scanKey('chain');
        $body = ['line_id' => $ctx['line_id'], 'quantity' => 3, 'container_id' => $ctx['box_id']];

        foreach (['1', '1', '2', '2', '1', '2'] as $ifMatch) {
            $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
                $ctx['headers'] + ['If-Match' => $ifMatch, 'Idempotency-Key' => $key])
                ->assertOk()
                ->assertJsonPath('data.scanned_quantity', 3)
                ->assertJsonPath('data.version', 2);
        }

        $this->assertLineState($ctx['line_id'], 3);
        $this->assertDocumentVersion($ctx['document_id'], 2);
        $this->assertSame(1, DB::table('inbound_scan_entries')->where('inbound_line_id', $ctx['line_id'])->count());
    }

    /**
     * Case 4 — SAME KEY / DIFFERENT PAYLOAD. SRS §8.2 chỉ cho phép 409 với đúng bộ
     * mã STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT /
     * DOWNSTREAM_DEPENDENCY_EXISTS; case này phải là IDEMPOTENCY_CONFLICT và tuyệt
     * đối không được ghi nhận số lượng mới.
     */
    public function test_same_key_with_different_quantity_is_rejected_with_409_idempotency_conflict(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-CONFLICT-001');
        $key = $this->scanKey('conflict');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 2, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key])->assertOk();

        $conflict = $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 3, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '2', 'Idempotency-Key' => $key]);

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertLineState($ctx['line_id'], 2);
        $this->assertDocumentVersion($ctx['document_id'], 2);
    }

    /**
     * Alias qty (Annex C) và quantity (legacy) là **cùng một payload**.
     * Fingerprint canonical phải coi chúng bằng nhau, nếu không một retry hợp lệ
     * đổi alias sẽ bị 409 sai — đây là lý do không dùng thẳng
     * IdempotencyStore::requestHash().
     */
    public function test_qty_alias_is_not_treated_as_a_different_payload(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-ALIAS-001');
        $key = $this->scanKey('alias');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 2, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'qty' => 2, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '2', 'Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertLineState($ctx['line_id'], 2);
    }

    /**
     * Ràng buộc nghiệp vụ phải giữ: hai *hành động quét thật* liên tiếp cùng nội
     * dung (rất bình thường với COMPONENT quantity-mode) vẫn phải cộng dồn. Nếu
     * dedupe theo nội dung thuần (hoặc theo cửa sổ thời gian) thì case này sẽ bị
     * nuốt oan — đó là lý do danh tính request phải do client cấp.
     */
    public function test_different_key_same_payload_is_a_genuine_second_scan(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-SECOND-001');
        $body = ['line_id' => $ctx['line_id'], 'quantity' => 2, 'container_id' => $ctx['box_id']];

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $this->scanKey('second-a')])
            ->assertOk()->assertJsonPath('data.scanned_quantity', 2);

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan", $body,
            $ctx['headers'] + ['If-Match' => '2', 'Idempotency-Key' => $this->scanKey('second-b')])
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 4)
            ->assertJsonPath('data.replayed', false);

        $this->assertLineState($ctx['line_id'], 4);
        $this->assertDocumentVersion($ctx['document_id'], 3);
    }

    /**
     * Backward compatibility: scan là stock-neutral và Annex C không khai
     * Idempotency-Key bắt buộc, nên thiếu header **không** được 428 (khác
     * post-receipt). Client cũ vẫn chạy đúng như trước.
     */
    public function test_scan_without_idempotency_key_still_works_and_is_not_428(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-NOKEY-001');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 1, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 1)
            ->assertJsonPath('data.replayed', false);

        $this->assertLineState($ctx['line_id'], 1);
    }

    /** Độ dài key dùng chung contract IdempotencyKeyGuard (8..100) — 422, không phải 500. */
    public function test_too_short_idempotency_key_is_rejected_with_422(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-SHORTKEY-001');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 1, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertLineState($ctx['line_id'], 0);
    }

    /**
     * Request lỗi không được cache: sau một 422 (quét vượt Expected) client sửa dữ
     * liệu và gửi lại bằng **đúng key cũ** thì phải chạy được, không bị kẹt 409
     * vĩnh viễn.
     */
    public function test_failed_scan_does_not_poison_the_idempotency_key(): void
    {
        $ctx = $this->draftComponentQuantityDocument('WWHN188-IDEMP-RECOVER-001');
        $key = $this->scanKey('recover');

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 99, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key])
            ->assertStatus(422);

        $this->assertSame(
            0,
            DB::table('idempotency_keys')->where('idem_key', $key)->count(),
            'Lease của request lỗi phải bị dọn để client sửa dữ liệu và thử lại cùng key.'
        );

        $this->postJson("/api/v1/inbound-documents/{$ctx['document_id']}/scan",
            ['line_id' => $ctx['line_id'], 'quantity' => 2, 'container_id' => $ctx['box_id']],
            $ctx['headers'] + ['If-Match' => '1', 'Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.scanned_quantity', 2)
            ->assertJsonPath('data.replayed', false);

        $this->assertLineState($ctx['line_id'], 2);
    }

    /**
     * Nhánh serialized (item_id) trước nay đã an toàn tuyệt đối nhờ
     * assertNoDuplicateItemInDocument() (409 DUPLICATE_ITEM_IN_DOCUMENT). Sau
     * DEC-SCAN-IDEMP-001, retry đúng key trả về **kết quả cũ** thay vì 409 — client
     * phục hồi được sau lost-response mà vẫn không tạo entry thứ hai.
     */
    public function test_serialized_item_retry_with_same_key_replays_instead_of_duplicate_conflict(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN188-IDEMP-SERIAL-001');

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC WWHN-188 Scan Idempotency Serial',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => 1]],
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SN-'.Str::upper(Str::random(8)),
            'item_status' => 'NEW',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $this->scanKey('serial');
        $body = ['line_id' => $lineId, 'quantity' => 1, 'item_id' => $itemId];

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", $body,
            $headers + ['If-Match' => '1', 'Idempotency-Key' => $key])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", $body,
            $headers + ['If-Match' => '2', 'Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, DB::table('inbound_scan_entries')->where('item_id', $itemId)->count());
        $this->assertLineState($lineId, 1);
        $this->assertDocumentVersion($documentId, 2);

        // Không có key thì guard trùng item vẫn phải chặn như cũ (defense in depth).
        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", $body,
            $headers + ['If-Match' => '2'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_ITEM_IN_DOCUMENT');
    }
}
