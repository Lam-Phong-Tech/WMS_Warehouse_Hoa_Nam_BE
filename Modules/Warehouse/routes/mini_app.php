<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppInboundController;
use Modules\Warehouse\Http\Controllers\MiniAppInboundStagingController;
use Modules\Warehouse\Http\Controllers\MiniAppOutboundController;
use Modules\Warehouse\Http\Middleware\EnforceInboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Http\Middleware\EnsureInboundComponentBox;

/*
|--------------------------------------------------------------------------
| Warehouse Mini App API
|--------------------------------------------------------------------------
|
| Thin mobile adapters over the canonical WMS domain. Mini App does not own a
| second stock state machine. Inbound changes stock only at Post Receipt;
| outbound changes stock only at Post Issue.
|
*/
Route::prefix('v1/mini-app')->middleware('auth:api')->group(function (): void {
    // ── Inbound ──────────────────────────────────────────────────────────
    Route::post('inbound/resolve-code', [MiniAppInboundStagingController::class, 'resolveCode'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::post('inbound/record', [MiniAppInboundStagingController::class, 'record'])
        ->middleware([
            'permission:inbound.create',
            'permission:inbound.scan',
            EnforceInboundWarehouseScope::class,
            EnsureCanonicalInboundWarehouse::class,
        ]);

    Route::get('inbound-documents', [MiniAppInboundController::class, 'index'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents', [MiniAppInboundController::class, 'store'])
        ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);

    Route::get('inbound-documents/{id}', [MiniAppInboundController::class, 'show'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/cancel', [MiniAppInboundController::class, 'cancel'])
        ->middleware(['permission:inbound.cancel|inbound.create', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/boxes', [MiniAppInboundController::class, 'registerBox'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/scan', [MiniAppInboundController::class, 'scan'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class, EnsureInboundComponentBox::class]);

    Route::post('inbound-documents/{id}/unscan', [MiniAppInboundController::class, 'unscan'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::get('inbound-documents/{id}/scan-entries', [MiniAppInboundController::class, 'scanEntries'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/cartons/{containerId}/complete-scan', [MiniAppInboundController::class, 'completeCartonScan'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/post-receipt', [MiniAppInboundController::class, 'approve'])
        ->middleware(['permission:inbound.post', EnforceInboundWarehouseScope::class]);

    // ── Outbound ─────────────────────────────────────────────────────────
    // No POST /outbound-documents here: canonical outbound DRAFT is created only
    // by confirming an outbound request/import. Mini App consumes that queue.
    Route::get('outbound-documents', [MiniAppOutboundController::class, 'index'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents/{id}', [MiniAppOutboundController::class, 'show'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/cancel', [MiniAppOutboundController::class, 'cancel'])
        ->middleware(['permission:outbound.cancel', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/scan', [MiniAppOutboundController::class, 'scan'])
        ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/unmatch', [MiniAppOutboundController::class, 'unmatch'])
        ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents/{id}/scan-entries', [MiniAppOutboundController::class, 'scanEntries'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents/{id}/packing-label', [MiniAppOutboundController::class, 'packingLabel'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/preview', [MiniAppOutboundController::class, 'previewPackingLabel'])
        ->middleware(['permission:packing_label.preview|label.print', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/ready', [MiniAppOutboundController::class, 'readyPackingLabel'])
        ->middleware(['permission:packing_label.ready|label.print', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/print', [MiniAppOutboundController::class, 'printPackingLabel'])
        ->middleware(['permission:packing_label.print|label.print', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/reprint', [MiniAppOutboundController::class, 'reprintPackingLabel'])
        ->middleware(['permission:packing_label.reprint|label.reprint', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/apply', [MiniAppOutboundController::class, 'applyPackingLabel'])
        ->middleware(['permission:label.print', EnforceOutboundWarehouseScope::class]);

    // This is the only Mini App outbound endpoint that decreases inventory.
    Route::post('outbound-documents/{id}/post-issue', [MiniAppOutboundController::class, 'postIssue'])
        ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);
});
