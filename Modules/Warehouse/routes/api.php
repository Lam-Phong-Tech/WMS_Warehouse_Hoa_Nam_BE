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
    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses', [WarehouseController::class, 'store']);
    Route::get('warehouses/{id}', [WarehouseController::class, 'show']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{id}', [ProductController::class, 'show']);

    Route::get('stock-documents', [StockDocumentController::class, 'index']);
    Route::post('stock-documents', [StockDocumentController::class, 'store']);
    Route::get('stock-documents/{id}', [StockDocumentController::class, 'show']);
    Route::post('stock-documents/{id}/submit', [StockDocumentController::class, 'submit']);
    Route::post('stock-documents/{id}/approve', [StockDocumentController::class, 'approve']);
    Route::post('stock-documents/{id}/reject', [StockDocumentController::class, 'reject']);
    Route::post('stock-documents/{id}/post', [StockDocumentController::class, 'post']);
    Route::post('stock-documents/{id}/cancel', [StockDocumentController::class, 'cancel']);
    // Điều chuyển (transfer) only:
    Route::post('stock-documents/{id}/dispatch', [StockDocumentController::class, 'dispatch']);
    Route::post('stock-documents/{id}/receive', [StockDocumentController::class, 'receive']);
    Route::post('stock-documents/{id}/record-loss', [StockDocumentController::class, 'recordLoss']);
    Route::post('stock-documents/{id}/return', [StockDocumentController::class, 'returnTransfer']);
    Route::post('stock-documents/{id}/confirm-return', [StockDocumentController::class, 'confirmReturn']);

    Route::get('stock-requests', [StockRequestController::class, 'index']);
    Route::post('stock-requests', [StockRequestController::class, 'store']);
    Route::get('stock-requests/{id}', [StockRequestController::class, 'show']);
    Route::post('stock-requests/{id}/submit', [StockRequestController::class, 'submit']);
    Route::post('stock-requests/{id}/approve', [StockRequestController::class, 'approve']);
    Route::post('stock-requests/{id}/reject', [StockRequestController::class, 'reject']);
    Route::post('stock-requests/{id}/cancel', [StockRequestController::class, 'cancel']);
    Route::post('stock-requests/{id}/convert', [StockRequestController::class, 'convert']);

    Route::get('safe-stock-configs', [SafeStockConfigController::class, 'index']);
    Route::post('safe-stock-configs', [SafeStockConfigController::class, 'store']);
    Route::get('safe-stock-configs/{id}', [SafeStockConfigController::class, 'show']);
    Route::get('safe-stock-alerts', [SafeStockAlertController::class, 'index']);

    Route::get('reports/inventory-summary', [ReportController::class, 'inventorySummary']);
    Route::get('reports/stock-movement-summary', [ReportController::class, 'stockMovementSummary']);
});
