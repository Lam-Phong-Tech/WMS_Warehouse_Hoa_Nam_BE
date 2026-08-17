<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-201 (task cha HN-OUT-003 — "Post Issue atomic, không âm tồn và idempotent").
 *
 * WWHN-195/WWHN-199 đã lái qua HTTP: state guard (STATE_CONFLICT), optimistic lock
 * (STALE_VERSION), business_key uniqueness (DUPLICATE_STOCK_EFFECT), idempotent
 * replay không nhân đôi audit_log, và INSUFFICIENT_STOCK tại *scan time*
 * (assertIssueAvailability — soft-check, không lockForUpdate, xem WWHN-199 mục 3).
 *
 * Gap còn lại mà bộ test hiện có chưa chứng minh: INSUFFICIENT_STOCK/
 * INSUFFICIENT_AVAILABLE_STOCK tại *Post Issue time* — nguồn sự thật cuối cùng nằm
 * trong mutateBalance() (CurrentBaselineStockService.php dòng 848-858), nơi có
 * lockForUpdate() thật trên inventory_balances và chặn before+delta<0 bất kể scan-time
 * check đã pass trước đó. Đây đúng là kịch bản "atomic, không âm tồn" mà tên task
 * WWHN-201 nhấn mạnh: 2 document cùng SKU/warehouse cùng scan xong ở SCANNING (mỗi cái
 * đều pass soft-check tại thời điểm scan riêng của nó), nhưng tổng qty vượt tồn thực —
 * document Post trước phải thắng, document Post sau phải bị chặn KHÔNG được trừ âm,
 * và không được tạo stock_movement nào cho lần Post bị chặn.
 */
class Wwhn201PostIssueAtomicNonNegativeStockTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_second_post_issue_is_rejected_with_insufficient_stock_when_first_post_already_consumed_the_shared_balance(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN201-RACE-001');

        // Tồn thực chỉ đủ cho 1 document; scan-time soft-check (assertIssueAvailability)
        // không lockForUpdate() nên cả 2 document đều scan xong bình thường (xem WWHN-199
        // mục 3) — bài test này chứng minh guard thật nằm ở mutateBalance()/Post Issue time.
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);

        $requestA = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-WWHN201-A');
        $requestB = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-WWHN201-B');

        $docA = $this->scanReadyOutboundDocument($headers, $warehouse->id, $requestA, $sku->sku_id);
        $docB = $this->scanReadyOutboundDocument($headers, $warehouse->id, $requestB, $sku->sku_id);

        $movementBefore = DB::table('stock_movements')->count();

        $postA = $this->postJson("/api/v1/outbound-documents/{$docA['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn201-race-post-a',
        ]);
        $postA->assertOk()->assertJsonPath('data.status', 'POSTED');

        $balanceAfterA = DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame(0, (int) $balanceAfterA->available_qty, 'Document A phải trừ hết tồn khả dụng.');
        $this->assertSame(0, (int) $balanceAfterA->quantity);

        // Document B đã SCANNING đầy đủ (pass soft-check lúc scan), nhưng tồn thực đã bị A
        // rút hết — Post Issue phải bị chặn tại nguồn sự thật cuối cùng, không được trừ âm.
        $postB = $this->postJson("/api/v1/outbound-documents/{$docB['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn201-race-post-b',
        ]);
        $postB->assertStatus(422);
        $this->assertContains(
            $postB->json('error.code'),
            ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK'],
            'Post Issue thứ hai phải bị chặn bởi guard không-âm-tồn tại mutateBalance(), không được xuất vượt tồn thực.'
        );

        $movementAfter = DB::table('stock_movements')->count();
        $this->assertSame($movementBefore + 1, $movementAfter, 'Document B bị chặn không được tạo stock_movement nào (chỉ A tạo 1).');

        $balanceAfterB = DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame(0, (int) $balanceAfterB->available_qty, 'Tồn khả dụng không được giảm thêm sau lần Post bị chặn.');
        $this->assertSame(0, (int) $balanceAfterB->quantity, 'Tồn tổng không được âm sau lần Post bị chặn — bất biến cốt lõi của WWHN-201.');

        // Document B phải giữ nguyên SCANNING (không POSTED một phần, không đổi trạng thái
        // khi bị chặn) — đúng ngữ nghĩa "atomic": toàn bộ transaction rollback khi guard chặn.
        $this->assertDatabaseHas('stock_documents', ['id' => $docB['document_id'], 'status' => 'SCANNING', 'version' => 2]);
    }

    public function test_replaying_post_issue_with_same_idempotency_key_returns_identical_result_without_creating_a_second_movement(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-IDEMP-001');
        $documentId = $doc['document_id'];

        $postHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn201-idempotent-replay-001'];

        $first = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $postHeaders);
        $first->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $firstMovementIds = $first->json('data.movement_ids');

        $balanceAfterFirst = DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->first();
        $movementCountAfterFirst = DB::table('stock_movements')->count();

        // Replay đúng key: phải trả lại đúng response đã cache (movement_ids giống hệt),
        // không chạy lại business logic, không tạo stock_movement/thay đổi balance lần nữa.
        $second = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $postHeaders);
        $second->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($firstMovementIds, $second->json('data.movement_ids'), 'Replay phải trả về đúng movement_ids đã tạo ở lần Post gốc.');

        $balanceAfterReplay = DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->first();
        $this->assertSame((int) $balanceAfterFirst->available_qty, (int) $balanceAfterReplay->available_qty, 'Replay không được trừ tồn thêm lần nữa.');
        $this->assertSame((int) $balanceAfterFirst->quantity, (int) $balanceAfterReplay->quantity);
        $this->assertSame($movementCountAfterFirst, DB::table('stock_movements')->count(), 'Replay không được tạo thêm stock_movement.');
    }

    /**
     * Dựng document SCANNING đầy đủ (match item, RFID, label PRINTED) từ một
     * outbound_request có sẵn — biến thể của readyToPostIssueDocument() cho phép truyền
     * warehouse/request/sku dùng chung giữa 2 document để mô phỏng race trên cùng balance.
     */
    private function scanReadyOutboundDocument(array $headers, string $warehouseId, string $requestId, string $skuId): array
    {
        $created = $this->outboundDocumentFixture((string) $warehouseId, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'serial_number' => 'SN-'.Str::upper(Str::random(8)),
            'item_status' => 'IN_STOCK',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedRfidCodeFor($itemId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->markLabelsPrinted($documentId);

        return ['document_id' => $documentId, 'item_id' => $itemId];
    }

    private function seedRfidCodeFor(string $itemId): void
    {
        $physicalCodeId = (string) Str::uuid();
        $codeValue = 'RFID-'.Str::upper(Str::random(10));
        DB::table('physical_codes')->insert([
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'RFID',
            'hardware_uid' => 'HW-'.Str::upper(Str::random(10)),
            'status' => 'ACTIVE',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('physical_code')) {
            DB::table('physical_code')->insert([
                'physical_code_id' => $physicalCodeId,
                'code_value' => $codeValue,
                'code_type' => 'ITEM',
                'carrier_type' => 'RFID',
                'assigned_object_type' => 'ITEM',
                'assigned_object_id' => $itemId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
