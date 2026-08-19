<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-234/235 — đóng khoảng trống **G-01** (`TC-P1-016`, SRS §13.3
 * `NFR-SRS-002` "Injected failure rollback all").
 *
 * `QA1_FIXED_BUILD_REPORT_ROUND2.md` ghi nhận: baseline chỉ chứng minh rollback
 * POST_ISSUE gián tiếp qua nhánh `INSUFFICIENT_STOCK` (lỗi nghiệp vụ trước khi ghi
 * bất kỳ gì) — không có test tiêm lỗi hạ tầng SAU khi `stock_movements` đã INSERT
 * rồi assert toàn bộ transaction (document/movement/balance/posting_event) quay
 * lại nguyên trạng. `Wwhn289StockInvariantProofTest::test_rollback_dimension_...`
 * đã làm đúng việc này cho Post Receipt; đây là bản tương đương cho Post Issue.
 *
 * Post Issue chạy qua `AlignedOutboundFlowController@postIssue`, KHÔNG phải
 * `CurrentBaselineStockFlowController` (đã xác nhận bằng `route:list --json`,
 * xem [[project-wwhn234]]) — nó gọi `CurrentBaselineStockService::postStockDocument()`
 * bên trong cùng một `DB::transaction()` với việc bump version/status của
 * `stock_documents`, nên injected failure ngay sau INSERT `stock_movements` phải
 * cuốn theo cả version bump đó, không chỉ riêng phần ghi tồn.
 */
class Wwhn234PostIssueRollbackProofTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_g01_injected_failure_after_movement_insert_rolls_back_document_movement_balance_and_posting_event(): void
    {
        $headers = $this->superAdminHeaders();
        $document = $this->readyToPostIssueDocument($headers, 'WWHN234-G01-ROLLBACK');
        $endpoint = "/api/v1/outbound-documents/{$document['document_id']}/post-issue";
        $idempotencyKey = 'wwhn234-g01-rollback-key-001';

        $movementsBefore = DB::table('stock_movements')->count();
        $balanceBefore = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $document['warehouse_id'])
            ->where('sku_id', $document['sku_id'])
            ->value('quantity');
        $postingEventsBefore = DB::table('posting_event')->count();

        $injected = false;
        Event::listen(function (QueryExecuted $event) use (&$injected): void {
            if ($injected) {
                return;
            }
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'insert into') && str_contains($sql, 'stock_movements')) {
                $injected = true;
                throw new RuntimeException('WWHN-234 G-01 injected infrastructure failure after movement insert (Post Issue)');
            }
        });

        $failed = $this->postJson($endpoint, [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => $idempotencyKey,
        ]);

        Event::forget(QueryExecuted::class);

        $this->assertTrue($injected, 'Lỗi phải được tiêm đúng vào lệnh INSERT stock_movements.');
        $failed->assertStatus(500);
        $this->assertSame('INTERNAL_SERVER_ERROR', $failed->json('error.code'));
        $body = $failed->getContent();
        foreach (['insert into', 'stock_movements', 'RuntimeException', '#0 ', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase(
                $leak,
                $body,
                "Phản hồi 500 không được lộ chi tiết nội bộ: {$leak}"
            );
        }

        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            'Injected failure phải rollback movement.'
        );
        $this->assertSame(
            $balanceBefore,
            (int) DB::table('inventory_balances')
                ->where('warehouse_id', $document['warehouse_id'])
                ->where('sku_id', $document['sku_id'])
                ->value('quantity'),
            'Injected failure phải rollback balance.'
        );
        $this->assertSame(
            $postingEventsBefore,
            DB::table('posting_event')->count(),
            'Injected failure phải rollback luôn posting_event (cùng transaction với movement/balance).'
        );
        // Version bump của stock_documents (bước đầu tiên trong cùng transaction,
        // TRƯỚC cả postStockDocument) cũng phải cuốn theo — không chỉ riêng phần tồn.
        $this->assertDatabaseHas('stock_documents', [
            'id' => $document['document_id'],
            'status' => 'SCANNING',
            'version' => 2,
        ]);

        // Sau sự cố, lease idempotency phải được nhả (IdempotencyStore::run() xoá
        // lease trong catch Throwable) — cùng key vẫn Post lại được, không kẹt vĩnh viễn.
        $recovered = $this->postJson($endpoint, [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => $idempotencyKey,
        ]);
        $recovered->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.replayed', false);

        $this->assertSame($movementsBefore + 1, DB::table('stock_movements')->count());
        $this->assertSame($postingEventsBefore + 1, DB::table('posting_event')->count());
        $this->assertDatabaseHas('stock_documents', [
            'id' => $document['document_id'],
            'status' => 'POSTED',
            'version' => 3,
        ]);
    }
}
