<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WWHN-184 / DEV184-P1-03R — executed concurrency evidence cho Post Receipt.
 *
 * Reviewer: "DEV race chỉ SQLite stale replay". `PostReceiptConcurrencyBehaviorTest`
 * chứng minh *kết quả* của guard bằng cách phát lại hai request HTTP tuần tự trên
 * MỘT connection sqlite — nó không bao giờ đặt hai database backend độc lập vào
 * tranh chấp thật. WWHN-188 đã đóng khoảng trống đó cho `inboundScan()`
 * (stock-neutral); `postReceipt()` — luồng DUY NHẤT ghi
 * `stock_movements`/`inventory_balances` — thì vẫn còn hở đúng chỗ nguy hiểm nhất.
 *
 * Test này chạy `scripts/wwhn184-post-receipt-runtime-concurrency.php`: spawn các
 * tiến trình OS thật (mỗi tiến trình một PostgreSQL backend) đua trên chính
 * production code path, lấy mẫu `pg_locks`/`pg_stat_activity` trong lúc tranh chấp,
 * ghi timestamp thô theo từng worker, và assert trạng thái DB đã commit trước/sau —
 * không chỉ HTTP status.
 *
 * Cố ý KHÔNG dùng RefreshDatabase/DatabaseTransactions: bằng chứng đòi hỏi các hàng
 * đã commit mà tiến trình OS độc lập trên backend khác thực sự nhìn thấy được.
 * Orchestrator tự dựng và tự assert fixture cô lập của nó (token duy nhất mỗi lần
 * chạy), cùng quy ước với gate runtime concurrency của WWHN-188/WWHN-219.
 */
class Wwhn184PostReceiptRuntimeConcurrencyTest extends TestCase
{
    /**
     * Ba kịch bản bắt buộc của DEV184-P1-03R ("≥2 connection race" trên luồng Post).
     *
     * @var list<string>
     */
    private const REQUIRED_SCENARIOS = [
        'same_document_same_version_two_keys',
        'shared_idempotency_key',
        'post_vs_cancel',
    ];

    /** Kịch bản tranh chấp bằng row lock — bắt buộc quan sát được wait thật. */
    private const LOCK_CONTENDED_SCENARIOS = [
        'same_document_same_version_two_keys',
        'post_vs_cancel',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Runtime concurrency evidence requires PostgreSQL; PostReceiptConcurrencyBehaviorTest phủ phần replay tuần tự trên driver khác.');
        }

        if (getenv('WWHN184_RUNTIME_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set WWHN184_RUNTIME_CONCURRENCY=1 để chạy race đa tiến trình (gate PostgreSQL của CI làm việc này).');
        }
    }

    public function test_post_receipt_multi_connection_races_commit_stock_effect_exactly_once(): void
    {
        $evidenceDir = getenv('WWHN184_EVIDENCE_DIR') ?: 'storage/app/wwhn-184-evidence';
        $script = base_path('scripts/wwhn184-post-receipt-runtime-concurrency.php');

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($evidenceDir).' 2>&1';
        exec($command, $output, $exitCode);
        $rendered = implode("\n", $output);

        $summaryPath = base_path($evidenceDir.'/post-receipt-concurrency-summary.json');
        $this->assertFileExists($summaryPath, "Orchestrator không tạo được summary.\n".$rendered);

        $summary = json_decode((string) file_get_contents($summaryPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, "Race Post Receipt thất bại.\n".$rendered);
        $this->assertSame('PASS', $summary['status']);
        $this->assertSame([], $summary['failed_scenarios']);
        $this->assertSame(
            self::REQUIRED_SCENARIOS,
            array_keys($summary['scenarios']),
            'DEV184-P1-03R yêu cầu race double-post (2 key), race chia sẻ Idempotency-Key và race Post vs Cancel.'
        );

        // Bằng chứng closure phải tự mang PostgreSQL version và candidate SHA —
        // reviewer từ chối bằng chứng không gắn được với một bản build cụ thể.
        $this->assertMatchesRegularExpression('/^PostgreSQL \d+/', (string) $summary['postgres_version']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            (string) $summary['git_sha'],
            'Summary phải pin một commit SHA thật, không phải placeholder.'
        );

        foreach ($summary['scenarios'] as $name => $scenario) {
            $this->assertTrue($scenario['passed'], "Scenario {$name} failed: ".json_encode($scenario['checks']));
            $this->assertSame(
                2,
                $scenario['distinct_backend_pid_count'],
                "Scenario {$name} không dùng hai PostgreSQL backend riêng biệt."
            );

            if (in_array($name, self::LOCK_CONTENDED_SCENARIOS, true)) {
                $this->assertTrue(
                    $scenario['real_contention_proven'],
                    "Scenario {$name} không quan sát được lock contention thật; race sẽ là vô nghĩa."
                );
            }

            // Timestamp thô: hai tiến trình phải thực sự chồng lấn thời gian, không
            // phải chạy nối tiếp rồi gọi là "race".
            $this->assertTrue($scenario['timing']['measurable'], "Scenario {$name} không ghi được timestamp thô theo worker.");
            $this->assertTrue(
                $scenario['timing']['workers_in_flight_simultaneously'],
                "Scenario {$name}: hai worker không hề chồng lấn trong wall-clock."
            );

            // DB before/after phải có thật để reviewer đối chiếu, không chỉ verdict.
            $this->assertArrayHasKey('db_before', $scenario);
            $this->assertArrayHasKey('db_after', $scenario);
        }

        // Bất biến quan trọng nhất của luồng nhập: dù đua kiểu gì, tồn cũng chỉ được
        // tăng đúng một lần và chỉ sinh đúng một movement cho mỗi phiếu đã POSTED.
        $doublePost = $summary['scenarios']['same_document_same_version_two_keys'];
        $this->assertSame('POSTED', $doublePost['db_after']['document_status']);
        $this->assertSame(1, $doublePost['db_after']['movements_for_document']);
        $this->assertSame(
            $doublePost['db_before']['on_hand_qty'] + 4,
            $doublePost['db_after']['on_hand_qty'],
            'Hai writer cùng Post một phiếu chỉ được tăng tồn đúng một lần (REQ-SRS-008).'
        );

        $sharedKey = $summary['scenarios']['shared_idempotency_key'];
        $this->assertSame(1, $sharedKey['db_after']['movements_for_document']);
        $this->assertTrue(
            $sharedKey['checks']['shared_key_stored_once'],
            'Cùng một Idempotency-Key chỉ được lưu đúng một hàng lease.'
        );

        $postVsCancel = $summary['scenarios']['post_vs_cancel'];
        $this->assertContains(
            $postVsCancel['final_status'],
            ['POSTED', 'CANCELLED'],
            'Post đua Cancel phải kết thúc ở đúng một trạng thái terminal, không phải trạng thái lai.'
        );
        $this->assertTrue(
            $postVsCancel['checks']['stock_effect_matches_winning_transition'],
            'Stock effect phải khớp đúng transition thắng: POSTED thì tăng tồn, CANCELLED thì tồn không đổi.'
        );
    }
}
