<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\CurrentBaselineItemIdentityController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineSkuController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ProductStatusCompatibilityController;
use Modules\Warehouse\Http\Controllers\ReportController;
use Modules\Warehouse\Http\Controllers\ScanController;
use Modules\Warehouse\Http\Controllers\WarrantyController;

Route::prefix('v1')->middleware('auth:api')->group(function (): void {
    Route::patch('products/{id}/enable', [ProductStatusCompatibilityController::class, 'enable'])
        ->middleware('permission:sku.manage');
    Route::patch('products/{id}/disable', [ProductStatusCompatibilityController::class, 'disable'])
        ->middleware('permission:sku.manage');

    Route::get('skus', [ProductController::class, 'index'])
        ->middleware('permission:sku.view');
    Route::get('skus/{id}', [ProductController::class, 'show'])
        ->whereUuid('id')
        ->middleware('permission:sku.view');
    Route::post('skus', [CurrentBaselineSkuController::class, 'store'])
        ->middleware('permission:sku.manage');
    Route::patch('skus/{id}', [CurrentBaselineSkuController::class, 'update'])
        ->whereUuid('id')
        ->middleware('permission:sku.manage');
    Route::post('skus/{id}/physical-codes', [CurrentBaselineItemIdentityController::class, 'assign'])
        ->whereUuid('id')
        ->middleware('permission:physical_code.assign_rfid');
    Route::delete('skus/{id}/physical-codes/{physicalCodeId}', [CurrentBaselineItemIdentityController::class, 'revoke'])
        ->whereUuid('id')
        ->whereUuid('physicalCodeId')
        ->middleware('permission:physical_code.assign_rfid');

    Route::post('scan/classify', [ScanController::class, 'classify'])
        ->middleware('permission:scan.classify');

    Route::get('reports/inventory-reconciliation', [ReportController::class, 'inventoryReconciliation'])
        ->middleware('permission:inventory.trace');

    Route::post('warranty-cases/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.manage');
});
