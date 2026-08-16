<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ARCHITECTURE GUARD — NOT concurrency evidence.
 *
 * These assertions read source files and prove only that the locking/versioning
 * constructs have not been deleted from the canonical services and controllers.
 * They execute no transactions and open no competing connections, so they must
 * never be cited as proof that concurrency control works at runtime.
 *
 * Executed proof lives in {@see Wwhn219RuntimeConcurrencyTest} and
 * `scripts/wwhn219-runtime-concurrency.php`, which spawn real OS processes on
 * independent PostgreSQL backends and assert the committed ledger, balance and
 * audit outcome.
 */
class StockDocumentConcurrencyGuardTest extends TestCase
{
    public function test_source_guard_stock_mutations_retain_lock_and_version_constructs(): void
    {
        $stock = $this->source('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');
        $flow = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');

        $this->assertStringContainsString('lockForUpdate()', $stock);
        $this->assertStringContainsString("where('version',", $stock);
        $this->assertStringContainsString('VERSION_CONFLICT', $stock);
        $this->assertStringContainsString('lockForUpdate()', $flow);
        $this->assertStringContainsString('If-Match', $flow);
        $this->assertStringContainsString('PRECONDITION_FAILED', $flow);
    }

    public function test_source_guard_reversal_retains_reversible_quantity_recheck_constructs(): void
    {
        $reversal = $this->source('Modules/Warehouse/app/Services/CurrentBaselineReversalService.php');

        $this->assertStringContainsString('lockForUpdate()', $reversal);
        $this->assertStringContainsString('REVERSAL_QTY_EXCEEDED', $reversal);
        $this->assertStringContainsString('REVERSAL_CONCURRENT_CONFLICT', $reversal);
        $this->assertStringContainsString('original_movement_id', $reversal);
        $this->assertStringContainsString('correction_chain_id', $reversal);
        $this->assertStringContainsString('REVERSAL_OF_REVERSAL_FORBIDDEN', $reversal);
    }

    public function test_source_guard_container_and_component_mutations_retain_version_constructs(): void
    {
        foreach ([
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php',
        ] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString('lockForUpdate()', $source, $path);
            $this->assertStringContainsString('If-Match', $source, $path);
            $this->assertStringContainsString('PRECONDITION_FAILED', $source, $path);
            $this->assertStringContainsString("'version' =>", $source, $path);
        }
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
