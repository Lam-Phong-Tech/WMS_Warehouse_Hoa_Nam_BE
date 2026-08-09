<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\SkuGovernanceController;
use Modules\Warehouse\Http\Controllers\StocktakeController;
use Modules\Warehouse\Http\Controllers\WarrantyController;

/*
 * Governance/compatibility routes loaded after the base Warehouse routes.
 * For duplicate method+URI pairs Laravel keeps the final route definition, so
 * the scan endpoints below correct the permission gate without touching the
 * stable base route file.
 */
Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::post('skus', [SkuGovernanceController::class, 'store'])
        ->middleware('permission:product.manage');
    Route::patch('skus/{id}', [SkuGovernanceController::class, 'update'])
        ->middleware('permission:product.manage');
    Route::post('skus/{id}/physical-codes', [SkuGovernanceController::class, 'addPhysicalCode'])
        ->middleware('permission:product.manage');
    Route::delete('skus/{id}/physical-codes/{physicalCodeId}', [SkuGovernanceController::class, 'revokePhysicalCode'])
        ->middleware('permission:product.manage');

    Route::post('inbound-documents/{id}/scan', [InboundDocumentController::class, 'scan'])
        ->middleware('permission:stock_document.scan');
    Route::post('outbound-documents/{id}/scan', [OutboundDocumentController::class, 'scan'])
        ->middleware('permission:stock_document.scan');

    // Canonical stocktake lifecycle. The controller/service already implements
    // these transactions; the missing route wiring was causing the 404 gate.
    Route::get('stocktake-sessions', [StocktakeController::class, 'index'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake-sessions', [StocktakeController::class, 'store'])
        ->middleware('permission:stocktake.adjust');
    Route::get('stocktake-sessions/{id}', [StocktakeController::class, 'show'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake-sessions/{id}/start', [StocktakeController::class, 'start'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake-sessions/{id}/counts', [StocktakeController::class, 'recordCounts'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake-sessions/{id}/review', [StocktakeController::class, 'review'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake-sessions/{id}/acknowledge-movements', [StocktakeController::class, 'acknowledgeMovements'])
        ->middleware('permission:stocktake.adjust');
    Route::get('stocktake-sessions/{id}/variances', [StocktakeController::class, 'variances'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake-sessions/{id}/apply-adjustment', [StocktakeController::class, 'applyAdjustment'])
        ->middleware('permission:stocktake.adjust');

    // Contract alias retained for clients/DevOps checks using /stocktake/sessions.
    Route::get('stocktake/sessions', [StocktakeController::class, 'index'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake/sessions', [StocktakeController::class, 'store'])
        ->middleware('permission:stocktake.adjust');
    Route::get('stocktake/sessions/{id}', [StocktakeController::class, 'show'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake/sessions/{id}/start', [StocktakeController::class, 'start'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake/sessions/{id}/counts', [StocktakeController::class, 'recordCounts'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake/sessions/{id}/review', [StocktakeController::class, 'review'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake/sessions/{id}/acknowledge-movements', [StocktakeController::class, 'acknowledgeMovements'])
        ->middleware('permission:stocktake.adjust');
    Route::get('stocktake/sessions/{id}/variances', [StocktakeController::class, 'variances'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake/sessions/{id}/apply-adjustment', [StocktakeController::class, 'applyAdjustment'])
        ->middleware('permission:stocktake.adjust');

    // Warranty TEMP_ONLY is status-only; all aliases terminate in the same
    // idempotent controller method and remain protected by warranty.temp_only.
    Route::post('warranty-cases/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.temp_only');
    Route::post('warranty/cases/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.temp_only');
    Route::post('warranty/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.temp_only');
});
