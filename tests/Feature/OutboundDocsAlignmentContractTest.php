<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ghim các invariant đồng bộ tài liệu (BRD/SRS/BPD/ERD) cho luồng 03 · Xuất kho
 * sau đợt rà soát docs-alignment: API toa xuất, vòng đời OPEN/CONSUMED, template
 * Excel, nhật ký in nhãn, aggregate nhãn/RFID và việc gỡ bỏ khái niệm partial.
 */
class OutboundDocsAlignmentContractTest extends TestCase
{
    public function test_outbound_request_read_api_and_lifecycle_are_wired(): void
    {
        $routes = $this->source('Modules/Warehouse/routes/api.php');
        $import = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php');
        $document = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php');

        $this->assertStringContainsString("Route::get('outbound-requests'", $routes);
        $this->assertStringContainsString("Route::get('outbound-requests/{id}'", $routes);
        $this->assertStringContainsString('OutboundRequestController', $routes);

        // ERD bảng 37: OPEN -> CONSUMED khi phiếu xuất sinh từ toa; Cancel phiếu
        // trả toa về OPEN nếu không còn phiếu active/POSTED nào dùng toa.
        $this->assertStringContainsString("'status' => 'CONSUMED'", $import);
        $this->assertStringContainsString("'status' => 'CONSUMED'", $document);
        $this->assertStringContainsString("'status' => 'OPEN'", $document);

        // §8.1 + BP-WMS-04/05: phiếu xuất chỉ sinh từ Confirm toa — không còn endpoint
        // tạo/sửa phiếu xuất trực tiếp; line luôn lấy từ outbound_request_lines.
        $this->assertStringNotContainsString("Route::post('outbound-documents',", $routes);
        $this->assertStringNotContainsString("Route::patch('outbound-documents/{id}',", $routes);
        $this->assertStringNotContainsString('function storeOutbound', $document);
        $this->assertStringContainsString("'outbound_request_line_id' => \$requestLineId", $import);
    }

    public function test_import_surface_has_list_template_preview_and_errors(): void
    {
        $routes = $this->source('Modules/Warehouse/routes/api.php');
        $import = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineImportController.php');

        $this->assertStringContainsString("Route::get('outbound-request-imports'", $routes);
        $this->assertStringContainsString("Route::get('outbound-request-imports/template'", $routes);
        $this->assertStringContainsString("Route::get('outbound-request-imports/{id}/preview'", $routes);
        $this->assertStringContainsString("Route::get('outbound-request-imports/{id}/errors'", $routes);
        $this->assertStringContainsString('CurrentBaselineImportReadController', $routes);
        $this->assertStringContainsString('downloadOutboundRequestTemplate', $import);
        $this->assertStringContainsString('indexOutboundRequestImports', $import);
    }

    public function test_label_and_printer_read_write_surface_is_complete(): void
    {
        $routes = $this->source('Modules/Warehouse/routes/api.php');
        $operations = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php');

        $this->assertStringContainsString("Route::get('label-snapshots/{id}'", $routes);
        $this->assertStringContainsString("Route::get('label-print-events'", $routes);
        $this->assertStringContainsString("Route::patch('printer-configs/{id}'", $routes);
        $this->assertStringContainsString('showLabelSnapshot', $operations);
        $this->assertStringContainsString('listLabelPrintEvents', $operations);
        $this->assertStringContainsString('updatePrinterConfig', $operations);
    }

    public function test_outbound_list_returns_label_and_rfid_progress(): void
    {
        $controller = $this->source('Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php');

        $this->assertStringContainsString('label_printed_count', $controller);
        $this->assertStringContainsString('rfid_mapped_count', $controller);
        $this->assertStringContainsString('matched_count', $controller);
        $this->assertStringContainsString('required_total', $controller);
    }

    public function test_scan_evidence_is_recorded_for_manual_item_scans_too(): void
    {
        $service = $this->source('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');

        $this->assertStringNotContainsString("! isset(\$payload['code_value']) || ! Schema::hasTable('scan_batch')", $service);
        $this->assertStringContainsString('NFR-SRS-004', $service);
    }

    public function test_mutations_expose_etag_alongside_if_match(): void
    {
        foreach ([
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
        ] as $path) {
            $this->assertStringContainsString("header('ETag'", $this->source($path), $path);
        }
    }

    public function test_partial_and_stocktake_concepts_stay_removed_from_active_surface(): void
    {
        $report = $this->source('Modules/Warehouse/app/Http/Controllers/ReportController.php');
        $model = $this->source('Modules/Warehouse/app/Models/StockDocument.php');
        $outbound = $this->source('Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php');

        $this->assertStringNotContainsString('documents_partial', $report);
        $this->assertStringNotContainsString('stocktake_variance', $report);
        $this->assertStringNotContainsString('PARTIALLY_POSTED', $model);
        $this->assertStringNotContainsString('PARTIALLY_POSTED', $outbound);
        $this->assertStringNotContainsString('stock_out_submit', $outbound);
        $this->assertStringNotContainsString('stock_out_approve', $outbound);
        $this->assertStringNotContainsString('stock_out_reject', $outbound);
        $this->assertStringNotContainsString('remainder', $outbound);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
