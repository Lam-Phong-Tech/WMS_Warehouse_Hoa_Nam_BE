<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentBaselineFlowContractSourceTest extends TestCase
{
    public function test_stock_flow_source_pins_current_business_invariants(): void
    {
        $controller = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');
        $service = $this->source('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');
        $reversal = $this->source('Modules/Warehouse/app/Services/CurrentBaselineReversalService.php');

        $this->assertStringContainsString('If-Match', $controller);
        $this->assertStringContainsString('OUTBOUND_REQUEST_REQUIRED', $controller);
        $this->assertStringContainsString('LABEL_NOT_PRINTED', $controller);
        $this->assertStringContainsString('PRODUCT_SKU_REQUIRED', $controller);

        $this->assertStringContainsString("assigned_object_type', 'ITEM'", $service);
        $this->assertStringContainsString('ITEM_RFID_REQUIRED', $service);
        $this->assertStringContainsString('PRODUCT_ITEM_MATCH_REQUIRED', $service);
        $this->assertStringContainsString('applyReceiptSubledger', $service);
        $this->assertStringContainsString('applyIssueSubledger', $service);
        $this->assertStringContainsString("'COMPONENT_ISSUE'", $service);

        $this->assertStringContainsString('physical_return_confirmed', $reversal);
        $this->assertStringContainsString('PENDING_RETURN', $reversal);
        $this->assertStringContainsString('PHYSICAL_RETURN_REQUIRED', $reversal);
        $this->assertStringContainsString('REVERSAL_QTY_EXCEEDED', $reversal);
        $this->assertStringContainsString('REVERSAL_DEPENDENCY_EXISTS', $reversal);
        $this->assertStringContainsString('REVERSAL_OF_REVERSAL_FORBIDDEN', $reversal);
        $this->assertStringContainsString("'original_document_status' => 'POSTED'", $reversal);
    }

    public function test_excel_consume_creates_linked_outbound_drafts(): void
    {
        $operations = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php');
        $import = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php');

        $this->assertStringContainsString('PhpOffice\\PhpSpreadsheet\\IOFactory', $operations);
        $this->assertStringContainsString('INVALID_EXCEL_TEMPLATE', $operations);
        $this->assertStringContainsString('validation_errors', $operations);
        $this->assertStringContainsString('source_checksum', $operations);
        $this->assertStringContainsString('source_key', $import);
        $this->assertStringContainsString('COMPONENT_OUTBOUND_FORBIDDEN', $import);
        $this->assertStringContainsString('outbound_request_id', $import);
        $this->assertStringContainsString('outbound_document_ids', $import);
        $this->assertStringContainsString("'status' => 'DRAFT'", $import);
    }

    public function test_warranty_label_and_pv_rules_are_pinned(): void
    {
        $operations = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php');
        $warranty = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php');

        $this->assertStringContainsString('WARRANTY_TRANSITIONS', $operations);
        $this->assertStringContainsString('ACTIVE_WARRANTY_EXISTS', $operations);
        $this->assertStringContainsString('TEMP_ONLY_EVIDENCE_REQUIRED', $operations);
        $this->assertStringContainsString('If-Match', $warranty);
        $this->assertStringContainsString('PRECONDITION_REQUIRED', $warranty);
        $this->assertStringContainsString('STALE_VERSION', $operations);

        $this->assertStringContainsString('LABEL_PII_FORBIDDEN', $operations);
        $this->assertStringContainsString('PRINT_FAILED', $operations);
        $this->assertStringContainsString('agent_endpoint', $operations);
        $this->assertStringContainsString("where('s.status', 'ACTIVE')", $operations);
        $this->assertStringContainsString("where('p.published', true)", $operations);
    }

    public function test_component_issue_is_warranty_only_scan_first_and_atomic(): void
    {
        $component = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php');
        $subledger = $this->source('Modules/Warehouse/app/Services/ComponentContainerSubledgerService.php');

        $this->assertStringContainsString("'purpose' => 'WARRANTY'", $component);
        $this->assertStringContainsString('COMPONENT_SKU_REQUIRED', $component);
        $this->assertStringContainsString('FULL_POST_REQUIRED', $component);
        $this->assertStringContainsString('code_value', $component);
        $this->assertStringContainsString('scan_event_id', $component);
        $this->assertStringContainsString("'status' => 'ACTIVE'", $component);
        $this->assertStringContainsString("'status' => 'POSTED'", $component);
        $this->assertStringContainsString('consumeDocument', $component);
        $this->assertStringContainsString('postComponentIssue', $component);
        $this->assertStringContainsString('IdempotencyStore::handle', $component);
        $this->assertStringContainsString("where('ci.status', 'ACTIVE')", $subledger);
    }

    public function test_container_and_physical_identity_follow_current_erd(): void
    {
        $container = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php');
        $identity = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php');

        foreach (['EMPTY', 'IN_STOCK', 'PARTIAL', 'ISSUED', 'RECEIVED_PENDING_PUTAWAY', 'STORED', 'CLOSED'] as $status) {
            $this->assertStringContainsString($status, $container);
        }
        $this->assertStringContainsString('CONTAINER_CYCLE', $container);
        $this->assertStringContainsString('PUTAWAY_CONFIRM', $container);
        $this->assertStringContainsString('is_active', $container);
        $this->assertStringContainsString('If-Match', $container);

        $this->assertStringContainsString('PHYSICAL_CODE_TARGET_XOR', $identity);
        $this->assertStringContainsString('RFID_HARDWARE_UID_REQUIRED', $identity);
        $this->assertStringContainsString("'item_id' =>", $identity);
        $this->assertStringContainsString("'container_id' =>", $identity);
        $this->assertStringContainsString('If-Match', $identity);
    }

    public function test_documented_quantities_are_fail_closed_to_bigint_on_postgres(): void
    {
        $migration = $this->source('Modules/Warehouse/database/migrations/2026_08_10_000720_enforce_all_documented_quantities_bigint.php');

        foreach ([
            'inventory_balances', 'stock_movements', 'stock_document_lines', 'container_items',
            'inbound_scan_entries', 'outbound_item_matches', 'component_issue_lines',
            'component_issue_items', 'reversal_line',
        ] as $table) {
            $this->assertStringContainsString($table, $migration);
        }
        $this->assertStringContainsString('fractional legacy values must be reconciled first', $migration);
        $this->assertStringContainsString('TYPE BIGINT', $migration);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
