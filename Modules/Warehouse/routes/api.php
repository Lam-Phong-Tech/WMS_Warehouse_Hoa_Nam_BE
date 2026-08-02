<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\DocumentStatusHistoryController;
use Modules\Warehouse\Http\Controllers\DocumentStatusTransitionController;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\ImportController;
use Modules\Warehouse\Http\Controllers\InventoryBalanceController;
use Modules\Warehouse\Http\Controllers\InventoryReservationController;
use Modules\Warehouse\Http\Controllers\InventorySnapshotController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ReportController;
use Modules\Warehouse\Http\Controllers\SafeStockAlertController;
use Modules\Warehouse\Http\Controllers\SafeStockConfigController;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Http\Controllers\StockRequestController;
use Modules\Warehouse\Http\Controllers\StocktakeController;
use Modules\Warehouse\Http\Controllers\TransferController;
use Modules\Warehouse\Http\Controllers\ScanController;
use Modules\Warehouse\Http\Controllers\WarrantyController;
use Modules\Warehouse\Http\Controllers\WarehouseController;
use Modules\Warehouse\Http\Controllers\WmsContractController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    // Kho tổng — chỉ tra cứu + sửa thông tin, không tạo/xoá qua API (1 kho tổng duy nhất).
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouse.view');
    Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:warehouse.view');
    Route::patch('warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.manage');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:product.view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:product.manage');
    Route::get('products/{id}', [ProductController::class, 'show'])->middleware('permission:product.view');
    Route::patch('products/{id}/enable', [ProductController::class, 'enable'])->middleware('permission:product.manage');
    Route::patch('products/{id}/disable', [ProductController::class, 'disable'])->middleware('permission:product.manage');

    Route::get('product-categories', [WmsContractController::class, 'productCategories'])->middleware('permission:master.manage');
    Route::get('uoms', [WmsContractController::class, 'uoms'])->middleware('permission:master.manage');
    Route::get('suppliers', [WmsContractController::class, 'suppliers'])->middleware('permission:master.manage');
    Route::get('skus', [ProductController::class, 'index'])->middleware('permission:sku.manage');
    Route::post('skus', [ProductController::class, 'store'])->middleware('permission:sku.manage');
    Route::get('skus/{id}', [ProductController::class, 'show'])->middleware('permission:sku.manage');
    Route::post('skus/import-excel', [ImportController::class, 'importExcel'])->middleware('permission:sku.manage');
    Route::get('items', [WmsContractController::class, 'items'])->middleware('permission:inventory.view');
    Route::get('physical-codes', [WmsContractController::class, 'physicalCodes'])->middleware('permission:inventory.view');

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
    Route::post('inbound-documents/{id}/scan', [InboundDocumentController::class, 'scan'])->middleware('permission:stock_document.scan');
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
    Route::post('outbound-documents/{id}/scan', [OutboundDocumentController::class, 'scan'])->middleware('permission:stock_document.scan');
    Route::post('outbound-documents/{id}/close-remainder', [OutboundDocumentController::class, 'closeRemainder'])->middleware('permission:stock_document.post');
    Route::post('outbound-documents/{id}/reverse', [OutboundDocumentController::class, 'reverse'])->middleware('permission:stock_document.reverse');

    Route::get('safe-stock-configs', [SafeStockConfigController::class, 'index'])->middleware('permission:safe_stock.view');
    Route::post('safe-stock-configs', [SafeStockConfigController::class, 'store'])->middleware('permission:safe_stock.manage');
    Route::get('safe-stock-configs/{id}', [SafeStockConfigController::class, 'show'])->middleware('permission:safe_stock.view');
    Route::patch('safe-stock-configs/{id}', [SafeStockConfigController::class, 'update'])->middleware('permission:safe_stock.manage');
    Route::delete('safe-stock-configs/{id}', [SafeStockConfigController::class, 'destroy'])->middleware('permission:safe_stock.manage');
    Route::get('safe-stock-alerts', [SafeStockAlertController::class, 'index'])->middleware('permission:safe_stock.view');
    Route::get('safe-stock-alerts/history', [SafeStockAlertController::class, 'history'])->middleware('permission:safe_stock.view');

    Route::get('putaway/tasks', [WmsContractController::class, 'putawayTasks'])->middleware('permission:inventory.view');
    Route::get('putaway/history', [WmsContractController::class, 'putawayHistory'])->middleware('permission:inventory.view');
    Route::get('stocktake/sessions', [WmsContractController::class, 'stocktakeSessions'])->middleware('permission:inventory.view');
    Route::post('stocktake-sessions/{id}/apply-adjustment', [StocktakeController::class, 'applyAdjustment'])->middleware('permission:stocktake.adjust');
    Route::get('reversal-documents', [WmsContractController::class, 'reversalDocuments'])->middleware('permission:stock_document.reverse');
    Route::get('warranty/cases', [WmsContractController::class, 'warrantyCases'])->middleware('permission:inventory.view');
    Route::get('warranty/cases/{caseId}/events', [WmsContractController::class, 'warrantyEvents'])->middleware('permission:inventory.view');
    Route::post('warranty-cases/{id}/temp-only', [WarrantyController::class, 'tempOnly'])->middleware('permission:warranty.temp_only');
    Route::post('scan/classify', [ScanController::class, 'classify'])->middleware('permission:scan.classify');
    Route::get('scan/batches', [WmsContractController::class, 'scanBatches'])->middleware('permission:inventory.view');
    Route::post('scan/batches', [WmsContractController::class, 'storeScanBatch'])->middleware('permission:inventory.view');
    Route::get('scan/events', [WmsContractController::class, 'scanEvents'])->middleware('permission:inventory.view');

    // Tồn kho hiện tại + lịch sử biến động (inventory_movement).
    Route::get('inventory/balances', [InventoryBalanceController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('stock-movements/{id}', [StockMovementController::class, 'show'])->middleware('permission:inventory.view');
    Route::get('stock-movements/{id}/trace-source', [StockMovementController::class, 'traceSource'])->middleware('permission:inventory.view');

    // Đặt giữ tồn kho (non-MVP, read-only, chưa wire vào luồng xuất kho).
    Route::get('inventory-reservations', [InventoryReservationController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('inventory-reservations/{id}', [InventoryReservationController::class, 'show'])->middleware('permission:inventory.view');

    // Snapshot tồn kho cuối kỳ.
    Route::get('inventory-snapshots', [InventorySnapshotController::class, 'index'])->middleware('permission:inventory.view');
    Route::post('inventory-snapshots/generate', [InventorySnapshotController::class, 'generate'])->middleware('permission:warehouse.manage');

    // Lịch sử chuyển trạng thái chứng từ (read-only).
    Route::get('document-status-history', [DocumentStatusHistoryController::class, 'index'])->middleware('permission:stock_document.view');

    // Danh mục tham khảo state machine (không phải nguồn enforcement thực tế).
    Route::get('document-status-transitions', [DocumentStatusTransitionController::class, 'index'])->middleware('permission:warehouse.view');
    Route::patch('document-status-transitions/{id}/toggle', [DocumentStatusTransitionController::class, 'toggle'])->middleware('permission:warehouse.manage');

    // Yêu cầu xuất/nhập/điều chuyển kho (tiền duyệt trước khi tạo stock_documents).
    Route::get('stock-requests', [StockRequestController::class, 'index'])->middleware('permission:stock_request.view');
    Route::post('stock-requests', [StockRequestController::class, 'store'])->middleware('permission:stock_request.create');
    Route::get('stock-requests/{id}', [StockRequestController::class, 'show'])->middleware('permission:stock_request.view');
    Route::patch('stock-requests/{id}', [StockRequestController::class, 'update'])->middleware('permission:stock_request.create');
    Route::delete('stock-requests/{id}', [StockRequestController::class, 'destroy'])->middleware('permission:stock_request.create');
    Route::post('stock-requests/{id}/submit', [StockRequestController::class, 'submit'])->middleware('permission:stock_request.submit');
    Route::post('stock-requests/{id}/approve', [StockRequestController::class, 'approve'])->middleware('permission:stock_request.approve');
    Route::post('stock-requests/{id}/reject', [StockRequestController::class, 'reject'])->middleware('permission:stock_request.approve');
    Route::post('stock-requests/{id}/convert', [StockRequestController::class, 'convert'])->middleware('permission:stock_request.approve');
    Route::post('stock-requests/{id}/cancel', [StockRequestController::class, 'cancel'])->middleware('permission:stock_request.create');

    // Điều chuyển kho — cần ≥ 2 kho khác nhau để dùng thật (xem ghi chú trong TransferController).
    Route::get('transfer-orders', [TransferController::class, 'index'])->middleware('permission:transfer.view');
    Route::post('transfer-orders', [TransferController::class, 'store'])->middleware('permission:transfer.create');
    Route::get('transfer-orders/{id}', [TransferController::class, 'show'])->middleware('permission:transfer.view');
    Route::patch('transfer-orders/{id}', [TransferController::class, 'update'])->middleware('permission:transfer.create');
    Route::post('transfer-orders/{id}/submit', [TransferController::class, 'submit'])->middleware('permission:transfer.submit');
    Route::post('transfer-orders/{id}/approve', [TransferController::class, 'approve'])->middleware('permission:transfer.approve');
    Route::post('transfer-orders/{id}/reject', [TransferController::class, 'reject'])->middleware('permission:transfer.approve');
    Route::post('transfer-orders/{id}/cancel', [TransferController::class, 'cancel'])->middleware('permission:transfer.create');
    Route::post('transfer-orders/{id}/dispatch', [TransferController::class, 'dispatch'])->middleware('permission:transfer.post');
    Route::post('transfer-orders/{id}/receive', [TransferController::class, 'receive'])->middleware('permission:transfer.post');
    Route::post('transfer-orders/{id}/record-loss', [TransferController::class, 'recordLoss'])->middleware('permission:transfer.post');
    Route::post('transfer-orders/{id}/return', [TransferController::class, 'returnTransfer'])->middleware('permission:transfer.post');
    Route::post('transfer-orders/{id}/confirm-return', [TransferController::class, 'confirmReturn'])->middleware('permission:transfer.post');

    Route::get('reports/inventory-xnt', [ReportController::class, 'inventoryXnt'])->middleware('permission:report.view');
    Route::get('reports/inventory-xnt/export-excel', [ReportController::class, 'exportExcel'])->middleware('permission:report.view');
    Route::get('reports/dashboard-quick', [ReportController::class, 'dashboardQuick'])->middleware('permission:report.view');
    Route::get('reports/inventory-reconciliation', [ReportController::class, 'inventoryReconciliation'])->middleware('permission:report.view');
});
