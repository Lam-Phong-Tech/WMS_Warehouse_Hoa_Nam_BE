<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Executed concurrency evidence for WWHN-219.
 *
 * Deliberately does NOT use RefreshDatabase/DatabaseTransactions: the proof
 * requires committed rows that independent OS processes on separate PostgreSQL
 * backends can actually see. The orchestrator builds and asserts its own
 * isolated fixtures.
 */
class Wwhn219RuntimeConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Runtime concurrency evidence requires PostgreSQL; the static architecture guard covers other drivers.');
        }

        if (getenv('WWHN219_RUNTIME_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set WWHN219_RUNTIME_CONCURRENCY=1 to execute the multi-process race (CI hard gate does this).');
        }
    }

    public function test_multi_connection_races_preserve_ledger_balance_and_audit_invariants(): void
    {
        $evidenceDir = getenv('WWHN219_EVIDENCE_DIR') ?: 'storage/app/wwhn-219-evidence';
        $script = base_path('scripts/wwhn219-runtime-concurrency.php');

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($evidenceDir).' 2>&1';
        exec($command, $output, $exitCode);
        $rendered = implode("\n", $output);

        $summaryPath = base_path($evidenceDir.'/runtime-concurrency-summary.json');
        $this->assertFileExists($summaryPath, "Orchestrator produced no summary.\n".$rendered);

        $summary = json_decode((string) file_get_contents($summaryPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, "Runtime concurrency race failed.\n".$rendered);
        $this->assertSame('PASS', $summary['status']);
        $this->assertSame([], $summary['failed_scenarios']);

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
