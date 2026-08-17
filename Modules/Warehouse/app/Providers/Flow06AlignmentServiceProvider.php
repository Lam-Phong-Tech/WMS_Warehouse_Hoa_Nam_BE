<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedOutboundDocumentController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Services\AlignedOutboundStockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Runtime overrides for the 2026-08-15 current business baseline.
 * They are registered after the normal Warehouse routes so identical method/URI
 * pairs resolve to these canonical handlers without keeping two active semantics.
 */
class Flow06AlignmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurrentBaselineStockService::class, AlignedOutboundStockService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Route::middleware(['api', 'auth:api'])->prefix('api/v1')->group(function (): void {
                // Override giữ nguyên cả permission lẫn data scope (§8.1 Enforcement
                // standard) — nếu bỏ scope ở đây thì route gốc bị thay thế và mất guard.
                Route::get('outbound-documents', [AlignedOutboundDocumentController::class, 'index'])
                    ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
                Route::post('outbound-documents/{id}/scan', [AlignedOutboundFlowController::class, 'outboundScan'])
                    ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
                Route::post('outbound-documents/{id}/unmatch', [AlignedOutboundFlowController::class, 'outboundUnmatch'])
                    ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
                Route::post('outbound-documents/{id}/post-issue', [AlignedOutboundFlowController::class, 'postIssue'])
                    ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);

                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
                Route::post('label-snapshots/{id}/apply', [AlignedOutboundFlowController::class, 'applyPackingLabel'])
                    ->middleware('permission:label.print');

                // Toàn bộ route Flow 06 (import-match, label-log, warranty-defects)
                // đã trỏ thẳng controller Aligned trong routes/api.php nên không còn
                // override runtime nào cho báo cáo ở đây — chỉ giữ override luồng xuất.
            });
        });
    }
}
