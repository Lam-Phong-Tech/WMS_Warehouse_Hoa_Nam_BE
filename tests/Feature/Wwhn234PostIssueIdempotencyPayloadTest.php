<?php

namespace Tests\Feature;

use App\Support\ConflictCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-234 (DEV234-P1-02 · F-08) — đóng khoảng trống **TC-P1-014**.
 *
 * Ma trận SRS §13.3 yêu cầu `SAME_KEY_DIFF_PAYLOAD` cho cả bốn action đổi tồn, nhưng trên
 * baseline chỉ POST_RECEIPT (`TC-P1-006`) và POST_REVERSAL (`TC-P1-030`) có test. POST_ISSUE
 * bị bỏ sót vì endpoint này **không đọc body** — nhìn qua thì tưởng "không có payload nào
 * để mà khác nhau".
 *
 * Đó chính là chỗ nguy hiểm. `IdempotencyStore::requestHash()` băm `method + path + query +
 * toàn bộ body`, nên client gửi kèm field lạ vẫn tạo hash khác. Nếu comparator nới lỏng cho
 * endpoint không-body thì hai lần Post khác nhau sẽ replay lẫn nhau, và một chứng từ bị coi
 * là đã Post trong khi tồn chưa hề bị trừ. Test này khoá hành vi đó lại.
 *
 * Kỳ vọng theo AC-GWT-IDEMP-001: `409 IDEMPOTENCY_CONFLICT`, không có write thứ hai.
 */
class Wwhn234PostIssueIdempotencyPayloadTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_post_issue_with_same_key_and_different_payload_conflicts_without_second_write(): void
    {
        $headers = $this->superAdminHeaders();
        $document = $this->readyToPostIssueDocument($headers, 'WWHN234-IDEMP-001');
        $endpoint = "/api/v1/outbound-documents/{$document['document_id']}/post-issue";
        $key = 'wwhn234-post-issue-payload-key';

        $this->postJson($endpoint, [], $headers + ['If-Match' => '2', 'Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.status', 'POSTED');

        $movements = DB::table('stock_movements')->count();
        $balance = $this->onHand($document);

        // Cùng key nhưng body khác ⇒ hash khác ⇒ phải là conflict, không phải replay.
        $this->postJson(
            $endpoint,
            ['note' => 'payload khac so voi lan dau'],
            $headers + ['If-Match' => '3', 'Idempotency-Key' => $key]
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', ConflictCode::IDEMPOTENCY);

        $this->assertSame(
            $movements,
            DB::table('stock_movements')->count(),
            'Request xung đột không được tạo movement thứ hai.'
        );
        $this->assertSame(
            $balance,
            $this->onHand($document),
            'Request xung đột không được đổi tồn.'
        );
    }

    public function test_post_issue_replay_is_decided_by_idempotency_key_before_optimistic_lock(): void
    {
        $headers = $this->superAdminHeaders();
        $document = $this->readyToPostIssueDocument($headers, 'WWHN234-IDEMP-002');
        $endpoint = "/api/v1/outbound-documents/{$document['document_id']}/post-issue";
        $key = 'wwhn234-post-issue-replay-key';

        $this->postJson($endpoint, [], $headers + ['If-Match' => '2', 'Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.replayed', false);

        $movements = DB::table('stock_movements')->count();
        $balance = $this->onHand($document);

        // If-Match của lần retry cố tình để nguyên giá trị cũ (đã stale sau lần Post đầu).
        // Replay phải được quyết định bởi Idempotency-Key **trước** khi chạm optimistic
        // lock; nếu không, một retry hợp lệ sau timeout mạng sẽ nhận 412 thay vì nhận lại
        // kết quả cũ, và client sẽ tưởng lệnh Post chưa chạy.
        $this->postJson($endpoint, [], $headers + ['If-Match' => '2', 'Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.status', 'POSTED')
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame($balance, $this->onHand($document));
    }

    /** @param array{sku_id: string, warehouse_id: mixed} $document */
    private function onHand(array $document): int
    {
        return (int) DB::table('inventory_balances')
            ->where('sku_id', $document['sku_id'])
            ->where('warehouse_id', (string) $document['warehouse_id'])
            ->value('on_hand_qty');
    }
}
