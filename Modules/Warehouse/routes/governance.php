<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\SkuGovernanceController;

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
});
