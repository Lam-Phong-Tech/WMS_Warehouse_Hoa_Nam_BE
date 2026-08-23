<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppInboundController;
use Modules\Warehouse\Http\Middleware\EnforceInboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Http\Middleware\EnsureInboundComponentBox;

/*
|--------------------------------------------------------------------------
| Warehouse Mini App API
|--------------------------------------------------------------------------
|
| Adapter routes for the mobile/mini-app inbound workflow. These routes reuse
| the canonical inbound services; they do not create a parallel stock state
| machine. Only post-receipt changes inventory. The Mini App may label that
| action "Phê duyệt", but the backend transition remains SCANNING -> POSTED.
|
*/
Route::prefix('v1/mini-app')->middleware('auth:api')->group(function (): void {
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

    // UI action can be labelled "Phê duyệt". The API deliberately keeps the
    // canonical Full Post Receipt path/state transition and requires inbound.post.
    Route::post('inbound-documents/{id}/post-receipt', [MiniAppInboundController::class, 'approve'])
        ->middleware(['permission:inbound.post', EnforceInboundWarehouseScope::class]);
});
