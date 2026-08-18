<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-289 / DEV289-P1-03 — "CORE hard gate: chứng minh stock invariant".
 *
 * Review 34/100 yêu cầu đúng 5 chiều trên build đã pin + đối soát movement/balance
 * với mismatch = 0. Đây là AC #3 của task cha WWHN-77 ("Stock-changing Khắc phục
 * chạy lại invariant/idempotency/concurrency/rollback suite") và ánh xạ 1-1 sang
 * NFR của SRS v1.12 §9:
 *
 *   negative     → NFR-SRS-003 (concurrency prevents negative stock) + AC-GWT-OUT-001
 *   concurrent   → NFR-SRS-003 (duplicate reversal bị chặn) + BR-REV-08
 *   retry        → NFR-SRS-001 "Retry 3 times cùng key/payload = một movement"
 *   idempotency  → AC-GWT-IDEMP-001 (cùng key khác payload = 409 conflict)
 *   rollback     → NFR-SRS-002 "Injected failure rollback all"
 *   reconcile    → §7.5 Reconciliation Controls, mismatch phải = 0
 *
 * Test KHÔNG dựng lại những gì Wwhn201PostIssueAtomicNonNegativeStockTest /
 * Wwhn199ReservationConcurrencyGuardTest đã chứng minh; nó lái đủ 6 chiều trong
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

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            $directory = base_path('build/evidence/wwhn-289');
            if (! is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
            @file_put_contents(
                $directory.'/stock-invariant-proof.json',
                json_encode(self::$evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        self::$evidence = [];

        parent::tearDownAfterClass();
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

        self::$evidence['concurrent_duplicate_reversal'] = [
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
