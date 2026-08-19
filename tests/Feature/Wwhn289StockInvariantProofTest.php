<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-289 / DEV289-P1-03 — "CORE hard gate: chứng minh stock invariant".
 *
 * Register 73/100 (DEV289-P1-03R) liệt kê đúng bảy chiều QA1 phải chạy lại trên
 * build đã pin: retry, same-key-different-payload, injected rollback, negative
 * stock, true concurrency/race, reversal, reconcile — kèm mismatch = 0. Đây là AC #3
 * của task cha WWHN-77 và ánh xạ 1-1 sang NFR của SRS v1.12 §9:
 *
 *   retry            → NFR-SRS-001 "Retry 3 times cùng key/payload = một movement"
 *   idempotency      → AC-GWT-IDEMP-001 (cùng key khác payload = 409 IDEMPOTENCY_CONFLICT)
 *   rollback         → NFR-SRS-002 "Injected failure rollback all"
 *   negative_stock   → NFR-SRS-003 nửa "prevents negative stock" + AC-GWT-OUT-001
 *   true_concurrency → NFR-SRS-003 nửa "parallel requests: one success; others conflict"
 *   reversal         → NFR-SRS-003 "duplicate reversal" + BR-REV-08
 *   reconcile        → §7.5 Reconciliation Controls, mismatch phải = 0
 *
 * LỊCH SỬ SỬA: bản trước docblock này khai có chiều `negative` nhưng KHÔNG tồn tại
 * test method nào chạy nó, và chiều `concurrent` chỉ bấm Reverse hai lần tuần tự —
 * tức bằng chứng cũ tuyên bố nhiều hơn nó chứng minh. Đó chính là lỗ mà review
 * 73/100 chỉ ra, và hai method `..._negative_stock_dimension_...` /
 * `..._true_concurrency_dimension_...` bên dưới là phần bù.
 *
 * Test KHÔNG dựng lại những gì Wwhn201PostIssueAtomicNonNegativeStockTest /
 * Wwhn199ReservationConcurrencyGuardTest đã chứng minh; nó lái đủ bảy chiều trong
 * MỘT vòng đời nghiệp vụ thật (inbound → outbound → reversal) rồi ghi ra evidence
 * máy đọc được `build/evidence/wwhn-289/stock-invariant-proof.json` để QA1/reviewer
 * đối chiếu thay vì phải tin lời DEV.
 */
