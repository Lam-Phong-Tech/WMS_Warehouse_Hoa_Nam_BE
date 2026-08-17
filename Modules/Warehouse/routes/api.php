<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AgencyController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\AlignedWarrantyReportController;
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
use Modules\Warehouse\Http\Controllers\ScanController;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Http\Controllers\WarehouseController;
use Modules\Warehouse\Http\Controllers\WmsContractController;
use Modules\Warehouse\Http\Middleware\EnforceInventoryWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
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
        Route::patch('products/{id}/enable', [ProductController::class, 'enable'])->middleware('permission:sku.manage');
        Route::patch('products/{id}/disable', [ProductController::class, 'disable'])->middleware('permission:sku.manage');

        Route::get('inbound-documents', [InboundDocumentController::class, 'index'])->middleware('permission:inbound.view');
        Route::post('inbound-documents', [CurrentBaselineDocumentController::class, 'storeInbound'])
            ->middleware(['permission:inbound.create', EnsureCanonicalInboundWarehouse::class]);
        Route::get('inbound-documents/{id}', [InboundDocumentController::class, 'show'])->middleware('permission:inbound.view');
        Route::patch('inbound-documents/{id}', [CurrentBaselineDocumentController::class, 'updateInbound'])
            ->middleware(['permission:inbound.create', EnsureCanonicalInboundWarehouse::class]);
        // Annex C cancelInboundDocument khai `inbound.create`; baseline seed lại cấp
        // `inbound.cancel` riêng cho WAREHOUSE_APPROVER/KEEPER. Nhận cả hai để vừa
        // đúng contract Annex C vừa không thu hồi quyền đang cấp.
        Route::post('inbound-documents/{id}/cancel', [CurrentBaselineDocumentController::class, 'cancelInbound'])
            ->middleware('permission:inbound.cancel|inbound.create');
        Route::post('inbound-documents/{id}/boxes', [CurrentBaselineInboundBoxController::class, 'store'])->middleware('permission:inbound.scan');
        Route::post('inbound-documents/{id}/scan', [CurrentBaselineStockFlowController::class, 'inboundScan'])
            ->middleware(['permission:inbound.scan', EnsureInboundComponentBox::class]);
        // ERD bảng 28 inbound_scan_entries.status ACTIVE/REMOVED "cho sửa trước Post":
        // đối xứng với outbound-documents/{id}/unmatch, stock-neutral.
        Route::post('inbound-documents/{id}/unscan', [CurrentBaselineStockFlowController::class, 'inboundUnscan'])
            ->middleware('permission:inbound.scan');
        Route::post('inbound-documents/{id}/post-receipt', [CurrentBaselineStockFlowController::class, 'postReceipt'])->middleware('permission:inbound.post');
        Route::post('inbound-documents/{id}/reverse', [CurrentBaselineStockFlowController::class, 'reverseInbound'])->middleware('permission:inventory.reversal.post');

        // SRS §3: "Putaway task list/create/confirm/cancel" -> permission key
        // `putaway.manage` (NV kho = A*). Path legacy /putaway/* phải nhận cùng bộ
        // quyền như alias canonical /putaway-tasks ở dưới, nếu không NV kho được
        // seed `putaway.manage` mà vẫn 403 trên đúng path FE đang gọi.
        Route::get('putaway/tasks', [PutawayController::class, 'tasks'])->middleware('permission:putaway.view|putaway.manage|container.view');
        Route::post('putaway/tasks', [CurrentBaselinePutawayTaskController::class, 'store'])->middleware('permission:putaway.manage|container.manage');
        Route::post('putaway/tasks/{id}/confirm', [PutawayController::class, 'confirm'])->middleware('permission:putaway.manage|container.manage');
        Route::post('putaway/tasks/{id}/cancel', [PutawayController::class, 'cancel'])->middleware('permission:putaway.manage|container.manage');
        Route::get('putaway/history', [PutawayController::class, 'history'])->middleware('permission:putaway.view|putaway.manage|container.view');

        // §8.1: KHÔNG có "direct POST /outbound-documents create operation" — phiếu xuất
        // chỉ được sinh từ Confirm toa (POST /outbound-request-imports/{id}/confirm);
        // Annex C cũng không khai createOutboundDocument/updateOutboundDocument.
        // §8.1 Enforcement standard: permission + data scope cho mọi route phiếu xuất.
        Route::get('outbound-documents', [OutboundDocumentController::class, 'index'])
            ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
        Route::get('outbound-documents/{id}', [OutboundDocumentController::class, 'show'])
            ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/cancel', [CurrentBaselineDocumentController::class, 'cancelOutbound'])
            ->middleware(['permission:outbound.cancel', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/scan', [CurrentBaselineStockFlowController::class, 'outboundScan'])
            ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/unmatch', [CurrentBaselineStockFlowController::class, 'outboundUnmatch'])
            ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/post-issue', [CurrentBaselineStockFlowController::class, 'postIssue'])
            ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/reverse', [CurrentBaselineStockFlowController::class, 'reverseOutbound'])
            ->middleware(['permission:inventory.reversal.post', EnforceOutboundWarehouseScope::class]);

        // §8.1 Enforcement standard: "exact x-permission + data scope". Middleware
        // dưới đây chặn đọc/truy vết ngoài warehouse scope, không thay thế permission.
        Route::get('inventory/balances', [InventoryBalanceController::class, 'index'])
            ->middleware(['permission:inventory.view', EnforceInventoryWarehouseScope::class]);
        Route::get('inventory/balances/{id}/subledger', [InventoryBalanceController::class, 'subledger'])
            ->middleware(['permission:inventory.view', EnforceInventoryWarehouseScope::class]);
        Route::get('stock-movements', [StockMovementController::class, 'index'])
            ->middleware(['permission:inventory.view', EnforceInventoryWarehouseScope::class]);
        Route::get('stock-movements/reversible', [StockMovementController::class, 'reversible'])
            ->middleware(['permission:inventory.reversal.post', EnforceInventoryWarehouseScope::class]);
        Route::get('stock-movements/trace-by-code', [StockMovementController::class, 'traceByPhysicalCode'])
            ->middleware(['permission:inventory.trace', EnforceInventoryWarehouseScope::class]);
        Route::get('stock-movements/{id}', [StockMovementController::class, 'show'])
            ->middleware(['permission:inventory.view', EnforceInventoryWarehouseScope::class]);
        Route::get('stock-movements/{id}/trace-source', [StockMovementController::class, 'traceSource'])
            ->middleware(['permission:inventory.trace', EnforceInventoryWarehouseScope::class]);

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
        // RPT-WAR-01/RPT-WAR-COMP-01: trỏ thẳng controller Aligned thay vì để
        // Flow06AlignmentServiceProvider override một handler legacy — handler cũ dùng
        // bộ status ngoài §6 nên summary.processing đếm thiếu CHECKING/REPAIRING/COMPLETED.
        Route::get('reports/warranty-defects', [AlignedWarrantyReportController::class, 'index'])->middleware('permission:report.view');
        Route::get('reports/warranty-defects/export-excel', [AlignedWarrantyReportController::class, 'export'])->middleware('permission:report.export');
        // Annex C REQ-SRS-031/032. "reports/{report_code}" là catch-all nên phải
        // đăng ký sau mọi route reports/* cụ thể ở trên.
        Route::get('reports/inventory-ledger', [OperationalReportController::class, 'inventoryLedger'])->middleware('permission:report.view');
        Route::post('reports/export', [OperationalReportController::class, 'createExport'])->middleware('permission:report.export');
        Route::get('reports/{report_code}', [OperationalReportController::class, 'byCode'])->middleware('permission:report.view');

        Route::get('agencies', [AgencyController::class, 'index'])->middleware('permission:agency.view');
        Route::post('agencies', [AgencyController::class, 'store'])->middleware('permission:agency.manage');
        Route::get('agencies/{id}', [AgencyController::class, 'show'])->middleware('permission:agency.view');
        Route::patch('agencies/{id}', [AgencyController::class, 'update'])->middleware('permission:agency.manage');
        // BR-AGY-01 + BR-MST "deactivate thay vì xóa": không có DELETE đại lý.
        // Ngừng dùng bằng PATCH status=INACTIVE để chứng từ lịch sử giữ nguyên FK.

        Route::get('defect-catalogs', [DefectCatalogController::class, 'index'])->middleware('permission:defect.view');
        Route::post('defect-catalogs', [DefectCatalogController::class, 'store'])->middleware('permission:defect.manage');
        Route::get('defect-catalogs/{id}', [DefectCatalogController::class, 'show'])->middleware('permission:defect.view');
        Route::patch('defect-catalogs/{id}', [DefectCatalogController::class, 'update'])->middleware('permission:defect.manage');
        // BR-DEF-01..04 "lịch sử không bị đổi": không có DELETE bệnh/lỗi.
        // Ngừng dùng bằng PATCH status=INACTIVE; hồ sơ bảo hành cũ giữ nguyên defect_id.

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

        // Annex C importOutboundRequestExcel dùng permission `outbound.request.import`;
        // giữ `outbound.import` legacy để không thu hồi quyền đang cấp cho KEEPER/ADMIN.
        Route::get('outbound-request-imports', [CurrentBaselineImportController::class, 'indexOutboundRequestImports'])->middleware('permission:outbound.request.import|outbound.import');
        Route::post('outbound-request-imports', [CurrentBaselineImportController::class, 'storeOutboundRequestImport'])->middleware('permission:outbound.request.import|outbound.import');
        // "template" phải đăng ký trước "{id}" để không bị route {id} nuốt.
        Route::get('outbound-request-imports/template', [CurrentBaselineImportController::class, 'downloadOutboundRequestTemplate'])->middleware('permission:outbound.request.import|outbound.import');
        Route::get('outbound-request-imports/{id}', [CurrentBaselineImportController::class, 'showOutboundRequestImport'])->middleware('permission:outbound.request.import|outbound.import');
        Route::get('outbound-request-imports/{id}/preview', [CurrentBaselineImportReadController::class, 'preview'])->middleware('permission:outbound.request.import|outbound.import');
        Route::get('outbound-request-imports/{id}/errors', [CurrentBaselineImportReadController::class, 'errors'])->middleware('permission:outbound.request.import|outbound.import');
        Route::post('outbound-request-imports/{id}/consume', [CurrentBaselineImportController::class, 'consumeOutboundRequestImport'])->middleware('permission:outbound.request.confirm|outbound.import');

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

        // Annex C listWarrantyCases dùng `warranty.view`; các GET còn lại của SCR-WAR-01
        // là cùng một bề mặt đọc nên nhận `warranty.view|warranty.manage` để role chỉ
        // được cấp warranty.view (LEADERSHIP, WAREHOUSE_APPROVER) vẫn tra cứu được.
        // Mọi mutation giữ nguyên `warranty.manage`.
        Route::get('warranty-cases', [CanonicalMvpController::class, 'listWarrantyCases'])->middleware('permission:warranty.view|warranty.manage');
        Route::post('warranty-cases', [CurrentBaselineWarrantyController::class, 'storeWarrantyCase'])->middleware('permission:warranty.manage');
        Route::get('warranty-cases/{id}', [CurrentBaselineWarrantyController::class, 'showWarrantyCase'])->middleware('permission:warranty.view|warranty.manage');
        Route::patch('warranty-cases/{id}', [CurrentBaselineWarrantyController::class, 'updateWarrantyCase'])->middleware('permission:warranty.manage');
        Route::get('warranty-cases/{id}/events', [CanonicalMvpController::class, 'listWarrantyEvents'])->middleware('permission:warranty.view|warranty.manage');
        Route::get('warranty-cases/{id}/attachments', [CurrentBaselineWarrantyAttachmentController::class, 'index'])->middleware('permission:warranty.view|warranty.manage');
        Route::post('warranty-cases/{id}/attachments', [CurrentBaselineWarrantyAttachmentController::class, 'store'])->middleware('permission:warranty.manage');
        Route::get('warranty-attachments/{attachmentId}/download', [CurrentBaselineWarrantyAttachmentController::class, 'download'])->middleware('permission:warranty.view|warranty.manage');
        Route::delete('warranty-attachments/{attachmentId}', [CurrentBaselineWarrantyAttachmentController::class, 'destroy'])->middleware('permission:warranty.manage');

        // ──────────────────────────────────────────────────────────────────
        // Alias theo đúng path/permission của SRS v1.12 §8.1 + Annex C.
        // Cùng handler với route hiện hành nên không nhân đôi nghiệp vụ;
        // permission dùng dạng `chuẩn|legacy` để không thu hồi quyền đang cấp.
        // ──────────────────────────────────────────────────────────────────
        Route::post('inbound-documents/{id}/scans', [CurrentBaselineStockFlowController::class, 'inboundScan'])
            ->middleware(['permission:inbound.scan', EnsureInboundComponentBox::class]);
        Route::post('inbound-documents/{id}/reversals', [CurrentBaselineStockFlowController::class, 'reverseInbound'])
            ->middleware('permission:inventory.reversal.post');

        // Route /scan gốc được Flow06AlignmentServiceProvider override sang
        // AlignedOutboundFlowController; alias phải trỏ thẳng handler thật để hai
        // path không chạy hai nghiệp vụ khác nhau (label 1/lô vs label per-item).
        Route::post('outbound-documents/{id}/scans', [AlignedOutboundFlowController::class, 'outboundScan'])
            ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/reversals', [CurrentBaselineStockFlowController::class, 'reverseOutbound'])
            ->middleware(['permission:inventory.reversal.post', EnforceOutboundWarehouseScope::class]);

        Route::get('putaway-tasks', [PutawayController::class, 'tasks'])->middleware('permission:putaway.view|putaway.manage|container.view');
        Route::post('putaway-tasks', [CurrentBaselinePutawayTaskController::class, 'store'])->middleware('permission:putaway.manage|container.manage');
        Route::post('putaway-tasks/{id}/confirm', [PutawayController::class, 'confirm'])->middleware('permission:putaway.manage|container.manage');
        Route::post('putaway-tasks/{id}/cancel', [PutawayController::class, 'cancel'])->middleware('permission:putaway.manage|container.manage');

        Route::post('warranty-cases/{id}/status', [CurrentBaselineWarrantyController::class, 'updateWarrantyCase'])
            ->middleware('permission:warranty.manage');

        Route::get('warranty-component-issues', [CanonicalMvpController::class, 'listComponentIssueDocuments'])->middleware('permission:warranty.component_issue');
        Route::post('warranty-component-issues', [CurrentBaselineComponentFlowController::class, 'store'])->middleware('permission:warranty.component_issue');
        Route::get('warranty-component-issues/{id}', [CurrentBaselineComponentIssueQueryController::class, 'show'])->middleware('permission:warranty.component_issue');
        Route::post('warranty-component-issues/{id}/scans', [CurrentBaselineComponentFlowController::class, 'scan'])->middleware('permission:warranty.component_issue');
        Route::post('warranty-component-issues/{id}/cancel', [CurrentBaselineComponentFlowController::class, 'cancel'])->middleware('permission:warranty.component_issue');
        Route::post('warranty-component-issues/{id}/post', [CurrentBaselineComponentFlowController::class, 'post'])->middleware('permission:warranty.component_issue');

        Route::post('outbound-request-imports/{id}/confirm', [CurrentBaselineImportController::class, 'consumeOutboundRequestImport'])
            ->middleware('permission:outbound.request.confirm|outbound.import');
        Route::post('outbound-request-imports/manual', [CurrentBaselineOperationsController::class, 'storeOutboundRequestImport'])
            ->middleware('permission:outbound.request.manual_create|outbound.import');

        // Annex C queryInventory / traceInventoryCode. §8.1 "Enforcement standard:
        // All protected endpoints — exact x-permission + data scope": alias canonical
        // phải chịu đúng data-scope guard như path legacy, nếu không nó là đường vòng.
        Route::get('inventory', [InventoryBalanceController::class, 'index'])
            ->middleware(['permission:inventory.view', EnforceInventoryWarehouseScope::class]);
        Route::get('inventory/trace/{code}', [StockMovementController::class, 'traceByPathCode'])
            ->middleware(['permission:inventory.trace', EnforceInventoryWarehouseScope::class]);

        Route::post('scan-batches/classify', [ScanController::class, 'classify'])->middleware('permission:scan.execute|scan.classify');

        // NFR-SRS-004: bằng chứng scan phải tra cứu được (FE TRACE/PhysicalCodes).
        Route::get('scan/batches', [WmsContractController::class, 'scanBatches'])->middleware('permission:inventory.view');
        Route::get('scan/events', [WmsContractController::class, 'scanEvents'])->middleware('permission:inventory.view');

        // Annex D SCR-IN-01: đọc lại bằng chứng quét của phiếu nhập.
        Route::get('inbound-documents/{id}/scan-entries', [CurrentBaselineDocumentController::class, 'inboundScanEntries'])
            ->middleware('permission:inbound.view');

        // Annex C createWarehouse — REQ-SRS-001: kho ACTIVE thứ hai trả 409 STATE_CONFLICT.
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouse.manage');

        // Annex C listDefects / createDefect / updateDefect — path chuẩn §8.1 Master.
        // Route /defect-catalogs giữ nguyên cho client hiện hành.
        Route::get('defects', [DefectCatalogController::class, 'index'])->middleware('permission:defect.view');
        Route::post('defects', [DefectCatalogController::class, 'store'])->middleware('permission:defect.manage');
        Route::get('defects/{id}', [DefectCatalogController::class, 'show'])->middleware('permission:defect.view');
        Route::patch('defects/{id}', [DefectCatalogController::class, 'update'])->middleware('permission:defect.manage');

        // Annex C listCatalogMasterEntries / createCatalogMasterEntry / updateCatalogMasterEntry.
        Route::get('catalog-masters/{masterType}', [CurrentBaselineMasterController::class, 'listCatalogMasterEntries'])->middleware('permission:catalog.view');
        Route::post('catalog-masters/{masterType}', [CurrentBaselineMasterController::class, 'createCatalogMasterEntry'])->middleware('permission:catalog.manage');
        Route::patch('catalog-masters/{masterType}/{id}', [CurrentBaselineMasterController::class, 'updateCatalogMasterEntry'])->middleware('permission:catalog.manage');

        // Annex C updateCatalogMasterEntry — deactivate thay vì xoá (BR-MST-01..07).
        Route::patch('brands/{id}', [CurrentBaselineMasterController::class, 'updateBrand'])->middleware('permission:catalog.manage');
        Route::patch('product-categories/{id}', [CurrentBaselineMasterController::class, 'updateProductCategory'])->middleware('permission:catalog.manage');
        Route::patch('power-sources/{id}', [CurrentBaselineMasterController::class, 'updatePowerSource'])->middleware('permission:catalog.manage');
        Route::patch('product-models/{id}', [CurrentBaselineMasterController::class, 'updateProductModel'])->middleware('permission:catalog.manage');
        Route::patch('package-variants/{id}', [CurrentBaselineMasterController::class, 'updatePackageVariant'])->middleware('permission:catalog.manage');

        // Annex C publishPvProduct — quyền pv.publish tách khỏi sku.manage theo §3.
        Route::patch('pv/products/{id}/publish', [ProductController::class, 'publishPvProduct'])
            ->middleware('permission:pv.publish|sku.manage');

        // SRS §6 Reversal state model: DRAFT → (confirm) → post/cancel.
        Route::post('reversal-documents/{id}/confirm', [ReversalDocumentController::class, 'confirm'])->middleware('permission:inventory.reversal.post');
        Route::post('reversal-documents/{id}/post', [ReversalDocumentController::class, 'post'])->middleware('permission:inventory.reversal.post');
        Route::post('reversal-documents/{id}/cancel', [ReversalDocumentController::class, 'cancel'])->middleware('permission:inventory.reversal.post');

        // Annex C assignItemRfid — RFID là tùy chọn, không phải gate Post Issue.
        Route::put('outbound-documents/{id}/items/{itemId}/rfid', [CurrentBaselinePhysicalCodeController::class, 'assignOutboundItemRfid'])
            ->middleware(['permission:physical_code.assign_rfid', EnforceOutboundWarehouseScope::class]);

        // Annex C packing label lifecycle (§6): preview/ready trước completion,
        // print/reprint dùng lại đúng snapshot bất biến.
        Route::post('outbound-documents/{id}/packing-labels/{labelId}/preview', [AlignedOutboundFlowController::class, 'previewPackingLabel'])
            ->middleware(['permission:packing_label.preview|label.print', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/packing-labels/{labelId}/ready', [AlignedOutboundFlowController::class, 'readyPackingLabel'])
            ->middleware(['permission:packing_label.ready|label.print', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/packing-labels/{labelId}/print', [CurrentBaselineOperationsController::class, 'printPackingLabelForDocument'])
            ->middleware(['permission:packing_label.print|label.print', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/packing-labels/{labelId}/reprint', [CurrentBaselineOperationsController::class, 'reprintPackingLabelForDocument'])
            ->middleware(['permission:packing_label.reprint|label.reprint', EnforceOutboundWarehouseScope::class]);
    });
});
