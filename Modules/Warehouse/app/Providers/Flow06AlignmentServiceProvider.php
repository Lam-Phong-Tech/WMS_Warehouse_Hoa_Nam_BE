<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1InboundDocumentController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1RecipientController;
use Modules\Warehouse\Http\Controllers\BaM1ReportController;
use Modules\Warehouse\Http\Controllers\BaM1WarrantyController;
use Modules\Warehouse\Http\Middleware\EnforceInboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Services\BaM1StockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Runtime overrides for the confirmed GĐ1 business baseline.
 * Registered after normal Warehouse routes so identical method/URI pairs resolve
 * to one canonical handler while legacy source remains available for migration.
 */
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
                // GĐ1 inbound: create/update only document header. SKU lines and qty
                // are derived from physical scans; no planned SKU/qty input remains.
                Route::post('inbound-documents', [BaM1InboundDocumentController::class, 'store'])
                    ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);
                Route::patch('inbound-documents/{id}', [BaM1InboundDocumentController::class, 'update'])
                    ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);

                // Post Issue is the authoritative stock transaction. Packing Label
                // is an optional operational action after Post and cannot gate or
                // roll back inventory.
                Route::post('outbound-documents/{id}/post-issue', [BaM1OutboundFlowController::class, 'postIssue'])
                    ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);

                // Multi-defect + canonical estimated_completion_date, with legacy
                // expected_completion_date/defect_codes aliases only at API edge.
                Route::post('warranty-cases', [BaM1WarrantyController::class, 'storeWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::get('warranty-cases/{id}', [BaM1WarrantyController::class, 'showWarrantyCase'])
                    ->middleware('permission:warranty.view|warranty.manage');
                Route::patch('warranty-cases/{id}', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::post('warranty-cases/{id}/status', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');

                // Exactly four recipient classes share the historical agencies
                // master so existing outbound FK/history remains valid.
                Route::get('agencies', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
                Route::post('agencies', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
                Route::get('agencies/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
                Route::patch('agencies/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');
                Route::get('recipients', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
                Route::post('recipients', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
                Route::get('recipients/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
                Route::patch('recipients/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');

                // GĐ1 reports: recipient dimensions and warranty multi-defect are
                // canonical. Existing URL names are kept for FE compatibility.
                Route::get('reports/outbound-by-agency', [BaM1ReportController::class, 'outboundByRecipient'])
                    ->middleware('permission:report.view');
                Route::get('reports/outbound-by-recipient', [BaM1ReportController::class, 'outboundByRecipient'])
                    ->middleware('permission:report.view');
                Route::get('reports/warranty-defects', [BaM1ReportController::class, 'warrantyDefects'])
                    ->middleware('permission:report.view');
                Route::get('reports/warranty-defect-sku-breakdown', [BaM1ReportController::class, 'defectSkuBreakdown'])
                    ->middleware('permission:report.view');

                // Packing label remains available as its own transaction. GĐ1 no
                // longer exposes an APPLY/"đã dán" mutation as a business state.
                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
            });
        });
    }
}
