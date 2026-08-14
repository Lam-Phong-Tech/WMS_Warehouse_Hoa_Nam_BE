<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AgencyController;
use Modules\Warehouse\Http\Controllers\CanonicalMvpController;
use Modules\Warehouse\Http\Controllers\ContainerController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineComponentFlowController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineComponentIssueQueryController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineContainerFlowController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineDocumentController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineImportController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineImportReadController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineInboundBoxController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineMasterController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineOperationsController;
use Modules\Warehouse\Http\Controllers\CurrentBaselinePhysicalCodeController;
use Modules\Warehouse\Http\Controllers\CurrentBaselinePutawayTaskController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineStockFlowController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineWarrantyAttachmentController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineWarrantyController;
use Modules\Warehouse\Http\Controllers\DefectCatalogController;
use Modules\Warehouse\Http\Controllers\DocumentStatusHistoryController;
use Modules\Warehouse\Http\Controllers\ImportBatchController;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\InventoryBalanceController;
use Modules\Warehouse\Http\Controllers\ItemInstanceController;
use Modules\Warehouse\Http\Controllers\OperationalReportController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\OutboundRequestController;
use Modules\Warehouse\Http\Controllers\PhysicalCodeController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ProductMasterController;
use Modules\Warehouse\Http\Controllers\PutawayController;
use Modules\Warehouse\Http\Controllers\ReversalDocumentController;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Http\Controllers\WarehouseController;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Http\Middleware\EnsureInboundComponentBox;

