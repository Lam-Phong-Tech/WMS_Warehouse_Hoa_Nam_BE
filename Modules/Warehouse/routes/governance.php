<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\DocumentStatusHistoryController;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\MasterCompatibilityController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ProductStatusCompatibilityController;
use Modules\Warehouse\Http\Controllers\ReportController;
use Modules\Warehouse\Http\Controllers\ScanController;
use Modules\Warehouse\Http\Controllers\SkuGovernanceController;
use Modules\Warehouse\Http\Controllers\StocktakeController;
use Modules\Warehouse\Http\Controllers\WarrantyController;

/*
 * Compatibility/governance routes are intentionally loaded after the base
 * Warehouse routes. Duplicate method+URI definitions here correct stale
 * middleware/contracts while every action still delegates to canonical code.
 */
Route::prefix('v1')->middleware('auth:api')->group(function (): void {
    // Legacy /products state actions. Storage remains sku.
    Route::patch('products/{id}/enable', [ProductStatusCompatibilityController::class, 'enable'])
        ->middleware('permission:product.manage');
    Route::patch('products/{id}/disable', [ProductStatusCompatibilityController::class, 'disable'])
        ->middleware('permission:product.manage');

    // Dedicated SKU aggregate compatibility contract.
    Route::get('skus', [ProductController::class, 'index'])
        ->middleware('permission:sku.manage');
    Route::get('skus/{id}', [ProductController::class, 'show'])
        ->whereUuid('id')
        ->middleware('permission:sku.manage');
    Route::post('skus', [SkuGovernanceController::class, 'store'])
        ->middleware('permission:sku.manage');
    Route::patch('skus/{id}', [SkuGovernanceController::class, 'update'])
        ->whereUuid('id')
        ->middleware('permission:sku.manage');
    Route::post('skus/{id}/physical-codes', [SkuGovernanceController::class, 'addPhysicalCode'])
        ->whereUuid('id')
        ->middleware('permission:sku.manage');
    Route::delete('skus/{id}/physical-codes/{physicalCodeId}', [SkuGovernanceController::class, 'revokePhysicalCode'])
        ->whereUuid('id')
        ->whereUuid('physicalCodeId')
        ->middleware('permission:sku.manage');

    // Legacy master URLs, backed only by canonical product_masters/SKU/import_batches.
    Route::get('product-categories', [MasterCompatibilityController::class, 'productCategories'])
        ->middleware('permission:master.manage');
    Route::get('uoms', [MasterCompatibilityController::class, 'uoms'])
        ->middleware('permission:master.manage');
    Route::get('suppliers', [MasterCompatibilityController::class, 'suppliers'])
        ->middleware('permission:master.manage');

    // Canonical read-only scan/classify boundary.
    Route::post('scan/classify', [ScanController::class, 'classify'])
        ->middleware('permission:scan.classify');

    // Correct stale base middleware: scan only validates, it is not stock posting.
    Route::post('inbound-documents/{id}/scan', [InboundDocumentController::class, 'scan'])
        ->middleware('permission:stock_document.scan');
    Route::post('outbound-documents/{id}/scan', [OutboundDocumentController::class, 'scan'])
        ->middleware('permission:stock_document.scan');

    // History is part of the stock-document read surface, not warehouse admin.
    Route::get('document-status-history', [DocumentStatusHistoryController::class, 'index'])
        ->middleware('permission:stock_document.view');

    // Existing reconciliation implementation was present but unwired.
    Route::get('reports/inventory-reconciliation', [ReportController::class, 'inventoryReconciliation'])
        ->middleware('permission:report.view');

    // Canonical stocktake lifecycle.
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

    // Contract alias retained for clients using /stocktake/sessions.
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

    // Warranty TEMP_ONLY aliases terminate in one idempotent implementation.
    Route::post('warranty-cases/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.temp_only');
    Route::post('warranty/cases/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.temp_only');
    Route::post('warranty/{id}/temp-only', [WarrantyController::class, 'tempOnly'])
        ->middleware('permission:warranty.temp_only');
});
