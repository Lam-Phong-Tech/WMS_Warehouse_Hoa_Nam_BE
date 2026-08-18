<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Executed concurrency evidence for WWHN-188 (HN-IN-002, BE1) — closes reviewer
 * finding DEV188-P1-02: "Chạy race thật PostgreSQL bằng >=2 connection/process cho
 * same doc/version, same line và same item/different doc; xác nhận 1 winner, loser
 * rollback/no side effect."
 *
 * `InboundScanConcurrencyBehaviorTest` proves the guard's *outcome* by replaying two
 * HTTP requests sequentially on a single SQLite connection — it never puts two
 * independent database backends in real contention. This test instead runs
 * `scripts/wwhn188-inbound-scan-runtime-concurrency.php`, which spawns real OS
 * processes (one PostgreSQL backend connection each) that call the production
 * `CurrentBaselineStockFlowController::inboundScan()` concurrently, samples
 * `pg_locks`/`pg_stat_activity` while they contend, and asserts the committed
 * version/scanned_qty/inbound_scan_entries outcome — not HTTP status alone.
 *
 * Deliberately does NOT use RefreshDatabase/DatabaseTransactions: the proof requires
 * committed rows that independent OS processes on separate PostgreSQL backends can
 * actually see. The orchestrator builds and asserts its own isolated fixtures
 * (unique per run token), same convention as the WWHN-219 runtime concurrency gate.
 */
class InboundScanRuntimeConcurrencyTest extends TestCase
{
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
            ['same_document_same_line', 'same_document_different_line', 'same_item_different_document'],
            array_keys($summary['scenarios']),
            'DEV188-P1-02 requires all three scenarios: same doc/version+same line, same doc/version+different line, same item/different doc.'
        );

        foreach ($summary['scenarios'] as $name => $scenario) {
            $this->assertTrue($scenario['passed'], "Scenario {$name} failed: ".json_encode($scenario['checks']));
            $this->assertSame(
                2,
                $scenario['distinct_backend_pid_count'],
                "Scenario {$name} did not use two distinct PostgreSQL backends."
            );
            $this->assertTrue(
                $scenario['real_contention_proven'],
                "Scenario {$name} never observed real lock contention; the race would be vacuous."
            );
        }
    }
}
