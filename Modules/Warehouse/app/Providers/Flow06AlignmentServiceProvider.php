<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1RecipientController;
use Modules\Warehouse\Http\Controllers\BaM1WarrantyController;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Services\BaM1StockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Runtime overrides for the current business baseline.
 * Registered after normal Warehouse routes so identical method/URI pairs resolve
 * to these canonical BA handlers without maintaining two active semantics.
 */
class Flow06AlignmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keep the canonical diagnostic/reservation behavior and add the 2026-08-25
        // unknown-SKU materialization adapter for scan-driven inbound.
        $this->app->bind(CurrentBaselineStockService::class, BaM1StockService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Route::middleware(['api', 'auth:api'])->prefix('api/v1')->group(function (): void {
                // BA 2026-08-25: Post Issue is the stock transaction. Packing Label
                // is independent and cannot gate or rollback it.
                Route::post('outbound-documents/{id}/post-issue', [BaM1OutboundFlowController::class, 'postIssue'])
                    ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);

                // Multi-defect + canonical estimated_completion_date, with legacy
                // expected_completion_date/defect_codes aliases.
                Route::post('warranty-cases', [BaM1WarrantyController::class, 'storeWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::get('warranty-cases/{id}', [BaM1WarrantyController::class, 'showWarrantyCase'])
                    ->middleware('permission:warranty.view|warranty.manage');
                Route::patch('warranty-cases/{id}', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::post('warranty-cases/{id}/status', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');

                // Four recipient classes share the historical agencies master so
                // existing outbound FK/history stays valid.
                Route::get('agencies', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
                Route::post('agencies', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
                Route::get('agencies/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
                Route::patch('agencies/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');
                Route::get('recipients', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
                Route::post('recipients', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
                Route::get('recipients/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
                Route::patch('recipients/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');

                // Packing label remains available as its own transaction; it does
                // not participate in the Post Issue commit boundary.
                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
                Route::post('label-snapshots/{id}/apply', [AlignedOutboundFlowController::class, 'applyPackingLabel'])
                    ->middleware('permission:label.print');
            });
        });
    }
}
