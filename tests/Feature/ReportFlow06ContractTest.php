<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contract guard cho Flow 06. Test này cố ý không phụ thuộc DB để bắt regression
 * route/permission/formula ngay ở CI trước khi chạy integration/UAT data pack.
 */
class ReportFlow06ContractTest extends TestCase
{
    public function test_flow_06_routes_cover_all_baseline_reports_and_export_permission(): void
    {
        $routes = file_get_contents(base_path('Modules/Warehouse/routes/api.php'));

        foreach ([
            'reports/hub',
            'reports/inventory-balance',
            'reports/inventory-xnt',
            'reports/inventory-xnt-enhanced',
            'reports/document-history',
            'reports/movement-trace',
            'reports/import-match',
            'reports/label-log',
            'reports/warranty-defects',
            'reports/outbound-by-agency',
            'reports/inventory-reconciliation',
        ] as $path) {
            $this->assertStringContainsString("'{$path}'", $routes, "Missing Flow 06 route {$path}");
        }

        foreach ([
            'reports/inventory-balance/export-excel',
            'reports/inventory-xnt/export-excel',
            'reports/inventory-xnt-enhanced/export-excel',
            'reports/document-history/export-excel',
            'reports/movement-trace/export-excel',
            'reports/import-match/export-excel',
            'reports/label-log/export-excel',
            'reports/warranty-defects/export-excel',
            'reports/outbound-by-agency/export-excel',
        ] as $path) {
            $needle = "Route::get('{$path}'";
            $offset = strpos($routes, $needle);
            $this->assertNotFalse($offset, "Missing export route {$path}");
            $snippet = substr($routes, (int) $offset, 260);
            $this->assertStringContainsString('permission:report.export', $snippet, "{$path} must enforce report.export on BE");
        }
    }

    public function test_xnt_uses_canonical_movement_semantics_and_exact_formula(): void
    {
        $source = file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'));

        $this->assertStringContainsString('Closing = Opening + Receipt - Issue - Component Issue + Reversal', $source);
        foreach (['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'] as $movementType) {
            $this->assertStringContainsString("'{$movementType}'", $source);
        }
        foreach (['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'] as $stockStatus) {
            $this->assertStringContainsString("'{$stockStatus}'", $source);
        }
        $this->assertStringNotContainsString("'PARTIALLY_POSTED'", $source);
        $this->assertStringNotContainsString('stocktake', strtolower($source));
    }

    public function test_report_catalog_has_exact_eight_mvp_business_reports(): void
    {
        $source = file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'));
        foreach ([
            'inventory-balance', 'inventory-xnt', 'document-history', 'movement-trace',
            'import-match', 'label-log', 'warranty-defects', 'outbound-by-agency',
        ] as $key) {
            $this->assertStringContainsString("'key' => '{$key}'", $source);
        }
    }
}
