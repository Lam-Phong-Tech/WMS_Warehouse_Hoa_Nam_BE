<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Executed concurrency evidence for WWHN-188 (HN-IN-002, BE1) — closes reviewer
 * findings DEV188-P1-02R2 ("Chạy actual race bằng >=2 DB connections/processes:
 * same doc/version, same line, same item/different docs; chứng minh 1 winner,
 * loser rollback/no side effect") and the runtime half of DEV188-P1-03R2
 * (DEC-SCAN-IDEMP-001 phải đứng vững dưới race thật, không chỉ trong test tuần tự).
 *
 * `InboundScanConcurrencyBehaviorTest` proves the guard's *outcome* by replaying two
 * HTTP requests sequentially on a single SQLite connection — it never puts two
 * independent database backends in real contention. This test instead runs
 * `scripts/wwhn188-inbound-scan-runtime-concurrency.php`, which spawns real OS
 * processes (one PostgreSQL backend connection each) that call the production
 * `CurrentBaselineStockFlowController::inboundScan()` concurrently, samples
 * `pg_locks`/`pg_stat_activity` while they contend, records raw per-worker
 * timestamps, and asserts committed DB state before/after — not HTTP status alone.
 *
 * Deliberately does NOT use RefreshDatabase/DatabaseTransactions: the proof requires
 * committed rows that independent OS processes on separate PostgreSQL backends can
 * actually see. The orchestrator builds and asserts its own isolated fixtures
 * (unique per run token), same convention as the WWHN-219 runtime concurrency gate.
 */
class InboundScanRuntimeConcurrencyTest extends TestCase
{
    /**
     * Bốn kịch bản bắt buộc. Ba kịch bản đầu là yêu cầu nguyên văn của
     * DEV188-P1-02R2; kịch bản thứ tư chứng minh DEC-SCAN-IDEMP-001 dưới race thật.
     *
     * @var list<string>
     */
    private const REQUIRED_SCENARIOS = [
        'same_document_same_line',
        'same_document_different_line',
        'same_item_different_document',
        'same_idempotency_key_quantity_mode',
    ];

    /** Kịch bản tranh chấp bằng row lock — bắt buộc phải quan sát được wait thật. */
    private const LOCK_CONTENDED_SCENARIOS = [
        'same_document_same_line',
        'same_document_different_line',
        'same_item_different_document',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Runtime concurrency evidence requires PostgreSQL; InboundScanConcurrencyBehaviorTest covers the sequential-replay proof on other drivers.');
        }

        if (getenv('WWHN188_RUNTIME_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set WWHN188_RUNTIME_CONCURRENCY=1 to execute the multi-process race (CI PostgreSQL gate does this).');
        }
    }

    public function test_inbound_scan_multi_connection_races_reject_exactly_one_writer_with_no_side_effect(): void
    {
        $evidenceDir = getenv('WWHN188_EVIDENCE_DIR') ?: 'storage/app/wwhn-188-evidence';
        $script = base_path('scripts/wwhn188-inbound-scan-runtime-concurrency.php');

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($evidenceDir).' 2>&1';
        exec($command, $output, $exitCode);
        $rendered = implode("\n", $output);

        $summaryPath = base_path($evidenceDir.'/runtime-concurrency-summary.json');
        $this->assertFileExists($summaryPath, "Orchestrator produced no summary.\n".$rendered);

        $summary = json_decode((string) file_get_contents($summaryPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, "Runtime concurrency race failed.\n".$rendered);
        $this->assertSame('PASS', $summary['status']);
        $this->assertSame([], $summary['failed_scenarios']);
        $this->assertSame(
            self::REQUIRED_SCENARIOS,
            array_keys($summary['scenarios']),
            'DEV188-P1-02R2 requires same doc/version+same line, same doc/version+different line and same item/different doc; DEV188-P1-03R2 adds the shared-Idempotency-Key race.'
        );

        // DEV188-P1-02R2 closure evidence phải tự mang PostgreSQL version và candidate
        // SHA — reviewer từ chối bằng chứng không gắn được với một bản build cụ thể.
        $this->assertMatchesRegularExpression('/^PostgreSQL \d+/', (string) $summary['postgres_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $summary['git_sha'], 'Summary must pin a real commit SHA, not a floating placeholder.');

        foreach ($summary['scenarios'] as $name => $scenario) {
            $this->assertTrue($scenario['passed'], "Scenario {$name} failed: ".json_encode($scenario['checks']));
            $this->assertSame(
                2,
                $scenario['distinct_backend_pid_count'],
                "Scenario {$name} did not use two distinct PostgreSQL backends."
            );

            if (in_array($name, self::LOCK_CONTENDED_SCENARIOS, true)) {
                $this->assertTrue(
                    $scenario['real_contention_proven'],
                    "Scenario {$name} never observed real lock contention; the race would be vacuous."
                );
            }

            // Raw timestamps: hai tiến trình phải thực sự chồng lấn trong thời gian,
            // không phải chạy nối tiếp rồi được gọi là "race".
            $this->assertTrue(
                $scenario['timing']['measurable'],
                "Scenario {$name} did not record raw per-worker timestamps."
            );
            $this->assertTrue(
                $scenario['timing']['workers_in_flight_simultaneously'],
                "Scenario {$name} workers never overlapped in wall-clock time."
            );

            // DB before/after phải có thật để reviewer đối chiếu, không chỉ verdict.
            $this->assertArrayHasKey('db_before', $scenario);
            $this->assertArrayHasKey('db_after', $scenario);
        }

        $idempotency = $summary['scenarios']['same_idempotency_key_quantity_mode'];
        $this->assertSame(
            2,
            $idempotency['db_after']['lines'][$idempotency['line_id']]['scanned_qty'],
            'Hai tiến trình gửi cùng một Idempotency-Key chỉ được cộng scanned_qty đúng một lần.'
        );
        $this->assertSame(
            1,
            $idempotency['db_before']['document_version'],
            'Fixture phải bắt đầu ở version=1 thì bằng chứng "tăng đúng một lần" mới có nghĩa.'
        );
        $this->assertSame(2, $idempotency['db_after']['document_version']);
    }
}
