<?php

namespace Tests\Feature;

use Tests\TestCase;

class StockDocumentCanonicalFlowTest extends TestCase
{
    public function test_current_document_lifecycle_is_draft_scanning_posted_cancelled_only(): void
    {
        $routes = $this->source('Modules/Warehouse/routes/api.php');
        $document = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php');
        $stock = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');
        $service = $this->source('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');

        $this->assertStringContainsString('CurrentBaselineDocumentController', $routes);
        $this->assertStringContainsString('CurrentBaselineStockFlowController', $routes);
        $this->assertStringContainsString("'status' => 'DRAFT'", $document);
        $this->assertStringContainsString("['DRAFT', 'SCANNING']", $document);
        $this->assertStringContainsString("status !== 'SCANNING'", $stock);
        $this->assertStringContainsString("'status' => 'POSTED'", $service);
        $this->assertStringContainsString("'status' => 'CANCELLED'", $document);

        $this->assertStringNotContainsString('PARTIALLY_POSTED', $routes);
        $this->assertStringNotContainsString('close-remainder', $routes);
        $this->assertStringNotContainsString('/submit', $routes);
        $this->assertStringNotContainsString('/approve', $routes);
        $this->assertStringNotContainsString('/reject', $routes);
    }

    public function test_scan_is_stock_neutral_and_full_post_is_exact(): void
    {
        $service = $this->source('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');
        $stock = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');

        $this->assertStringContainsString("'stock_effect' => 'NONE'", $service);
        $this->assertStringContainsString('scanned_qty', $service);
        $this->assertStringContainsString('qty_planned', $service);
        $this->assertStringContainsString('FULL_POST_REQUIRED', $service);
        $this->assertStringContainsString('OUTBOUND_REQUEST_REQUIRED', $stock);
        $this->assertStringContainsString('PRODUCT_SKU_REQUIRED', $stock);
        $this->assertStringContainsString('LABEL_NOT_PRINTED', $stock);
    }

    public function test_reversal_keeps_original_posted_and_uses_reversal_aggregate(): void
    {
        $service = $this->source('Modules/Warehouse/app/Services/CurrentBaselineReversalService.php');

        $this->assertStringContainsString("'original_document_status' => 'POSTED'", $service);
        $this->assertStringContainsString('reversal_document', $service);
        $this->assertStringContainsString('reversal_line', $service);
        $this->assertStringContainsString('REVERSAL_QTY_EXCEEDED', $service);
        $this->assertStringContainsString('PENDING_RETURN', $service);
        $this->assertStringContainsString('physical_return_confirmed', $service);
        $this->assertStringContainsString('REVERSAL_OF_REVERSAL_FORBIDDEN', $service);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
