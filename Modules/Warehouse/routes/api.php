<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AgencyController;
use Modules\Warehouse\Http\Controllers\CanonicalMvpController;
use Modules\Warehouse\Http\Controllers\ContainerController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineOperationsController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineStockController;
use Modules\Warehouse\Http\Controllers\DefectCatalogController;
use Modules\Warehouse\Http\Controllers\DocumentStatusHistoryController;
use Modules\Warehouse\Http\Controllers\ImportBatchController;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\InventoryBalanceController;
use Modules\Warehouse\Http\Controllers\ItemInstanceController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\PhysicalCodeController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ProductMasterController;
use Modules\Warehouse\Http\Controllers\ReportController;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Http\Controllers\WarehouseController;

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
        Route::post('inbound-documents', [InboundDocumentController::class, 'store'])->middleware('permission:inbound.create');
        Route::get('inbound-documents/{id}', [InboundDocumentController::class, 'show'])->middleware('permission:inbound.view');
        Route::patch('inbound-documents/{id}', [InboundDocumentController::class, 'update'])->middleware('permission:inbound.create');
        Route::post('inbound-documents/{id}/cancel', [InboundDocumentController::class, 'cancel'])->middleware('permission:inbound.cancel');
        Route::post('inbound-documents/{id}/scan', [CurrentBaselineStockController::class, 'inboundScan'])->middleware('permission:inbound.scan');
        Route::post('inbound-documents/{id}/post-receipt', [CurrentBaselineStockController::class, 'postReceipt'])->middleware('permission:inbound.post');
        Route::post('inbound-documents/{id}/reverse', [CurrentBaselineStockController::class, 'reverseInbound'])->middleware('permission:inventory.reversal.post');

        Route::get('outbound-documents', [OutboundDocumentController::class, 'index'])->middleware('permission:outbound.view');
        Route::post('outbound-documents', [OutboundDocumentController::class, 'store'])->middleware('permission:outbound.create');
        Route::get('outbound-documents/{id}', [OutboundDocumentController::class, 'show'])->middleware('permission:outbound.view');
        Route::patch('outbound-documents/{id}', [OutboundDocumentController::class, 'update'])->middleware('permission:outbound.create');
        Route::post('outbound-documents/{id}/cancel', [OutboundDocumentController::class, 'cancel'])->middleware('permission:outbound.cancel');
        Route::post('outbound-documents/{id}/scan', [CurrentBaselineStockController::class, 'outboundScan'])->middleware('permission:outbound.scan');
        Route::post('outbound-documents/{id}/post-issue', [CurrentBaselineStockController::class, 'postIssue'])->middleware('permission:outbound.post');
        Route::post('outbound-documents/{id}/reverse', [CurrentBaselineStockController::class, 'reverseOutbound'])->middleware('permission:inventory.reversal.post');

        Route::get('inventory/balances', [InventoryBalanceController::class, 'index'])->middleware('permission:inventory.view');
        Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('permission:inventory.view');
        Route::get('stock-movements/{id}', [StockMovementController::class, 'show'])->middleware('permission:inventory.view');
        Route::get('stock-movements/{id}/trace-source', [StockMovementController::class, 'traceSource'])->middleware('permission:inventory.trace');
        Route::get('document-status-history', [DocumentStatusHistoryController::class, 'index'])->middleware('permission:inventory.view');

        Route::get('reports/inventory-xnt', [ReportController::class, 'inventoryXnt'])->middleware('permission:report.view');
        Route::get('reports/inventory-xnt/export-excel', [ReportController::class, 'exportExcel'])->middleware('permission:report.export');
        Route::get('reports/dashboard-quick', [ReportController::class, 'dashboardQuick'])->middleware('permission:report.view');

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
        Route::post('physical-codes', [PhysicalCodeController::class, 'store'])->middleware('permission:physical_code.assign_rfid');
        Route::get('physical-codes/{id}', [PhysicalCodeController::class, 'show'])->middleware('permission:inventory.trace');
        Route::patch('physical-codes/{id}', [PhysicalCodeController::class, 'update'])->middleware('permission:physical_code.assign_rfid');

        Route::get('import-batches', [ImportBatchController::class, 'index'])->middleware('permission:import_batch.view');
        Route::post('import-batches', [ImportBatchController::class, 'store'])->middleware('permission:import_batch.manage');
        Route::get('import-batches/{id}', [ImportBatchController::class, 'show'])->middleware('permission:import_batch.view');
        Route::patch('import-batches/{id}', [ImportBatchController::class, 'update'])->middleware('permission:import_batch.manage');

        Route::get('containers', [ContainerController::class, 'index'])->middleware('permission:container.view');
        Route::post('containers', [ContainerController::class, 'store'])->middleware('permission:container.manage');
        Route::get('containers/{id}', [ContainerController::class, 'show'])->middleware('permission:container.view');
        Route::patch('containers/{id}', [ContainerController::class, 'update'])->middleware('permission:container.manage');
        Route::post('containers/{id}/items', [ContainerController::class, 'addItem'])->middleware('permission:container.manage');
        Route::delete('containers/{id}/items/{containerItemId}', [ContainerController::class, 'removeItem'])->middleware('permission:container.manage');

        Route::get('item-instances', [ItemInstanceController::class, 'index'])->middleware('permission:item_instance.view');
        Route::post('item-instances', [ItemInstanceController::class, 'store'])->middleware('permission:item_instance.manage');
        Route::get('item-instances/{id}', [ItemInstanceController::class, 'show'])->middleware('permission:item_instance.view');
        Route::patch('item-instances/{id}', [ItemInstanceController::class, 'update'])->middleware('permission:item_instance.manage');
        Route::get('item-instances/{id}/trace', [ItemInstanceController::class, 'trace'])->middleware('permission:inventory.trace');

        Route::get('brands', [CanonicalMvpController::class, 'listBrands'])->middleware('permission:catalog.view');
        Route::post('brands', [CanonicalMvpController::class, 'storeBrand'])->middleware('permission:catalog.manage');
        Route::get('product-categories', [CanonicalMvpController::class, 'listProductCategories'])->middleware('permission:catalog.view');
        Route::post('product-categories', [CanonicalMvpController::class, 'storeProductCategory'])->middleware('permission:catalog.manage');
        Route::get('power-sources', [CanonicalMvpController::class, 'listPowerSources'])->middleware('permission:catalog.view');
        Route::post('power-sources', [CanonicalMvpController::class, 'storePowerSource'])->middleware('permission:catalog.manage');
        Route::get('product-models', [CanonicalMvpController::class, 'listProductModels'])->middleware('permission:catalog.view');
        Route::post('product-models', [CanonicalMvpController::class, 'storeProductModel'])->middleware('permission:catalog.manage');
        Route::get('package-variants', [CanonicalMvpController::class, 'listPackageVariants'])->middleware('permission:catalog.view');
        Route::post('package-variants', [CanonicalMvpController::class, 'storePackageVariant'])->middleware('permission:catalog.manage');

        Route::post('outbound-request-imports', [CurrentBaselineOperationsController::class, 'storeOutboundRequestImport'])->middleware('permission:outbound.import');
        Route::get('outbound-request-imports/{id}', [CurrentBaselineOperationsController::class, 'showOutboundRequestImport'])->middleware('permission:outbound.import');
        Route::post('outbound-request-imports/{id}/consume', [CurrentBaselineOperationsController::class, 'consumeOutboundRequestImport'])->middleware('permission:outbound.import');

        Route::get('label-snapshots', [CanonicalMvpController::class, 'listLabelSnapshots'])->middleware('permission:label.print');
        Route::post('label-snapshots', [CurrentBaselineOperationsController::class, 'storeLabelSnapshot'])->middleware('permission:label.print');
        Route::post('label-snapshots/{id}/print', [CurrentBaselineOperationsController::class, 'printLabelSnapshot'])->middleware('permission:label.print');
        Route::post('label-snapshots/{id}/reprint', [CurrentBaselineOperationsController::class, 'reprintLabelSnapshot'])->middleware('permission:label.reprint');
        Route::get('printer-configs', [CanonicalMvpController::class, 'listPrinterConfigs'])->middleware('permission:label.print');
        Route::post('printer-configs', [CurrentBaselineOperationsController::class, 'storePrinterConfig'])->middleware('permission:label.print');

        Route::get('component-issue-documents', [CanonicalMvpController::class, 'listComponentIssueDocuments'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents', [CurrentBaselineStockController::class, 'storeComponentIssueDocument'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents/{id}/post', [CurrentBaselineStockController::class, 'postComponentIssueDocument'])->middleware('permission:warranty.component_issue');

        Route::get('warranty-cases', [CanonicalMvpController::class, 'listWarrantyCases'])->middleware('permission:warranty.manage');
        Route::post('warranty-cases', [CurrentBaselineOperationsController::class, 'storeWarrantyCase'])->middleware('permission:warranty.manage');
        Route::patch('warranty-cases/{id}', [CurrentBaselineOperationsController::class, 'updateWarrantyCase'])->middleware('permission:warranty.manage');
        Route::get('warranty-cases/{id}/events', [CanonicalMvpController::class, 'listWarrantyEvents'])->middleware('permission:warranty.manage');
    });
});
