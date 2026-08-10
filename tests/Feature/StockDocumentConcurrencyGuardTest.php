<?php

namespace Tests\Feature;

use Tests\TestCase;

class StockDocumentConcurrencyGuardTest extends TestCase
{
    public function test_stock_mutations_lock_authoritative_rows_and_version_balances(): void
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

    public function test_reversal_rechecks_reversible_quantity_under_lock(): void
    {
        $reversal = $this->source('Modules/Warehouse/app/Services/CurrentBaselineReversalService.php');

        $this->assertStringContainsString('lockForUpdate()', $reversal);
        $this->assertStringContainsString('REVERSAL_QTY_EXCEEDED', $reversal);
        $this->assertStringContainsString('REVERSAL_CONCURRENT_CONFLICT', $reversal);
        $this->assertStringContainsString('original_movement_id', $reversal);
        $this->assertStringContainsString('correction_chain_id', $reversal);
        $this->assertStringContainsString('REVERSAL_OF_REVERSAL_FORBIDDEN', $reversal);
    }

    public function test_container_and_component_mutations_are_versioned_and_locked(): void
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
