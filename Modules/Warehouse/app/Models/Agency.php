<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1WarrantyController;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Services\BaM1CurrentBaselineStockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Runtime overrides for the current business baseline.
 * They are registered after the normal Warehouse routes so identical method/URI
 * pairs resolve to these canonical handlers without keeping two active semantics.
 */
class Flow06AlignmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Final concrete service combines aligned PRODUCT outbound rules,
        // reservation diagnostics and the BA scan-driven inbound behavior,
        // including safe auto-materialization of a SKU first seen in a QR scan.
        $this->app->bind(CurrentBaselineStockService::class, BaM1CurrentBaselineStockService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Route::middleware(['api', 'auth:api'])->prefix('api/v1')->group(function (): void {
                // BA 2026-08-25: Post Issue is a stock transaction. Packing Label is
                // an independent operational transaction and cannot gate/rollback it.
                Route::post('outbound-documents/{id}/post-issue', [BaM1OutboundFlowController::class, 'postIssue'])
                    ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);

                // Multi-defect + canonical estimated completion date. The BA adapter
                // only normalizes aliases; persistence remains in the canonical controller.
                Route::post('warranty-cases', [BaM1WarrantyController::class, 'storeWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::get('warranty-cases/{id}', [BaM1WarrantyController::class, 'showWarrantyCase'])
                    ->middleware('permission:warranty.view|warranty.manage');
                Route::patch('warranty-cases/{id}', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');
                Route::post('warranty-cases/{id}/status', [BaM1WarrantyController::class, 'updateWarrantyCase'])
                    ->middleware('permission:warranty.manage');

                // Packing label remains available as its own transaction; it no
                // longer participates in the Post Issue commit boundary.
                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
                Route::post('label-snapshots/{id}/apply', [AlignedOutboundFlowController::class, 'applyPackingLabel'])
                    ->middleware('permission:label.print');
            });
        });
    }
}
