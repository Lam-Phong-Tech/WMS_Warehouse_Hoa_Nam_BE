<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WWHN-198 / DEV198-P1-02R — gate cho bằng chứng race reservation outbound đã thực thi.
 *
 * Cố tình KHÔNG dùng RefreshDatabase/DatabaseTransactions: bằng chứng đòi hỏi các
 * hàng đã commit mà những OS process độc lập trên backend PostgreSQL riêng nhìn
 * thấy được. Orchestrator tự dựng và tự assert fixture cô lập của nó.
 *
 * Cùng pattern với Wwhn219RuntimeConcurrencyTest (WWHN-219) để CI chỉ có một cách
 * chạy bằng chứng concurrency, không đẻ thêm cơ chế thứ hai.
 */
class Wwhn198OutboundReservationRuntimeRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race reservation outbound cần PostgreSQL thật; sqlite chỉ chạy được guard tĩnh ở Wwhn46OutboundPickScanDedupTest.');
        }

        if (getenv('WWHN198_RUNTIME_RACE') !== '1') {
            $this->markTestSkipped('Đặt WWHN198_RUNTIME_RACE=1 để chạy race đa process (CI hard gate làm việc này).');
        }
    }

    public function test_multi_connection_reservation_races_keep_one_winner_and_no_double_active_match(): void
    {
        $evidenceDir = getenv('WWHN198_EVIDENCE_DIR') ?: 'storage/app/wwhn-198-evidence';
        $script = base_path('scripts/wwhn198-outbound-reservation-race.php');

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($evidenceDir).' 2>&1';
        exec($command, $output, $exitCode);
        $rendered = implode("\n", $output);

        $summaryPath = base_path($evidenceDir.'/reservation-race-summary.json');
        $this->assertFileExists($summaryPath, "Orchestrator không sinh summary.\n".$rendered);

        $summary = json_decode((string) file_get_contents($summaryPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, "Race reservation outbound thất bại.\n".$rendered);
        $this->assertSame('PASS', $summary['status']);
        $this->assertSame([], $summary['failed_scenarios']);

        $this->assertSame(
            ['cross_request_same_item', 'same_request_same_item', 'unique_index_guard'],
            array_keys($summary['scenarios']),
            'Register DEV198-P1-02R yêu cầu đủ cả same item/cross request lẫn bằng chứng index.'
        );

        foreach ($summary['scenarios'] as $name => $scenario) {
            $this->assertTrue($scenario['passed'], "Scenario {$name} fail: ".json_encode($scenario['checks']));
            $this->assertSame(
                2,
                $scenario['distinct_backend_pid_count'],
                "Scenario {$name} không dùng hai backend PostgreSQL tách rời."
            );
            $this->assertTrue(
                $scenario['real_contention_proven'],
                "Scenario {$name} không quan sát được contention thật — race sẽ là bằng chứng rỗng."
            );
        }

        // Không double ACTIVE match ở cả ba kịch bản là invariant cốt lõi của WWHN-46.
        foreach (['cross_request_same_item', 'same_request_same_item', 'unique_index_guard'] as $name) {
            $this->assertSame(1, $summary['scenarios'][$name]['active_match_count'], "Scenario {$name} tạo ra nhiều hơn 1 match ACTIVE.");
        }

        // Reconciliation: scan phải stock-neutral (chỉ Post Issue mới trừ tồn).
        $this->assertSame(
            $summary['scenarios']['cross_request_same_item']['balance_before'],
            $summary['scenarios']['cross_request_same_item']['balance_after'],
            'Scan không được chạm tồn kho.'
        );
        $this->assertSame(0, $summary['scenarios']['cross_request_same_item']['movement_count']);

        // DEV198-P2-01: DUPLICATE_ITEM_MATCH là hàng rào thật khi hai request đồng thời
        // cùng vượt qua controller — bằng chứng để KHÔNG xoá guard này.
        $this->assertTrue(
            $summary['scenarios']['same_request_same_item']['checks']['conflict_is_duplicate_item_match'],
            'DUPLICATE_ITEM_MATCH phải là mã lỗi của loser trong race cùng dòng — nếu khác, disposition P2-01 phải xem lại.'
        );

        // Index là hàng rào cuối cùng kể cả khi tầng application bị bypass.
        $this->assertNotSame([], $summary['scenarios']['unique_index_guard']['index_definitions']);
        $this->assertTrue($summary['scenarios']['unique_index_guard']['checks']['loser_sqlstate_is_unique_violation']);
    }
}
