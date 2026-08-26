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

    public function test_inbound_openapi_source_has_no_legacy_partial_or_approval_flow(): void
    {
        // 26/08: contract OpenAPI của inbound giờ tách sang nhiều controller —
        // InboundDocumentController giữ list/detail/cancel/scan/unscan/reverse
        // (dead-code shim, annotation vẫn có giá trị tham chiếu); update() đã
        // chuyển hẳn sang BaM1InboundDocumentController (handler thật, route đã
        // bị Flow06AlignmentServiceProvider ghi đè) và post-receipt() sang
        // CurrentBaselineStockFlowController (handler thật, không bị override) —
        // xem config/l5-swagger.php để biết vì sao 2 annotation cũ bị gỡ khỏi
        // InboundDocumentController (trùng path+method gây l5-swagger:generate
        // crash "Unable to merge").
        $openApiSource = $this->source('Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php');
        $postReceiptSource = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php');
        $updateSource = $this->source('Modules/Warehouse/app/Http/Controllers/BaM1InboundDocumentController.php');

        $this->assertStringContainsString('DRAFT -> SCANNING -> POSTED/CANCELLED', $openApiSource);
        $this->assertStringContainsString('SCANNING → POSTED', $postReceiptSource);
        $this->assertStringContainsString('Cập nhật thông tin đầu phiếu nhập (DRAFT/SCANNING; scan-driven, không sửa SKU/số lượng qua đây)', $updateSource);
        $this->assertStringNotContainsString('/submit', $openApiSource);
        $this->assertStringNotContainsString('/approve', $openApiSource);
        $this->assertStringNotContainsString('/reject', $openApiSource);
        $this->assertStringNotContainsString('close-remainder', $openApiSource);
        $this->assertStringNotContainsString('PARTIALLY_POSTED', $openApiSource);
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

    public function test_scanning_receipt_can_correct_expected_without_replacing_scan_evidence(): void
    {
        $document = $this->source('Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php');

        $this->assertStringContainsString("'lines.*.id' => ['nullable', 'uuid']", $document);
        $this->assertStringContainsString('updateScannedInboundLines', $document);
        $this->assertStringContainsString('PRESERVE_SCAN_EVIDENCE', $document);
        $this->assertStringContainsString('SCANNED_LINE_STRUCTURE_IMMUTABLE', $document);
        $this->assertStringContainsString('EXPECTED_QTY_BELOW_SCANNED', $document);
        $this->assertStringContainsString("'qty_planned' => \$expectedQty", $document);
        $this->assertStringContainsString("'scanned_qty' => 0", $document);
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
