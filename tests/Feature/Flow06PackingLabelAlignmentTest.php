<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AlignedImportMatchReportController;
use Modules\Warehouse\Http\Controllers\AlignedLabelLogReportController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundDocumentController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use Modules\Warehouse\Services\AlignedOutboundStockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Tests\TestCase;

/** Regression guard for the 2026-08-15 Packing Label / RFID / Flow 06 alignment. */
class Flow06PackingLabelAlignmentTest extends TestCase
{
    public function test_outbound_stock_service_uses_optional_rfid_alignment(): void
    {
        $this->assertInstanceOf(AlignedOutboundStockService::class, app(CurrentBaselineStockService::class));

        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Services/AlignedOutboundStockService.php'));
        $this->assertStringContainsString("'rfid_required' => false", $source);
        $this->assertStringContainsString('PRODUCT_ITEM_MATCH_REQUIRED', $source);
        $this->assertStringNotContainsString('ITEM_RFID_REQUIRED', $source);
    }

    public function test_runtime_routes_resolve_to_aligned_handlers(): void
    {
        // GĐ1 2026-08-25: Post Issue chạy qua BaM1OutboundFlowController (kế thừa
        // Aligned*, bypassPackingLabelGate=true) — nhãn không còn gate Post Issue.
        $expectations = [
            ['POST', 'api/v1/outbound-documents/{id}/scan', AlignedOutboundFlowController::class.'@outboundScan'],
            ['POST', 'api/v1/outbound-documents/{id}/post-issue', BaM1OutboundFlowController::class.'@postIssue'],
            ['POST', 'api/v1/label-snapshots', AlignedOutboundFlowController::class.'@storePackingLabel'],
            ['GET', 'api/v1/outbound-documents', AlignedOutboundDocumentController::class.'@index'],
            ['GET', 'api/v1/reports/import-match', AlignedImportMatchReportController::class.'@index'],
            ['GET', 'api/v1/reports/label-log', AlignedLabelLogReportController::class.'@index'],
        ];

        foreach ($expectations as [$method, $uri, $action]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->last(fn ($candidate) => in_array($method, $candidate->methods(), true) && $candidate->uri() === $uri);
            $this->assertNotNull($route, "Missing {$method} {$uri}");
            $this->assertSame($action, $route->getActionName(), "Wrong handler for {$method} {$uri}");
        }

        // GĐ1: không còn business state "đã dán" — route apply KHÔNG được đăng ký.
        $apply = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => in_array('POST', $candidate->methods(), true)
                && $candidate->uri() === 'api/v1/label-snapshots/{id}/apply');
        $this->assertNull($apply, 'POST label-snapshots/{id}/apply đã bị gỡ khỏi hợp đồng GĐ1.');
    }

    public function test_packing_label_contract_is_one_per_outbound_document_and_stock_neutral_until_post(): void
    {
        $flow = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php'));
        $migration = (string) file_get_contents(base_path('Modules/Warehouse/database/migrations/2026_08_15_140000_align_packing_label_to_outbound_document.php'));

        $this->assertStringContainsString('outbound_document_id', $flow);
        $this->assertStringContainsString('customer_phone', $flow);
        $this->assertStringContainsString('FULL_SCAN_REQUIRED_FOR_LABEL', $flow);
        $this->assertStringContainsString('stock_effect=NONE', $flow);
        $this->assertStringContainsString("unique('outbound_document_id'", $migration);
        $this->assertStringContainsString("'outbound_item_match_id' => null", $flow);
        $this->assertStringContainsString("'sku_id' => null", $flow);

        // GĐ1 2026-08-25: Post Issue không còn bị nhãn gate — không được tái sinh
        // chuỗi lỗi PACKING_LABEL_NOT_APPLIED; response khai bypass tường minh và
        // bước chuẩn bị nhãn nằm NGOÀI transaction trừ tồn (fail → warning, không rollback).
        $this->assertStringNotContainsString('PACKING_LABEL_NOT_APPLIED', $flow);
        $this->assertStringContainsString("'packing_label_bypassed'", $flow);
        $this->assertStringContainsString("'packing_label_warning'", $flow);

        $baM1 = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/BaM1OutboundFlowController.php'));
        $this->assertStringContainsString('extends AlignedOutboundFlowController', $baM1);
        $this->assertStringContainsString('bool $bypassPackingLabelGate = true', $baM1);
    }

    public function test_report_contract_keeps_filter_parity_and_document_level_label_semantics(): void
    {
        $import = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/AlignedImportMatchReportController.php'));
        foreach (['batch_id', 'request_id', 'sku_code', 'error_status', 'keyword', 'per_page'] as $filter) {
            $this->assertStringContainsString("'{$filter}'", $import);
        }

        $label = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/AlignedLabelLogReportController.php'));
        $this->assertStringContainsString("'label_count' => 1", $label);
        $this->assertStringContainsString('customer_phone', $label);
        $this->assertStringContainsString("whereNotNull('ls.outbound_document_id')", $label);
        $this->assertStringContainsString("'format'", $import);
        $this->assertStringContainsString("'format'", $label);
    }
}
