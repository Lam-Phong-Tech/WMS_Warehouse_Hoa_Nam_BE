<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\InventoryBalanceController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ReportController;
use Modules\Warehouse\Http\Controllers\SafeStockAlertController;
use Modules\Warehouse\Http\Controllers\SafeStockConfigController;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Http\Controllers\WarehouseController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    // Kho tổng — chỉ tra cứu + sửa thông tin, không tạo/xoá qua API (1 kho tổng duy nhất).
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouse.view');
    Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:warehouse.view');
    Route::patch('warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.manage');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:product.view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:product.manage');
    Route::get('products/{id}', [ProductController::class, 'show'])->middleware('permission:product.view');

    // Nhập kho (inbound-documents = stock_documents.doc_type=receipt).
    Route::get('inbound-documents', [InboundDocumentController::class, 'index'])->middleware('permission:stock_document.view');
    Route::post('inbound-documents', [InboundDocumentController::class, 'store'])->middleware('permission:stock_document.create');
    Route::get('inbound-documents/{id}', [InboundDocumentController::class, 'show'])->middleware('permission:stock_document.view');
    Route::patch('inbound-documents/{id}', [InboundDocumentController::class, 'update'])->middleware('permission:stock_document.create');
    Route::post('inbound-documents/{id}/submit', [InboundDocumentController::class, 'submit'])->middleware('permission:stock_document.submit');
    Route::post('inbound-documents/{id}/approve', [InboundDocumentController::class, 'approve'])->middleware('permission:stock_document.approve');
    Route::post('inbound-documents/{id}/reject', [InboundDocumentController::class, 'reject'])->middleware('permission:stock_document.approve');
    Route::post('inbound-documents/{id}/cancel', [InboundDocumentController::class, 'cancel'])->middleware('permission:stock_document.cancel');
    Route::post('inbound-documents/{id}/post-receipt', [InboundDocumentController::class, 'postReceipt'])->middleware('permission:stock_document.post');
    Route::post('inbound-documents/{id}/scan', [InboundDocumentController::class, 'scan'])->middleware('permission:stock_document.post');
    Route::post('inbound-documents/{id}/close-remainder', [InboundDocumentController::class, 'closeRemainder'])->middleware('permission:stock_document.post');
    Route::post('inbound-documents/{id}/reverse', [InboundDocumentController::class, 'reverse'])->middleware('permission:stock_document.reverse');

    // Xuất kho (outbound-documents = stock_documents.doc_type=issue).
    Route::get('outbound-documents', [OutboundDocumentController::class, 'index'])->middleware('permission:stock_document.view');
    Route::post('outbound-documents', [OutboundDocumentController::class, 'store'])->middleware('permission:stock_document.create');
    Route::get('outbound-documents/{id}', [OutboundDocumentController::class, 'show'])->middleware('permission:stock_document.view');
    Route::patch('outbound-documents/{id}', [OutboundDocumentController::class, 'update'])->middleware('permission:stock_document.create');
    Route::post('outbound-documents/{id}/submit', [OutboundDocumentController::class, 'submit'])->middleware('permission:stock_document.submit');
    Route::post('outbound-documents/{id}/approve', [OutboundDocumentController::class, 'approve'])->middleware('permission:stock_document.approve');
    Route::post('outbound-documents/{id}/reject', [OutboundDocumentController::class, 'reject'])->middleware('permission:stock_document.approve');
    Route::post('outbound-documents/{id}/cancel', [OutboundDocumentController::class, 'cancel'])->middleware('permission:stock_document.cancel');
    Route::post('outbound-documents/{id}/post-issue', [OutboundDocumentController::class, 'postIssue'])->middleware('permission:stock_document.post');
    Route::post('outbound-documents/{id}/scan', [OutboundDocumentController::class, 'scan'])->middleware('permission:stock_document.post');
    Route::post('outbound-documents/{id}/close-remainder', [OutboundDocumentController::class, 'closeRemainder'])->middleware('permission:stock_document.post');
    Route::post('outbound-documents/{id}/reverse', [OutboundDocumentController::class, 'reverse'])->middleware('permission:stock_document.reverse');

    Route::get('safe-stock-configs', [SafeStockConfigController::class, 'index'])->middleware('permission:safe_stock.view');
    Route::post('safe-stock-configs', [SafeStockConfigController::class, 'store'])->middleware('permission:safe_stock.manage');
    Route::get('safe-stock-configs/{id}', [SafeStockConfigController::class, 'show'])->middleware('permission:safe_stock.view');
    Route::get('safe-stock-alerts', [SafeStockAlertController::class, 'index'])->middleware('permission:safe_stock.view');

    // Tồn kho hiện tại + lịch sử biến động (inventory_movement).
    Route::get('inventory/balances', [InventoryBalanceController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('stock-movements/{id}', [StockMovementController::class, 'show'])->middleware('permission:inventory.view');
    Route::get('stock-movements/{id}/trace-source', [StockMovementController::class, 'traceSource'])->middleware('permission:inventory.view');

    Route::get('reports/inventory-xnt', [ReportController::class, 'inventoryXnt'])->middleware('permission:report.view');
    Route::get('reports/inventory-xnt/export-excel', [ReportController::class, 'exportExcel'])->middleware('permission:report.view');
    Route::get('reports/dashboard-quick', [ReportController::class, 'dashboardQuick'])->middleware('permission:report.view');
});
