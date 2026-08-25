<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1InboundComponentController;
use Modules\Warehouse\Http\Controllers\BaM1InboundDocumentController;
use Modules\Warehouse\Http\Controllers\BaM1InboundScanCorrectionController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1RecipientController;
use Modules\Warehouse\Http\Controllers\BaM1ReportController;
use Modules\Warehouse\Http\Controllers\BaM1WarrantyController;
use Modules\Warehouse\Http\Middleware\EnforceInboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Services\BaM1StockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/** Runtime overrides for the confirmed GĐ1 business baseline. */
class Flow06AlignmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurrentBaselineStockService::class, BaM1StockService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Route::middleware(['api', 'auth:api'])->prefix('api/v1')->group(function (): void {
                // Inbound is scan-driven: header only, then actual scans/counts.
                Route::post('inbound-documents', [BaM1InboundDocumentController::class, 'store'])
                    ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);
                Route::patch('inbound-documents/{id}', [BaM1InboundDocumentController::class, 'update'])
                    ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);
                Route::post('inbound-documents/{id}/component-lines', [BaM1InboundComponentController::class, 'ensureLine'])
                    ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);
                Route::post('inbound-documents/{id}/unscan', [BaM1InboundScanCorrectionController::class, 'unscan'])
                    ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

                // Post Issue is the stock transaction; label printing is independent.
                Route::post('outbound-documents/{id}/post-issue', [BaM1OutboundFlowController::class, 'postIssue'])
                    ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);

                // Warranty: multi-defect + canonical estimated_completion_date.
                Route::post('warranty-cases', [BaM1WarrantyController::class, 'storeWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::get('warranty-cases/{id}', [BaM1WarrantyController::class, 'showWarrantyCase'])
                    ->middleware('permission:warranty.view|warranty.manage');
                Route::patch('warranty-cases/{id}', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::post('warranty-cases/{id}/status', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');

                // Exactly four recipient classes on the historical agencies master.
                Route::get('agencies', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
                Route::post('agencies', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
                Route::get('agencies/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
                Route::patch('agencies/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');
                Route::get('recipients', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
                Route::post('recipients', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
                Route::get('recipients/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
                Route::patch('recipients/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');

                // GĐ1 report dimensions.
                Route::get('reports/outbound-by-agency', [BaM1ReportController::class, 'outboundByRecipient'])
                    ->middleware('permission:report.view');
                Route::get('reports/outbound-by-recipient', [BaM1ReportController::class, 'outboundByRecipient'])
                    ->middleware('permission:report.view');
                Route::get('reports/warranty-defects', [BaM1ReportController::class, 'warrantyDefects'])
                    ->middleware('permission:report.view');
                Route::get('reports/warranty-defect-sku-breakdown', [BaM1ReportController::class, 'defectSkuBreakdown'])
                    ->middleware('permission:report.view');

                // No APPLY/"đã dán" business state in GĐ1.
                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
            });
        });
    }
}
