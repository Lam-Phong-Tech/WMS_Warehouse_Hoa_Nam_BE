<?php

namespace Tests\Feature;

use Tests\TestCase;

class StockDocumentIdempotencyTest extends TestCase
{
    public function test_stock_changing_commands_use_single_idempotency_store_contract(): void
    {
        $stock = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');
        $component = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php');
        $import = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php');

        $this->assertStringContainsString('IdempotencyKeyGuard::require', $stock);
        $this->assertStringContainsString('IdempotencyStore::handle', $stock);
        $this->assertStringContainsString('post_receipt_', $stock);
        $this->assertStringContainsString('post_issue_', $stock);
        $this->assertStringContainsString('reverse_stock_document_', $stock);

        $this->assertStringContainsString('IdempotencyKeyGuard::require', $component);
        $this->assertStringContainsString('post_component_issue_', $component);
        $this->assertStringContainsString('IdempotencyStore::handle', $component);

        $this->assertStringContainsString('consume_outbound_import_', $import);
        $this->assertStringContainsString('IdempotencyStore::handle', $import);
    }

    public function test_existing_aggregate_mutations_require_if_match(): void
    {
        foreach ([
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php',
        ] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString('If-Match', $source, $path);
            $this->assertStringContainsString('PRECONDITION_REQUIRED', $source, $path);
            $this->assertStringContainsString('STALE_VERSION', $source, $path);
        }
    }

    public function test_import_has_checksum_and_source_key_replay_guards(): void
    {
        $import = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php');
        $base = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php');

        $this->assertStringContainsString('source_key', $import);
        $this->assertStringContainsString('SOURCE_KEY_CONFLICT', $import);
        $this->assertStringContainsString('source_checksum', $base);
        $this->assertStringContainsString('Duplicate checksum replayed', $base);
        $this->assertStringContainsString('outbound_document_ids', $import);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