Route::prefix('v1')->group(function (): void {
    Route::get('pv/catalog', [CurrentBaselineOperationsController::class, 'pvCatalog']);
    Route::get('pv/industries', [CanonicalMvpController::class, 'pvIndustries']);
    Route::get('pv/tags', [CanonicalMvpController::class, 'pvTags']);
    Route::get('pv/recommendations', [CurrentBaselineOperationsController::class, 'pvRecommendations']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouse.view');
        Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:warehouse.view');
        Route::patch('warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.manage');

        Route::get('products', [ProductController::class, 'index'])->middleware('permission:sku.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:sku.manage');
        Route::get('products/{id}', [ProductController::class, 'show'])->middleware('permission:sku.view');
        Route::patch('products/{id}', [ProductController::class, 'update'])->middleware('permission:sku.manage');

        Route::get('inbound-documents', [InboundDocumentController::class, 'index'])->middleware('permission:inbound.view');
        Route::post('inbound-documents', [CurrentBaselineDocumentController::class, 'storeInbound'])
            ->middleware(['permission:inbound.create', EnsureCanonicalInboundWarehouse::class]);
        Route::get('inbound-documents/{id}', [InboundDocumentController::class, 'show'])->middleware('permission:inbound.view');
        Route::patch('inbound-documents/{id}', [CurrentBaselineDocumentController::class, 'updateInbound'])
            ->middleware(['permission:inbound.create', EnsureCanonicalInboundWarehouse::class]);
        Route::post('inbound-documents/{id}/cancel', [CurrentBaselineDocumentController::class, 'cancelInbound'])->middleware('permission:inbound.cancel');
        Route::post('inbound-documents/{id}/boxes', [CurrentBaselineInboundBoxController::class, 'store'])->middleware('permission:inbound.scan');
        Route::post('inbound-documents/{id}/scan', [CurrentBaselineStockFlowController::class, 'inboundScan'])
            ->middleware(['permission:inbound.scan', EnsureInboundComponentBox::class]);
        Route::post('inbound-documents/{id}/post-receipt', [CurrentBaselineStockFlowController::class, 'postReceipt'])->middleware('permission:inbound.post');
        Route::post('inbound-documents/{id}/reverse', [CurrentBaselineStockFlowController::class, 'reverseInbound'])->middleware('permission:inventory.reversal.post');

        Route::get('putaway/tasks', [PutawayController::class, 'tasks'])->middleware('permission:container.view');
        Route::post('putaway/tasks', [CurrentBaselinePutawayTaskController::class, 'store'])->middleware('permission:container.manage');
        Route::post('putaway/tasks/{id}/confirm', [PutawayController::class, 'confirm'])->middleware('permission:container.manage');
        Route::post('putaway/tasks/{id}/cancel', [PutawayController::class, 'cancel'])->middleware('permission:container.manage');
        Route::get('putaway/history', [PutawayController::class, 'history'])->middleware('permission:container.view');

        Route::get('outbound-documents', [OutboundDocumentController::class, 'index'])->middleware('permission:outbound.view');
        Route::post('outbound-documents', [CurrentBaselineDocumentController::class, 'storeOutbound'])->middleware('permission:outbound.create');
        Route::get('outbound-documents/{id}', [OutboundDocumentController::class, 'show'])->middleware('permission:outbound.view');
        Route::patch('outbound-documents/{id}', [CurrentBaselineDocumentController::class, 'updateOutbound'])->middleware('permission:outbound.create');
        Route::post('outbound-documents/{id}/cancel', [CurrentBaselineDocumentController::class, 'cancelOutbound'])->middleware('permission:outbound.cancel');
        Route::post('outbound-documents/{id}/scan', [CurrentBaselineStockFlowController::class, 'outboundScan'])->middleware('permission:outbound.scan');
        Route::post('outbound-documents/{id}/unmatch', [CurrentBaselineStockFlowController::class, 'outboundUnmatch'])->middleware('permission:outbound.scan');
        Route::post('outbound-documents/{id}/post-issue', [CurrentBaselineStockFlowController::class, 'postIssue'])->middleware('permission:outbound.post');
        Route::post('outbound-documents/{id}/reverse', [CurrentBaselineStockFlowController::class, 'reverseOutbound'])->middleware('permission:inventory.reversal.post');

        Route::get('inventory/balances', [InventoryBalanceController::class, 'index'])->middleware('permission:inventory.view');
        Route::get('inventory/balances/{id}/subledger', [InventoryBalanceController::class, 'subledger'])->middleware('permission:inventory.view');
        Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('permission:inventory.view');
        Route::get('stock-movements/reversible', [StockMovementController::class, 'reversible'])->middleware('permission:inventory.reversal.post');
        Route::get('stock-movements/trace-by-code', [StockMovementController::class, 'traceByPhysicalCode'])->middleware('permission:inventory.trace');
        Route::get('stock-movements/{id}', [StockMovementController::class, 'show'])->middleware('permission:inventory.view');
        Route::get('stock-movements/{id}/trace-source', [StockMovementController::class, 'traceSource'])->middleware('permission:inventory.trace');

        Route::get('reversal-documents', [ReversalDocumentController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('reversal-documents', [ReversalDocumentController::class, 'store'])->middleware('permission:inventory.reversal.post');
        Route::get('reversal-documents/{id}', [ReversalDocumentController::class, 'show'])->middleware('permission:inventory.view');
        Route::get('reversal-documents/{id}/lines', [ReversalDocumentController::class, 'lines'])->middleware('permission:inventory.view');

        Route::get('document-status-history', [DocumentStatusHistoryController::class, 'index'])->middleware('permission:inventory.view');

        // Flow 06 · Báo cáo: read và export là hai quyền độc lập, BE enforce authoritative.
        Route::get('reports/hub', [OperationalReportController::class, 'hub'])->middleware('permission:report.view');
        Route::get('reports/inventory-balance', [OperationalReportController::class, 'inventoryBalance'])->middleware('permission:report.view');
        Route::get('reports/inventory-balance/export-excel', [OperationalReportController::class, 'inventoryBalanceExport'])->middleware('permission:report.export');
        Route::get('reports/inventory-xnt', [OperationalReportController::class, 'inventoryXnt'])->middleware('permission:report.view');
        Route::get('reports/inventory-xnt/export-excel', [OperationalReportController::class, 'inventoryXntExport'])->middleware('permission:report.export');
        Route::get('reports/inventory-xnt-enhanced', [OperationalReportController::class, 'inventoryXntEnhanced'])->middleware('permission:report.view');
        Route::get('reports/inventory-xnt-enhanced/export-excel', [OperationalReportController::class, 'inventoryXntEnhancedExport'])->middleware('permission:report.export');
        Route::get('reports/document-history', [OperationalReportController::class, 'documentHistory'])->middleware('permission:report.view');
        Route::get('reports/document-history/export-excel', [OperationalReportController::class, 'documentHistoryExport'])->middleware('permission:report.export');
        Route::get('reports/movement-trace', [OperationalReportController::class, 'movementTrace'])->middleware('permission:report.view');
        Route::get('reports/movement-trace/export-excel', [OperationalReportController::class, 'movementTraceExport'])->middleware('permission:report.export');
        Route::get('reports/import-match', [OperationalReportController::class, 'importMatch'])->middleware('permission:report.view');
        Route::get('reports/import-match/export-excel', [OperationalReportController::class, 'importMatchExport'])->middleware('permission:report.export');
        Route::get('reports/label-log', [OperationalReportController::class, 'labelLog'])->middleware('permission:report.view');
        Route::get('reports/label-log/export-excel', [OperationalReportController::class, 'labelLogExport'])->middleware('permission:report.export');
        Route::get('reports/outbound-by-agency', [OperationalReportController::class, 'outboundByAgency'])->middleware('permission:report.view');
        Route::get('reports/outbound-by-agency/export-excel', [OperationalReportController::class, 'outboundByAgencyExport'])->middleware('permission:report.export');
        Route::get('reports/inventory-reconciliation', [OperationalReportController::class, 'inventoryReconciliation'])->middleware('permission:report.view');
        Route::get('reports/dashboard-quick', [OperationalReportController::class, 'dashboardQuick'])->middleware('permission:report.view');
        Route::get('reports/warranty-defects', [OperationalReportController::class, 'warrantyDefects'])->middleware('permission:report.view');
        Route::get('reports/warranty-defects/export-excel', [OperationalReportController::class, 'warrantyDefectsExport'])->middleware('permission:report.export');

        Route::get('agencies', [AgencyController::class, 'index'])->middleware('permission:agency.view');
        Route::post('agencies', [AgencyController::class, 'store'])->middleware('permission:agency.manage');
        Route::get('agencies/{id}', [AgencyController::class, 'show'])->middleware('permission:agency.view');
        Route::patch('agencies/{id}', [AgencyController::class, 'update'])->middleware('permission:agency.manage');
        Route::delete('agencies/{id}', [AgencyController::class, 'destroy'])->middleware('permission:agency.manage');

        Route::get('defect-catalogs', [DefectCatalogController::class, 'index'])->middleware('permission:defect.view');
        Route::post('defect-catalogs', [DefectCatalogController::class, 'store'])->middleware('permission:defect.manage');
        Route::get('defect-catalogs/{id}', [DefectCatalogController::class, 'show'])->middleware('permission:defect.view');
        Route::patch('defect-catalogs/{id}', [DefectCatalogController::class, 'update'])->middleware('permission:defect.manage');
        Route::delete('defect-catalogs/{id}', [DefectCatalogController::class, 'destroy'])->middleware('permission:defect.manage');

        Route::get('product-masters', [ProductMasterController::class, 'index'])->middleware('permission:catalog.view');
        Route::post('product-masters', [ProductMasterController::class, 'store'])->middleware('permission:catalog.manage');
        Route::get('product-masters/{id}', [ProductMasterController::class, 'show'])->middleware('permission:catalog.view');
        Route::patch('product-masters/{id}', [ProductMasterController::class, 'update'])->middleware('permission:catalog.manage');

        Route::get('physical-codes', [PhysicalCodeController::class, 'index'])->middleware('permission:inventory.trace');
        Route::post('physical-codes', [CurrentBaselinePhysicalCodeController::class, 'store'])->middleware('permission:physical_code.assign_rfid');
        Route::get('physical-codes/{id}', [PhysicalCodeController::class, 'show'])->middleware('permission:inventory.trace');
        Route::patch('physical-codes/{id}', [CurrentBaselinePhysicalCodeController::class, 'update'])->middleware('permission:physical_code.assign_rfid');

        Route::get('import-batches', [ImportBatchController::class, 'index'])->middleware('permission:import_batch.view');
        Route::post('import-batches', [ImportBatchController::class, 'store'])->middleware('permission:import_batch.manage');
        Route::get('import-batches/{id}', [ImportBatchController::class, 'show'])->middleware('permission:import_batch.view');
        Route::patch('import-batches/{id}', [ImportBatchController::class, 'update'])->middleware('permission:import_batch.manage');

        Route::get('containers', [ContainerController::class, 'index'])->middleware('permission:container.view');
        Route::post('containers', [CurrentBaselineContainerFlowController::class, 'store'])->middleware('permission:container.manage');
        Route::get('containers/{id}', [ContainerController::class, 'show'])->middleware('permission:container.view');
        Route::patch('containers/{id}', [CurrentBaselineContainerFlowController::class, 'update'])->middleware('permission:container.manage');
        Route::post('containers/{id}/items', [CurrentBaselineContainerFlowController::class, 'addItem'])->middleware('permission:container.manage');
        Route::delete('containers/{id}/items/{containerItemId}', [CurrentBaselineContainerFlowController::class, 'removeItem'])->middleware('permission:container.manage');

        Route::get('item-instances', [ItemInstanceController::class, 'index'])->middleware('permission:item_instance.view');
        Route::post('item-instances', [ItemInstanceController::class, 'store'])->middleware('permission:item_instance.manage');
        Route::get('item-instances/{id}', [ItemInstanceController::class, 'show'])->middleware('permission:item_instance.view');
        Route::patch('item-instances/{id}', [ItemInstanceController::class, 'update'])->middleware('permission:item_instance.manage');
        Route::get('item-instances/{id}/trace', [ItemInstanceController::class, 'trace'])->middleware('permission:inventory.trace');

        Route::get('brands', [CurrentBaselineMasterController::class, 'listBrands'])->middleware('permission:catalog.view');
        Route::post('brands', [CurrentBaselineMasterController::class, 'storeBrand'])->middleware('permission:catalog.manage');
        Route::get('product-categories', [CurrentBaselineMasterController::class, 'listProductCategories'])->middleware('permission:catalog.view');
        Route::post('product-categories', [CurrentBaselineMasterController::class, 'storeProductCategory'])->middleware('permission:catalog.manage');
        Route::get('power-sources', [CurrentBaselineMasterController::class, 'listPowerSources'])->middleware('permission:catalog.view');
        Route::post('power-sources', [CurrentBaselineMasterController::class, 'storePowerSource'])->middleware('permission:catalog.manage');
        Route::get('product-models', [CurrentBaselineMasterController::class, 'listProductModels'])->middleware('permission:catalog.view');
        Route::post('product-models', [CurrentBaselineMasterController::class, 'storeProductModel'])->middleware('permission:catalog.manage');
        Route::get('package-variants', [CurrentBaselineMasterController::class, 'listPackageVariants'])->middleware('permission:catalog.view');
        Route::post('package-variants', [CurrentBaselineMasterController::class, 'storePackageVariant'])->middleware('permission:catalog.manage');

        Route::get('outbound-requests', [OutboundRequestController::class, 'index'])->middleware('permission:outbound.view');
        Route::get('outbound-requests/{id}', [OutboundRequestController::class, 'show'])->middleware('permission:outbound.view');

        Route::get('outbound-request-imports', [CurrentBaselineImportController::class, 'indexOutboundRequestImports'])->middleware('permission:outbound.import');
        Route::post('outbound-request-imports', [CurrentBaselineImportController::class, 'storeOutboundRequestImport'])->middleware('permission:outbound.import');
        // "template" phải đăng ký trước "{id}" để không bị route {id} nuốt.
        Route::get('outbound-request-imports/template', [CurrentBaselineImportController::class, 'downloadOutboundRequestTemplate'])->middleware('permission:outbound.import');
        Route::get('outbound-request-imports/{id}', [CurrentBaselineImportController::class, 'showOutboundRequestImport'])->middleware('permission:outbound.import');
        Route::get('outbound-request-imports/{id}/preview', [CurrentBaselineImportReadController::class, 'preview'])->middleware('permission:outbound.import');
        Route::get('outbound-request-imports/{id}/errors', [CurrentBaselineImportReadController::class, 'errors'])->middleware('permission:outbound.import');
        Route::post('outbound-request-imports/{id}/consume', [CurrentBaselineImportController::class, 'consumeOutboundRequestImport'])->middleware('permission:outbound.import');

        Route::get('label-snapshots', [CanonicalMvpController::class, 'listLabelSnapshots'])->middleware('permission:label.print');
        Route::post('label-snapshots', [CurrentBaselineOperationsController::class, 'storeLabelSnapshot'])->middleware('permission:label.print');
        Route::get('label-snapshots/{id}', [CurrentBaselineOperationsController::class, 'showLabelSnapshot'])->middleware('permission:label.print');
        Route::post('label-snapshots/{id}/print', [CurrentBaselineOperationsController::class, 'printLabelSnapshot'])->middleware('permission:label.print');
        Route::post('label-snapshots/{id}/reprint', [CurrentBaselineOperationsController::class, 'reprintLabelSnapshot'])->middleware('permission:label.reprint');
        Route::get('label-print-events', [CurrentBaselineOperationsController::class, 'listLabelPrintEvents'])->middleware('permission:label.print');
        Route::get('printer-configs', [CanonicalMvpController::class, 'listPrinterConfigs'])->middleware('permission:label.print');
        Route::post('printer-configs', [CurrentBaselineOperationsController::class, 'storePrinterConfig'])->middleware('permission:label.print');
        Route::patch('printer-configs/{id}', [CurrentBaselineOperationsController::class, 'updatePrinterConfig'])->middleware('permission:label.print');

        Route::get('component-issue-documents', [CanonicalMvpController::class, 'listComponentIssueDocuments'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents', [CurrentBaselineComponentFlowController::class, 'store'])->middleware('permission:warranty.component_issue');
        Route::get('component-issue-documents/{id}', [CurrentBaselineComponentIssueQueryController::class, 'show'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents/{id}/scan', [CurrentBaselineComponentFlowController::class, 'scan'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents/{id}/cancel', [CurrentBaselineComponentFlowController::class, 'cancel'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents/{id}/post', [CurrentBaselineComponentFlowController::class, 'post'])->middleware('permission:warranty.component_issue');

        Route::get('warranty-cases', [CanonicalMvpController::class, 'listWarrantyCases'])->middleware('permission:warranty.manage');
        Route::post('warranty-cases', [CurrentBaselineWarrantyController::class, 'storeWarrantyCase'])->middleware('permission:warranty.manage');
        Route::get('warranty-cases/{id}', [CurrentBaselineWarrantyController::class, 'showWarrantyCase'])->middleware('permission:warranty.manage');
        Route::patch('warranty-cases/{id}', [CurrentBaselineWarrantyController::class, 'updateWarrantyCase'])->middleware('permission:warranty.manage');
        Route::get('warranty-cases/{id}/events', [CanonicalMvpController::class, 'listWarrantyEvents'])->middleware('permission:warranty.manage');
        Route::get('warranty-cases/{id}/attachments', [CurrentBaselineWarrantyAttachmentController::class, 'index'])->middleware('permission:warranty.manage');
        Route::post('warranty-cases/{id}/attachments', [CurrentBaselineWarrantyAttachmentController::class, 'store'])->middleware('permission:warranty.manage');
        Route::get('warranty-attachments/{attachmentId}/download', [CurrentBaselineWarrantyAttachmentController::class, 'download'])->middleware('permission:warranty.manage');
        Route::delete('warranty-attachments/{attachmentId}', [CurrentBaselineWarrantyAttachmentController::class, 'destroy'])->middleware('permission:warranty.manage');
    });
});
