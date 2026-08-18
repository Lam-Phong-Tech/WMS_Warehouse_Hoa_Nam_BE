<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-201 — ma trận idempotency/concurrency của Post Issue ở TẦNG HTTP.
 *
 * Đóng phần deterministic của `DEV201-P1-01R` và toàn bộ `DEV201-P1-05`.
 *
 * `Wwhn201PostIssueAtomicNonNegativeStockTest` đã phủ hai ô: same-key/same-payload
 * (replay) và shared-stock (không âm tồn). Bốn ô còn lại của register — same-key/
 * different-payload, different-key same-doc, stale If-Match, forced rollback — cùng
 * bảng HTTP↔code của `DEC-OUT-STOCK-HTTP-001` chưa có test HTTP nào chốt. Thiếu
 * chúng thì một lần đổi mã lỗi (ví dụ trả 409 cho thiếu tồn như parent AC1 cũ) sẽ
 * lọt qua CI.
 *
 * Phần race thật (≥2 connection PostgreSQL cùng lúc) nằm ở
 * `scripts/wwhn201-runtime-concurrency.php` — SQLite `:memory:` không chứng minh
 * được contention. Bộ test này chứng minh **hợp đồng phản hồi**, script kia chứng
 * minh **bất biến dưới tranh chấp thật**; hai thứ bổ sung nhau, không thay thế nhau.
 *
 * @see docs/decision-records/DEC-OUT-STOCK-HTTP-001-insufficient-stock-http-status.md
 */
