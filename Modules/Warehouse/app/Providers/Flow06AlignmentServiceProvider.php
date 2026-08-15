<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedImportMatchReportController;
use Modules\Warehouse\Http\Controllers\AlignedLabelLogReportController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundDocumentController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
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
                Route::get('outbound-documents', [AlignedOutboundDocumentController::class, 'index'])
                    ->middleware('permission:outbound.view');
                Route::post('outbound-documents/{id}/scan', [AlignedOutboundFlowController::class, 'outboundScan'])
                    ->middleware('permission:outbound.scan');
                Route::post('outbound-documents/{id}/unmatch', [AlignedOutboundFlowController::class, 'outboundUnmatch'])
                    ->middleware('permission:outbound.scan');
                Route::post('outbound-documents/{id}/post-issue', [AlignedOutboundFlowController::class, 'postIssue'])
                    ->middleware('permission:outbound.post');

                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
                Route::post('label-snapshots/{id}/apply', [AlignedOutboundFlowController::class, 'applyPackingLabel'])
                    ->middleware('permission:label.print');

                Route::get('reports/import-match', [AlignedImportMatchReportController::class, 'index'])
                    ->middleware('permission:report.view');
                Route::get('reports/import-match/export-excel', [AlignedImportMatchReportController::class, 'export'])
                    ->middleware('permission:report.export');
                Route::get('reports/label-log', [AlignedLabelLogReportController::class, 'index'])
                    ->middleware('permission:report.view');
                Route::get('reports/label-log/export-excel', [AlignedLabelLogReportController::class, 'export'])
                    ->middleware('permission:report.export');
            });
        });
    }
}
