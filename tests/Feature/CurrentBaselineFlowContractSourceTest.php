<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentBaselineFlowContractSourceTest extends TestCase
{
    public function test_stock_flow_source_pins_current_business_invariants(): void
    {
        $controller = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockController.php'));
        $service = (string) file_get_contents(base_path('Modules/Warehouse/app/Services/CurrentBaselineStockService.php'));

        $this->assertStringContainsString('physical_return_confirmed', $controller);
        $this->assertStringContainsString('PHYSICAL_RETURN_REQUIRED', $controller);
        $this->assertStringContainsString('PENDING_RETURN', $controller);

        $this->assertStringContainsString("assigned_object_type', 'ITEM'", $service);
        $this->assertStringContainsString('ITEM_RFID_REQUIRED', $service);
        $this->assertStringContainsString('PRODUCT_ITEM_MATCH_REQUIRED', $service);
        $this->assertStringContainsString('applyReceiptSubledger', $service);
        $this->assertStringContainsString('applyIssueSubledger', $service);
        $this->assertStringContainsString("'COMPONENT_ISSUE'", $service);
        $this->assertStringContainsString('REVERSAL_QTY_EXCEEDED', $service);
        $this->assertStringContainsString('REVERSAL_DEPENDENCY_EXISTS', $service);
    }

    public function test_operational_flow_source_pins_excel_warranty_label_and_pv_rules(): void
    {
        $operations = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php'));

        $this->assertStringContainsString('PhpOffice\\PhpSpreadsheet\\IOFactory', $operations);
        $this->assertStringContainsString('INVALID_EXCEL_TEMPLATE', $operations);
        $this->assertStringContainsString('validation_errors', $operations);
        $this->assertStringContainsString('source_checksum', $operations);

        $this->assertStringContainsString('WARRANTY_TRANSITIONS', $operations);
        $this->assertStringContainsString('ACTIVE_WARRANTY_EXISTS', $operations);
        $this->assertStringContainsString('TEMP_ONLY_EVIDENCE_REQUIRED', $operations);
        $this->assertStringContainsString('PRECONDITION_REQUIRED', $operations);

        $this->assertStringContainsString('LABEL_PII_FORBIDDEN', $operations);
        $this->assertStringContainsString('PRINT_FAILED', $operations);
        $this->assertStringContainsString('agent_endpoint', $operations);

        $this->assertStringContainsString("where('s.status', 'ACTIVE')", $operations);
        $this->assertStringContainsString("where('p.published', true)", $operations);
    }

    public function test_component_issue_source_pins_warranty_only_atomic_stock_effect(): void
    {
        $component = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentIssueController.php'));

        $this->assertStringContainsString("'purpose' => 'WARRANTY'", $component);
        $this->assertStringContainsString('COMPONENT_SKU_REQUIRED', $component);
        $this->assertStringContainsString('FULL_POST_REQUIRED', $component);
        $this->assertStringContainsString('consumeDocument', $component);
        $this->assertStringContainsString('postComponentIssue', $component);
        $this->assertStringContainsString('IdempotencyStore::handle', $component);
    }
}