class Wwhn201PostIssueErrorContractTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** §8.2 chỉ công nhận đúng bốn business code cho HTTP 409. */
    private const CONFLICT_CODES_409 = [
        'STATE_CONFLICT',
        'IDEMPOTENCY_CONFLICT',
        'CONCURRENCY_CONFLICT',
        'DOWNSTREAM_DEPENDENCY_EXISTS',
    ];

    /** DEC-OUT-STOCK-HTTP-001: thiếu tồn thuộc lớp 422, không phải 409. */
    private const SHORTAGE_CODES = ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK'];

    /**
     * Ô "same-key / different-payload" của register.
     *
     * Cùng Idempotency-Key nhưng request hash khác phải là 409 IDEMPOTENCY_CONFLICT
     * — KHÔNG được replay snapshot cũ (sẽ trả kết quả của một payload khác) và cũng
     * không được chạy lại business logic (sẽ trừ tồn lần hai).
     */
    public function test_same_idempotency_key_with_different_payload_returns_409_idempotency_conflict_without_second_stock_effect(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-CONTRACT-IDEMP');
        $documentId = $doc['document_id'];
        $key = 'wwhn201-contract-same-key-diff-payload';

        $first = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $first->assertOk()->assertHeader('Idempotent-Replay', 'false');

        $movementsAfterFirst = DB::table('stock_movements')->count();
        $balanceAfterFirst = $this->availableQty($doc['warehouse_id'], $doc['sku_id']);

        // Cùng key, khác body → khác request hash.
        $second = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [
            'note' => 'payload đã đổi so với lần gửi đầu',
        ], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => $key,
        ]);

        $second->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $second->json('error.code'));
        $this->assertSame(
            $movementsAfterFirst,
            DB::table('stock_movements')->count(),
            'Xung đột idempotency không được tạo thêm stock_movement.'
        );
        $this->assertSame(
            $balanceAfterFirst,
            $this->availableQty($doc['warehouse_id'], $doc['sku_id']),
            'Xung đột idempotency không được đụng vào tồn.'
        );
    }

    /**
     * Ô "different-key / same-doc".
     *
     * Key mới nghĩa là "một thao tác mới", nên KHÔNG được replay. Phiếu đã POSTED thì
     * state guard phải chặn bằng 409 STATE_CONFLICT — đây chính là lớp bảo vệ cuối
     * chống double-post khi FE lỡ sinh key mới lúc bấm lại.
     */
    public function test_different_idempotency_key_on_already_posted_document_returns_409_state_conflict(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-CONTRACT-DIFFKEY');
        $documentId = $doc['document_id'];

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn201-contract-diffkey-a',
        ])->assertOk();

        $movementsAfterFirst = DB::table('stock_movements')->count();
        $balanceAfterFirst = $this->availableQty($doc['warehouse_id'], $doc['sku_id']);

        $second = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'wwhn201-contract-diffkey-b',
        ]);

        $second->assertStatus(409);
        $this->assertSame('STATE_CONFLICT', $second->json('error.code'));
        $this->assertSame($movementsAfterFirst, DB::table('stock_movements')->count());
        $this->assertSame($balanceAfterFirst, $this->availableQty($doc['warehouse_id'], $doc['sku_id']));
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'POSTED']);
    }

    /**
     * Ô "stale If-Match" + kiểm tra chống ăn mòn: sau 412, thao tác gửi lại bằng ĐÚNG
     * key cũ và version mới phải thành công đúng một lần.
     */
    public function test_stale_if_match_returns_412_and_the_same_key_still_posts_exactly_once_after_reload(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-CONTRACT-STALE');
        $documentId = $doc['document_id'];
        $key = 'wwhn201-contract-stale-if-match';

        $movementsBefore = DB::table('stock_movements')->count();

        // version thật đang là 2 (1 lần scan). Gửi If-Match=1 là bản cũ.
        $stale = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => $key,
        ]);
        $stale->assertStatus(412);
        $this->assertSame('STALE_VERSION', $stale->json('error.code'));
        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            '412 phải là no-write tuyệt đối theo §8.2.'
        );
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'SCANNING', 'version' => 2]);

        // Request lỗi KHÔNG được cache: gửi lại đúng key cũ với version đúng phải chạy
        // thật và thành công. Nếu store cache lỗi thì FE sẽ kẹt vĩnh viễn ở 412.
        $retry = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $retry->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $this->assertSame($movementsBefore + 1, DB::table('stock_movements')->count(), 'Post phải xảy ra đúng một lần.');
    }

    /**
     * Ô "forced rollback": guard nghiệp vụ chặn giữa chừng phải rollback TOÀN BỘ —
     * không movement, không đổi tồn, không đổi status, và đặc biệt không đóng băng
     * key vào trạng thái đã-thành-công.
     */
    public function test_business_guard_rejection_rolls_back_everything_and_does_not_poison_the_idempotency_key(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-CONTRACT-ROLLBACK');
        $documentId = $doc['document_id'];
        $key = 'wwhn201-contract-forced-rollback';

        // Rút tồn xuống 0 SAU khi document đã SCANNING đầy đủ: soft-check lúc scan đã
        // pass, nên guard duy nhất còn lại là mutateBalance() giữa transaction Post.
        DB::table('inventory_balances')
            ->where('warehouse_id', $doc['warehouse_id'])
            ->where('sku_id', $doc['sku_id'])
            ->update(['quantity' => 0, 'on_hand_qty' => 0, 'available_qty' => 0]);

        $movementsBefore = DB::table('stock_movements')->count();

        $blocked = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);

        $blocked->assertStatus(422);
        $this->assertContains($blocked->json('error.code'), self::SHORTAGE_CODES);
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), 'Rollback phải xoá sạch movement của lần Post bị chặn.');
        $this->assertSame(0, $this->availableQty($doc['warehouse_id'], $doc['sku_id']), 'Tồn không được âm.');
        // version đã được tăng trong cùng transaction với Post, nên rollback phải trả
        // nó về đúng 2 — nếu version trôi lên 3 thì lần gửi lại của FE sẽ ăn 412 oan.
        $this->assertDatabaseHas('stock_documents', ['id' => $documentId, 'status' => 'SCANNING', 'version' => 2]);

        // Key không bị "đầu độc": sau khi bổ sung tồn, gửi lại đúng key cũ phải chạy thật.
        DB::table('inventory_balances')
            ->where('warehouse_id', $doc['warehouse_id'])
            ->where('sku_id', $doc['sku_id'])
            ->update(['quantity' => 1, 'on_hand_qty' => 1, 'available_qty' => 1]);

        $retry = $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $retry->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $this->assertSame($movementsBefore + 1, DB::table('stock_movements')->count());
    }

    /**
     * DEV201-P1-05 — chống hồi quy trực tiếp: thiếu tồn phải là 422 và mã của nó
     * tuyệt đối không được nằm trong nhóm 409 của §8.2.
     */
    public function test_insufficient_stock_is_422_and_never_409(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-CONTRACT-422');

        DB::table('inventory_balances')
            ->where('warehouse_id', $doc['warehouse_id'])
            ->where('sku_id', $doc['sku_id'])
            ->update(['quantity' => 0, 'on_hand_qty' => 0, 'available_qty' => 0]);

        $response = $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn201-contract-422-check',
        ]);

        $code = $response->json('error.code');
        $this->assertSame(
            422,
            $response->getStatusCode(),
            'DEC-OUT-STOCK-HTTP-001: parent AC1 cũ ghi 409 là sai — §8.2 xếp INSUFFICIENT_STOCK vào lớp 422.'
        );
        $this->assertContains($code, self::SHORTAGE_CODES);
        $this->assertNotContains(
            $code,
            self::CONFLICT_CODES_409,
            'Mã thiếu tồn không được trùng nhóm 409; 409 mang nghĩa "reload rồi thử lại", còn reload không sinh ra tồn.'
        );
    }

    /** Thiếu Idempotency-Key trên lệnh đổi tồn là 428 theo §8.2, không phải 400/422. */
    public function test_missing_idempotency_key_returns_428_precondition_required(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocument($headers, 'WWHN201-CONTRACT-428');
        $movementsBefore = DB::table('stock_movements')->count();

        $response = $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
        ]);

        $response->assertStatus(428);
        $this->assertSame('PRECONDITION_REQUIRED', $response->json('error.code'));
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    private function availableQty(string $warehouseId, string $skuId): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->value('available_qty');
    }
}
