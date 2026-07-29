<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ReportController;
use Modules\Warehouse\Http\Controllers\SafeStockAlertController;
use Modules\Warehouse\Http\Controllers\SafeStockConfigController;
use Modules\Warehouse\Http\Controllers\StockDocumentController;
use Modules\Warehouse\Http\Controllers\StockRequestController;
use Modules\Warehouse\Http\Controllers\WarehouseController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:master-data.warehouse.view');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:master-data.warehouse.create');
    Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:master-data.warehouse.view');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:master-data.product.view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:master-data.product.create');
    Route::get('products/{id}', [ProductController::class, 'show'])->middleware('permission:master-data.product.view');

    Route::get('stock-documents', [StockDocumentController::class, 'index'])->middleware('permission:warehouse.stock-document.view');
    Route::post('stock-documents', [StockDocumentController::class, 'store'])->middleware('permission:warehouse.stock-document.create');
    Route::get('stock-documents/{id}', [StockDocumentController::class, 'show'])->middleware('permission:warehouse.stock-document.view');
    Route::post('stock-documents/{id}/submit', [StockDocumentController::class, 'submit'])->middleware('permission:warehouse.stock-document.submit');
    Route::post('stock-documents/{id}/approve', [StockDocumentController::class, 'approve'])->middleware('permission:warehouse.stock-document.approve');
    Route::post('stock-documents/{id}/reject', [StockDocumentController::class, 'reject'])->middleware('permission:warehouse.stock-document.approve');
    Route::post('stock-documents/{id}/post', [StockDocumentController::class, 'post'])->middleware('permission:warehouse.stock-document.post');
    Route::post('stock-documents/{id}/cancel', [StockDocumentController::class, 'cancel'])->middleware('permission:warehouse.stock-document.cancel');
    // Điều chuyển (transfer) only:
    Route::post('stock-documents/{id}/dispatch', [StockDocumentController::class, 'dispatch'])->middleware('permission:warehouse.stock-document.post');
    Route::post('stock-documents/{id}/receive', [StockDocumentController::class, 'receive'])->middleware('permission:warehouse.stock-document.post');
    Route::post('stock-documents/{id}/record-loss', [StockDocumentController::class, 'recordLoss'])->middleware('permission:warehouse.stock-document.post');
    Route::post('stock-documents/{id}/return', [StockDocumentController::class, 'returnTransfer'])->middleware('permission:warehouse.stock-document.post');
    Route::post('stock-documents/{id}/confirm-return', [StockDocumentController::class, 'confirmReturn'])->middleware('permission:warehouse.stock-document.post');

    Route::get('stock-requests', [StockRequestController::class, 'index'])->middleware('permission:warehouse.stock-request.view');
    Route::post('stock-requests', [StockRequestController::class, 'store'])->middleware('permission:warehouse.stock-request.create');
    Route::get('stock-requests/{id}', [StockRequestController::class, 'show'])->middleware('permission:warehouse.stock-request.view');
    Route::post('stock-requests/{id}/submit', [StockRequestController::class, 'submit'])->middleware('permission:warehouse.stock-request.submit');
    Route::post('stock-requests/{id}/approve', [StockRequestController::class, 'approve'])->middleware('permission:warehouse.stock-request.approve');
    Route::post('stock-requests/{id}/reject', [StockRequestController::class, 'reject'])->middleware('permission:warehouse.stock-request.approve');
    Route::post('stock-requests/{id}/cancel', [StockRequestController::class, 'cancel'])->middleware('permission:warehouse.stock-request.cancel');
    Route::post('stock-requests/{id}/convert', [StockRequestController::class, 'convert'])->middleware('permission:warehouse.stock-request.convert');

    Route::get('safe-stock-configs', [SafeStockConfigController::class, 'index'])->middleware('permission:warehouse.safe-stock.view');
    Route::post('safe-stock-configs', [SafeStockConfigController::class, 'store'])->middleware('permission:warehouse.safe-stock.manage');
    Route::get('safe-stock-configs/{id}', [SafeStockConfigController::class, 'show'])->middleware('permission:warehouse.safe-stock.view');
    Route::get('safe-stock-alerts', [SafeStockAlertController::class, 'index'])->middleware('permission:warehouse.safe-stock.view');

    Route::get('reports/inventory-summary', [ReportController::class, 'inventorySummary'])->middleware('permission:report.inventory.view');
    Route::get('reports/stock-movement-summary', [ReportController::class, 'stockMovementSummary'])->middleware('permission:report.inventory.view');
});