class Wwhn289StockInvariantProofTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    /**
     * DEV289-P1-02R — bản trước KHÔNG bao giờ ghi được file này và sự cố bị che kín:
     * `tearDownAfterClass()` chạy SAU khi Laravel đã set `Container::setInstance(null)`,
     * nên `base_path()` ném Error; hai toán tử `@` phía dưới lại nuốt sạch dấu vết. Hệ
     * quả là CI copy `build/evidence/wwhn-289/*.json` từ file ĐÃ COMMIT rồi đọc
     * `reconcile.mismatch_count` trong đó — tức cổng tự xác nhận bằng một artifact tĩnh
     * chứ không phải kết quả của lần chạy đang xét. Đó đúng là "evidence chain chưa sạch".
     *
     * Sửa: đường dẫn suy ra từ __DIR__ (không cần container) và ghi FAIL-CLOSED —
     * không ghi được thì ném, để cổng đỏ thay vì âm thầm giữ số liệu cũ.
     */
    public static function tearDownAfterClass(): void
    {
        self::flushEvidence('stock-invariant-proof.json', self::$evidence);
        self::$evidence = [];

        parent::tearDownAfterClass();
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private static function flushEvidence(string $filename, array $evidence): void
    {
        if ($evidence === []) {
            return;
        }

        $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-289';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không tạo được thư mục evidence: {$directory}");
        }

        $path = $directory.'/'.$filename;
        $written = file_put_contents(
            $path,
            json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($written === false) {
            throw new RuntimeException("Không ghi được evidence: {$path}");
        }
    }

    /**
     * NFR-SRS-001 / TC-NFR-001: "Retry 3 times cùng key/payload = một movement/balance change".
     */
    public function test_retry_dimension_three_identical_post_receipts_produce_exactly_one_movement(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->preparedReceipt($headers, 'WWHN289-RETRY-01', 5);

        $postHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn289-retry-key-001'];
        $responses = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->postJson(
                "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
                [],
                $postHeaders
            );
            $response->assertOk()->assertJsonPath('data.status', 'POSTED');
            $responses[] = [
                'attempt' => $attempt,
                'http_status' => $response->getStatusCode(),
                'idempotent_replay_header' => $response->headers->get('Idempotent-Replay'),
                'replayed_in_body' => $response->json('data.replayed'),
            ];
        }

        $this->assertSame('false', $responses[0]['idempotent_replay_header'], 'Lần Post đầu tiên không phải replay.');
        $this->assertSame('true', $responses[1]['idempotent_replay_header'], 'Lần 2 cùng key phải là replay.');
        $this->assertSame('true', $responses[2]['idempotent_replay_header'], 'Lần 3 cùng key phải là replay.');

        $movements = DB::table('stock_movements')
            ->where('stock_document_id', $fixture['document_id'])
            ->get();
        $this->assertCount(1, $movements, 'Retry 3 lần cùng key/payload chỉ được sinh đúng 1 stock_movement.');

        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->first();
        $this->assertSame(5, (int) $balance->quantity, 'Balance chỉ được cộng đúng 1 lần cho 3 retry.');
        $this->assertSame(5, (int) $balance->available_qty);

        self::$evidence['retry'] = [
            'nfr' => 'NFR-SRS-001 / TC-NFR-001',
            'attempts' => $responses,
            'movement_count' => $movements->count(),
            'balance_quantity' => (int) $balance->quantity,
            'verdict' => 'PASS',
        ];
    }

    /**
     * AC-GWT-IDEMP-001: cùng Idempotency-Key nhưng payload khác phải là conflict,
     * tuyệt đối không được ghi stock effect thứ hai.
     */
    public function test_idempotency_dimension_same_key_with_different_payload_is_rejected(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->postedReceipt($headers, 'WWHN289-IDEMP-A', 6);

        $sharedKey = 'wwhn289-idempotency-conflict-001';
        $reverseUrl = "/api/v1/inbound-documents/{$fixture['document_id']}/reverse";

        // H1: đảo 2/6 — commit thành công, đây là "stock action đã commit bằng key
        // IK-001 và payload hash H1" của AC-GWT-IDEMP-001.
        $h1 = $this->postJson($reverseUrl, [
            'reason' => 'WWHN-289 idempotency H1',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 2]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => $sharedKey]);
        $h1->assertOk();

        $movementsAfterH1 = DB::table('stock_movements')->count();
        $balanceAfterH1 = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');

        // Retry đúng H1 → replay, không đổi stock/movement lần hai.
        $replay = $this->postJson($reverseUrl, [
            'reason' => 'WWHN-289 idempotency H1',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 2]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => $sharedKey]);
        $replay->assertOk();
        $this->assertSame(
            $movementsAfterH1,
            DB::table('stock_movements')->count(),
            'Retry cùng key + cùng payload phải là replay, không ghi stock effect lần hai.'
        );

        // H2: cùng key IK-001, payload hash khác (quantity 3) → 409 IDEMPOTENCY_CONFLICT
        // đúng theo §8.2 + AC-GWT-IDEMP-001 + TC-P1-030.
        $h2 = $this->postJson($reverseUrl, [
            'reason' => 'WWHN-289 idempotency H2',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 3]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => $sharedKey]);

        $h2->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $h2->json('error.code'));
        $this->assertSame('IDEMPOTENCY_CONFLICT', $h2->json('error_code'));

        $this->assertSame(
            $movementsAfterH1,
            DB::table('stock_movements')->count(),
            'Conflict không được sinh thêm stock_movement.'
        );
        $this->assertSame(
            $balanceAfterH1,
            (int) DB::table('inventory_balances')
                ->where('warehouse_id', $fixture['warehouse_id'])
                ->where('sku_id', $fixture['sku_id'])
                ->value('quantity'),
            'Conflict không được đổi tồn.'
        );

        self::$evidence['idempotency_conflict'] = [
            'ac' => 'AC-GWT-IDEMP-001 / TC-P1-030',
            'replay_same_payload_http' => $replay->getStatusCode(),
            'conflict_http' => 409,
            'conflict_error_code' => $h2->json('error.code'),
            'movement_count_unchanged' => true,
            'balance_unchanged' => true,
            'verdict' => 'PASS',
        ];
    }

    /**
     * NFR-SRS-002 / TC-NFR-002: "Injected failure rollback all".
     *
     * Lỗi được tiêm ngay SAU khi stock_movements đã INSERT (điểm muộn nhất và nguy
     * hiểm nhất của giao dịch) để chứng minh document + movement + balance cùng
     * rollback, và quan trọng hơn: lease idempotency được nhả nên retry hợp lệ vẫn
     * Post được — nếu lease kẹt thì phiếu sẽ chết vĩnh viễn sau một sự cố hạ tầng.
     */
    public function test_rollback_dimension_injected_failure_rolls_back_document_movement_and_balance(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->preparedReceipt($headers, 'WWHN289-ROLLBACK-01', 3);

        $movementsBefore = DB::table('stock_movements')->count();
        $balanceBefore = DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');

        $injected = false;
        Event::listen(function (QueryExecuted $event) use (&$injected): void {
            if ($injected) {
                return;
            }
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'insert into') && str_contains($sql, 'stock_movements')) {
                $injected = true;
                throw new RuntimeException('WWHN-289 injected infrastructure failure after movement insert');
            }
        });

        $failed = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => 'wwhn289-rollback-key-001',
        ]);

        Event::forget(QueryExecuted::class);

        $this->assertTrue($injected, 'Lỗi phải được tiêm đúng vào lệnh INSERT stock_movements.');
        $failed->assertStatus(500);
        $this->assertSame('INTERNAL_SERVER_ERROR', $failed->json('error.code'));
        // §8.2 + NFR bảo mật: lỗi hệ thống không được lộ stack trace/SQL nội bộ.
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
            (int) $balanceBefore,
            (int) DB::table('inventory_balances')
                ->where('warehouse_id', $fixture['warehouse_id'])
                ->where('sku_id', $fixture['sku_id'])
                ->value('quantity'),
            'Injected failure phải rollback balance.'
        );
        $this->assertDatabaseHas('stock_documents', [
            'id' => $fixture['document_id'],
            'status' => 'SCANNING',
            'version' => 2,
        ]);

        // Sau sự cố, cùng phiếu phải Post lại được (lease idempotency đã nhả).
        $recovered = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => 'wwhn289-rollback-key-001',
        ]);
        $recovered->assertOk()->assertJsonPath('data.status', 'POSTED');

        self::$evidence['rollback'] = [
            'nfr' => 'NFR-SRS-002 / TC-NFR-002',
            'injection_point' => 'INSERT INTO stock_movements',
            'http_status_on_failure' => 500,
            'error_code' => 'INTERNAL_SERVER_ERROR',
            'stack_trace_leaked' => false,
            'movement_rolled_back' => true,
            'balance_rolled_back' => true,
            'document_state_preserved' => 'SCANNING',
            'retry_after_failure_succeeds' => true,
            'verdict' => 'PASS',
        ];
    }

    /**
     * NFR-SRS-003: "Concurrency prevents negative stock and duplicate reversal".
     * Wwhn201 đã khoá vế "không âm tồn"; vế còn thiếu là duplicate reversal.
     */
    public function test_concurrent_dimension_duplicate_reversal_of_the_same_movement_is_blocked(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->postedReceipt($headers, 'WWHN289-DUPREV-01', 5);

        $first = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'WWHN-289 duplicate reversal guard - lần 1',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 5]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'wwhn289-dup-reversal-1']);
        $first->assertOk();

        $balanceAfterFirst = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
        $this->assertSame(0, $balanceAfterFirst);
        $movementsAfterFirst = DB::table('stock_movements')->count();

        // Key khác + payload giống hệt = mô phỏng hai người bấm Reverse song song.
        $second = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'WWHN-289 duplicate reversal guard - lần 2',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 5]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'wwhn289-dup-reversal-2']);

        $this->assertContains(
            $second->getStatusCode(),
            [409, 422],
            'Reversal trùng phải bị chặn bằng conflict/unprocessable, không được ghi thêm stock effect.'
        );
        $this->assertSame(
            $movementsAfterFirst,
            DB::table('stock_movements')->count(),
            'Reversal trùng không được sinh thêm movement.'
        );
        $this->assertSame(
            0,
            (int) DB::table('inventory_balances')
                ->where('warehouse_id', $fixture['warehouse_id'])
                ->where('sku_id', $fixture['sku_id'])
                ->value('quantity'),
            'Tồn không được âm sau reversal trùng.'
        );

        self::$evidence['reversal'] = [
            'nfr' => 'NFR-SRS-003',
            'first_reversal_http' => $first->getStatusCode(),
            'second_reversal_http' => $second->getStatusCode(),
            'second_error_code' => $second->json('error.code'),
            'balance_after' => 0,
            'negative_stock' => false,
            'verdict' => 'PASS',
        ];
    }

    /**
     * §7.5 Reconciliation Controls: sau một vòng đời đầy đủ, ledger (stock_movements)
     * và projection (inventory_balances) phải khớp tuyệt đối — mismatch = 0. Đây là
     * số liệu mà review đòi "movement/balance reconcile ... mismatch=0".
     */
    public function test_reconcile_dimension_ledger_and_balance_have_zero_mismatch_after_full_cycle(): void
    {
        $headers = $this->superAdminHeaders();

        // Vòng đời đầy đủ chỉ bằng stock effect THẬT (không seed balance tay):
        // nhập 5 + nhập 8, rồi đảo 2 của phiếu đầu → ledger có RECEIPT/RECEIPT/
        // REVERSAL trên hai dimension, balance phải khớp tuyệt đối.
        $receipt = $this->postedReceipt($headers, 'WWHN289-RECON-01', 5);
        $this->postedReceipt($headers, 'WWHN289-RECON-02', 8);

        $this->postJson("/api/v1/inbound-documents/{$receipt['document_id']}/reverse", [
            'reason' => 'WWHN-289 reconcile cycle - trả lại 2',
            'lines' => [['movement_id' => $receipt['movement_id'], 'quantity' => 2]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'wwhn289-recon-reverse-001'])->assertOk();

        $report = $this->getJson('/api/v1/reports/inventory-reconciliation', $headers);
        $report->assertOk();
        $summary = $report->json('data.summary');

        $this->assertSame(0, (int) $summary['mismatch_count'], 'Đối soát ledger↔balance phải mismatch = 0.');
        $this->assertSame('PASS', $summary['status']);
        $this->assertGreaterThan(0, (int) $summary['rows_checked'], 'Đối soát phải thực sự kiểm ít nhất một dimension.');

        // Đối soát độc lập bằng SQL thô — không đi qua chính controller đang được
        // kiểm chứng, để reviewer không phải tin một nguồn duy nhất.
        $ledger = DB::table('stock_movements')
            ->select('warehouse_id', 'sku_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_qty')
            ->groupBy('warehouse_id', 'sku_id', 'stock_status')
            ->get();
        $rawMismatches = [];
        foreach ($ledger as $row) {
            $onHand = (int) DB::table('inventory_balances')
                ->where('warehouse_id', $row->warehouse_id)
                ->where('sku_id', $row->sku_id)
                ->where('stock_status', $row->stock_status)
                ->value('on_hand_qty');
            if ($onHand !== (int) $row->ledger_qty) {
                $rawMismatches[] = [
                    'warehouse_id' => $row->warehouse_id,
                    'sku_id' => $row->sku_id,
                    'stock_status' => $row->stock_status,
                    'ledger_qty' => (int) $row->ledger_qty,
                    'on_hand_qty' => $onHand,
                ];
            }
        }
        $this->assertSame([], $rawMismatches, 'Đối soát SQL thô cũng phải mismatch = 0.');

        $balanceAfter = (int) DB::table('inventory_balances')
            ->where('warehouse_id', $receipt['warehouse_id'])
            ->where('sku_id', $receipt['sku_id'])
            ->value('quantity');
        $this->assertSame(3, $balanceAfter, 'Nhập 5 rồi đảo 2 phải còn đúng 3.');

        self::$evidence['reconcile'] = [
            'srs' => '§7.5 Reconciliation Controls',
            'rows_checked' => (int) $summary['rows_checked'],
            'mismatch_count' => (int) $summary['mismatch_count'],
            'report_status' => $summary['status'],
            'independent_sql_mismatches' => $rawMismatches,
            'verdict' => 'PASS',
        ];
        self::$evidence['meta'] = [
            'jira' => 'WWHN-289',
            'register_item' => 'DEV289-P1-03',
            'driver' => DB::getDriverName(),
            'generated_by' => static::class,
        ];
    }

    /**
     * NFR-SRS-003 nửa đầu: "Concurrency prevents **negative stock**". Register 73/100
     * đòi chiều `negative stock` là một chiều RIÊNG — docblock của class trước đây khai
     * có chiều này nhưng KHÔNG có test method nào chạy nó, nên bằng chứng cũ nói nhiều
     * hơn nó chứng minh. Đây là chiều đó, chạy trên đúng guard thật:
     * CurrentBaselineStockService kiểm `after < 0` NGAY TRONG lockForUpdate() rồi mới
     * CAS + insert movement, nên nếu guard trả 422 thì không thể có movement.
     *
     * Ba chân độc lập, cố ý không chân nào tin chân nào:
     *   (1) đảo nhiều hơn số đã nhập  -> bị chặn, tồn không đổi
     *   (2) đảo hết rồi đảo thêm      -> bị chặn tại tồn = 0, không xuống âm
     *   (3) bất biến toàn cục         -> min() của cả ba cột tồn trong DB luôn >= 0
     */
    public function test_negative_stock_dimension_no_path_can_drive_balance_below_zero(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->postedReceipt($headers, 'WWHN289-NEG-01', 4);

        $movementsAfterReceipt = DB::table('stock_movements')->count();
        $attempts = [];

        // (1) Đảo 6 trong khi chỉ nhập 4.
        $overReversal = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'WWHN-289 negative stock guard - dao 6 trong khi chi nhap 4',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 6]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'wwhn289-neg-over-reversal-1']);

        $this->assertContains($overReversal->getStatusCode(), [409, 422], 'Đảo vượt số đã nhập phải bị từ chối.');
        $this->assertSame(4, $this->balanceQuantity($fixture), 'Yêu cầu bị từ chối không được đổi tồn.');
        $this->assertSame(
            $movementsAfterReceipt,
            DB::table('stock_movements')->count(),
            'Yêu cầu bị từ chối không được sinh movement.'
        );
        $attempts[] = [
            'leg' => 'over_reversal_beyond_received',
            'requested_qty' => 6,
            'on_hand_before' => 4,
            'http_status' => $overReversal->getStatusCode(),
            'error_code' => $this->errorCode($overReversal),
            'on_hand_after' => $this->balanceQuantity($fixture),
        ];

        // (2) Đảo đúng 4 (hợp lệ) rồi đảo thêm 1 khi tồn đã bằng 0.
        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'WWHN-289 negative stock guard - dao dung 4',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 4]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'wwhn289-neg-exact-reversal'])->assertOk();
        $this->assertSame(0, $this->balanceQuantity($fixture), 'Đảo hết phải về đúng 0.');

        $movementsAtZero = DB::table('stock_movements')->count();
        $belowZero = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'WWHN-289 negative stock guard - dao them 1 khi ton da 0',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 1]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'wwhn289-neg-below-zero']);

        $this->assertContains($belowZero->getStatusCode(), [409, 422], 'Đảo thêm khi tồn 0 phải bị từ chối.');
        $this->assertSame(0, $this->balanceQuantity($fixture), 'Tồn phải đứng ở 0, không được xuống âm.');
        $this->assertSame(
            $movementsAtZero,
            DB::table('stock_movements')->count(),
            'Lần đảo bị từ chối không được sinh movement.'
        );
        $attempts[] = [
            'leg' => 'reversal_at_zero_balance',
            'requested_qty' => 1,
            'on_hand_before' => 0,
            'http_status' => $belowZero->getStatusCode(),
            'error_code' => $this->errorCode($belowZero),
            'on_hand_after' => 0,
        ];

        // (3) Bất biến toàn cục: không dòng tồn nào trong DB được âm ở bất kỳ cột nào.
        $floor = DB::table('inventory_balances')
            ->selectRaw('MIN(quantity) AS min_quantity, MIN(on_hand_qty) AS min_on_hand, MIN(available_qty) AS min_available')
            ->first();

        $this->assertGreaterThanOrEqual(0, (int) $floor->min_quantity, 'quantity âm là vi phạm NFR-SRS-003.');
        $this->assertGreaterThanOrEqual(0, (int) $floor->min_on_hand, 'on_hand_qty âm là vi phạm NFR-SRS-003.');
        $this->assertGreaterThanOrEqual(0, (int) $floor->min_available, 'available_qty âm là vi phạm NFR-SRS-003.');

        self::$evidence['negative_stock'] = [
            'nfr' => 'NFR-SRS-003 (prevents negative stock) / TC-NFR-003',
            'guard' => 'CurrentBaselineStockService: kiem after<0 ben trong lockForUpdate() truoc CAS va truoc insert stock_movements',
            'attempts' => $attempts,
            'global_floor' => [
                'min_quantity' => (int) $floor->min_quantity,
                'min_on_hand_qty' => (int) $floor->min_on_hand,
                'min_available_qty' => (int) $floor->min_available,
            ],
            'negative_observed' => false,
            'verdict' => 'PASS',
        ];
    }

    /**
     * NFR-SRS-003 nửa sau: "Parallel requests: one success; others conflict/insufficient".
     *
     * Chiều `concurrent` cũ chỉ bấm Reverse hai lần TUẦN TỰ nên không chứng minh được
     * điều gì về race; register 73/100 tách riêng "true concurrency/race" đúng vì vậy.
     * Không thể fork process thật trong một test dùng RefreshDatabase (fixture nằm trong
     * transaction chưa commit nên connection thứ hai không nhìn thấy), nên chiều này
     * chứng minh đúng ba cơ chế mà một race THẬT phải đi qua, mỗi cơ chế bằng trạng thái
     * thật của hệ thống chứ không bằng mock:
     *
     *   (A) khoá bi quan  — đường ghi tồn phát `select ... for update` trên
     *       inventory_balances, tức hai request song song bị tuần tự hoá ở tầng DB chứ
     *       không chỉ có soft-check lúc scan (xem WWHN-199 mục 3).
     *   (B) lease đang bay — row idempotency ở status `processing` với lease chưa hết hạn
     *       là ĐÚNG trạng thái mà request thua nhìn thấy; IdempotencyStore phải trả
     *       409 CONCURRENCY_CONFLICT (không phải IDEMPOTENCY_CONFLICT: payload giống hệt).
     *   (C) thua optimistic lock — If-Match cũ vì người thắng đã đẩy version.
     *
     * Cả ba nhánh đều phải kết thúc bằng "không có stock effect thứ hai".
     */
    public function test_true_concurrency_dimension_race_losers_are_rejected_without_stock_effect(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->preparedReceipt($headers, 'WWHN289-RACE-01', 7);
        $winnerKey = 'wwhn289-race-winner-key';

        // (A) Bắt SQL của chính lần Post thắng để chứng minh có khoá dòng thật.
        $lockStatements = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$lockStatements): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'inventory_balances') && str_contains($sql, 'for update')) {
                $lockStatements[] = $query->sql;
            }
        });

        $winner = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $headers + ['If-Match' => '2', 'Idempotency-Key' => $winnerKey]
        );
        Event::forget(QueryExecuted::class);
        $winner->assertOk()->assertJsonPath('data.status', 'POSTED');

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $this->assertNotEmpty(
                $lockStatements,
                'Trên PostgreSQL đường ghi tồn phải phát select ... for update trên inventory_balances.'
            );
        }

        $movementsAfterWinner = DB::table('stock_movements')->count();
        $balanceAfterWinner = $this->balanceQuantity($fixture);
        $this->assertSame(7, $balanceAfterWinner);

        // (B) Ép chính row idempotency của người thắng về trạng thái "đang bay".
        $leaseRow = DB::table('idempotency_keys')->where('idem_key', $winnerKey)->first();
        $this->assertNotNull($leaseRow, 'Post thắng phải để lại một row idempotency để mô phỏng lease.');
        DB::table('idempotency_keys')->where('idem_key', $winnerKey)->update([
            'status' => 'processing',
            'locked_until' => now()->addMinutes(5),
        ]);

        $leaseLoser = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $headers + ['If-Match' => '2', 'Idempotency-Key' => $winnerKey]
        );

        $this->assertSame(409, $leaseLoser->getStatusCode(), 'Request gặp lease đang bay phải là 409.');
        $this->assertSame(
            'CONCURRENCY_CONFLICT',
            $this->errorCode($leaseLoser),
            'Tranh chấp lease là lớp concurrency (retry được), không phải IDEMPOTENCY_CONFLICT.'
        );
        $this->assertSame($movementsAfterWinner, DB::table('stock_movements')->count());
        $this->assertSame($balanceAfterWinner, $this->balanceQuantity($fixture));

        // Trả lease về trạng thái cũ để nhánh (C) không bị nhánh (B) che mất.
        DB::table('idempotency_keys')->where('idem_key', $winnerKey)->update([
            'status' => $leaseRow->status,
            'locked_until' => $leaseRow->locked_until,
        ]);

        // (C) Người thua optimistic lock: If-Match đã cũ vì người thắng đã đẩy version.
        $staleLoser = $this->postJson(
            "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
            [],
            $headers + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn289-race-stale-key']
        );

        $this->assertContains(
            $staleLoser->getStatusCode(),
            [409, 412],
            'Người thua optimistic lock phải bị chặn bằng 412 STALE_VERSION hoặc 409.'
        );
        $this->assertSame(
            $movementsAfterWinner,
            DB::table('stock_movements')->count(),
            'Người thua không được sinh stock effect thứ hai.'
        );
        $this->assertSame($balanceAfterWinner, $this->balanceQuantity($fixture));

        self::$evidence['true_concurrency'] = [
            'nfr' => 'NFR-SRS-003 (parallel requests: one success, others conflict) / TC-NFR-003',
            'driver' => $driver,
            'row_lock' => [
                'mechanism' => 'DB::table(inventory_balances)->lockForUpdate() trong transaction ghi ton',
                'statements_observed' => count($lockStatements),
                'asserted_on_this_driver' => $driver === 'pgsql',
                'note' => $driver === 'pgsql'
                    ? 'PostgreSQL bien dich lockForUpdate thanh FOR UPDATE nen khoa dong la that.'
                    : 'sqlite khoa toan file nen grammar khong sinh FOR UPDATE; chieu nay chi co gia tri chung minh tren PostgreSQL.',
            ],
            'lease_loser' => [
                'http_status' => $leaseLoser->getStatusCode(),
                'error_code' => $this->errorCode($leaseLoser),
                'second_stock_effect' => false,
            ],
            'optimistic_lock_loser' => [
                'http_status' => $staleLoser->getStatusCode(),
                'error_code' => $this->errorCode($staleLoser),
                'second_stock_effect' => false,
            ],
            'winner_count' => 1,
            'balance_after_all_losers' => $balanceAfterWinner,
            'verdict' => 'PASS',
        ];
    }

    /** Tồn AVAILABLE hiện tại của cặp kho/SKU trong fixture. */
    private function balanceQuantity(array $fixture): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->value('quantity');
    }

    /**
     * Mã lỗi nghiệp vụ đọc được ở cả hai hình dạng envelope (top-level `error_code`
     * của ApiResponse và `error.code` do ApiConventionMiddleware chuẩn hoá).
     */
    private function errorCode(TestResponse $response): ?string
    {
        return $response->json('error.code') ?? $response->json('error_code');
    }

    /**
     * Dựng phiếu nhập COMPONENT đã scan xong (SCANNING, version 2) — chưa Post.
     *
     * @return array{document_id:string,line_id:string,sku_id:string,warehouse_id:string,qty:int}
     */
    private function preparedReceipt(array $headers, string $skuCode, int $qty): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "Component {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-289 stock invariant fixture',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
            'qty' => $qty,
        ];
    }

    /**
     * @return array{document_id:string,line_id:string,sku_id:string,warehouse_id:string,qty:int,movement_id:string}
     */
    private function postedReceipt(array $headers, string $skuCode, int $qty): array
    {
        $fixture = $this->preparedReceipt($headers, $skuCode, $qty);

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn289-'.Str::lower(Str::random(14)),
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $fixture['movement_id'] = (string) DB::table('stock_movements')
            ->where('stock_document_id', $fixture['document_id'])
            ->where('movement_type', 'RECEIPT')
            ->value('id');

        return $fixture;
    }

    private function canonicalWarehouse(): Warehouse
    {
        return $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hòa Nam');
    }
}
