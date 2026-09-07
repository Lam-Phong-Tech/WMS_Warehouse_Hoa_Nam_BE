<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AlignedImportMatchReportController;
use Modules\Warehouse\Http\Controllers\AlignedLabelLogReportController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundDocumentController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\AlignedWarrantyReportController;
use Modules\Warehouse\Http\Controllers\BaM1RecipientController;
use Modules\Warehouse\Http\Controllers\BaM1ReportController;
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
use Modules\Warehouse\Http\Controllers\DocumentAttachmentController;
use Modules\Warehouse\Http\Controllers\DocumentStatusHistoryController;
use Modules\Warehouse\Http\Controllers\ImportBatchController;
use Modules\Warehouse\Http\Controllers\InboundDocumentController;
use Modules\Warehouse\Http\Controllers\InboundSkuInfoController;
use Modules\Warehouse\Http\Controllers\InventoryBalanceController;
use Modules\Warehouse\Http\Controllers\ItemInstanceController;
use Modules\Warehouse\Http\Controllers\OperationalReportController;
use Modules\Warehouse\Http\Controllers\OutboundDocumentController;
use Modules\Warehouse\Http\Controllers\OutboundRequestController;
use Modules\Warehouse\Http\Controllers\PhysicalCodeController;
use Modules\Warehouse\Http\Controllers\ProductController;
use Modules\Warehouse\Http\Controllers\ProductMasterController;
use Modules\Warehouse\Http\Controllers\PutawayController;
use Modules\Warehouse\Http\Controllers\PvAdminResourceController;
use Modules\Warehouse\Http\Controllers\PvProductImageController;
use Modules\Warehouse\Http\Controllers\PvPublicCatalogController;
use Modules\Warehouse\Http\Controllers\ReversalDocumentController;
use Modules\Warehouse\Http\Controllers\ScanController;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Http\Controllers\WarehouseController;
use Modules\Warehouse\Http\Controllers\WmsContractController;
use Modules\Warehouse\Http\Middleware\EnforceComponentIssueWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceInboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceInventoryWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceReversalWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Http\Middleware\EnsureInboundComponentBox;

Route::prefix('v1')->group(function (): void {
    // WWHN-289 (DEV289-P1-04): §8.1 bảng Security bắt buộc nhóm PV ẩn danh có
    // "read-only DTO/rate-limit/cache/anti-abuse controls" và Annex C khai 429
    // trong error set của chúng — trước đây không có limiter nào nên 429 là mã
    // lỗi công bố nhưng không thể xảy ra. Ngưỡng tại config/rate_limit.php.
    Route::middleware('throttle:pv-public')->group(function (): void {
        // WWHN-252 / Annex C: path CANONICAL của App PV là /pv/products và
        // /pv/products/{sku_id} (operationId listPvProducts / getPvProduct,
        // security: []). Trước đây surface chỉ có /pv/catalog nên FE không có
        // hợp đồng nào để wire vào — đó là gốc của FE252-P1-02/P1-07.
        Route::get('pv/products', [PvPublicCatalogController::class, 'listProducts']);
        Route::get('pv/products/{skuId}', [PvPublicCatalogController::class, 'showProduct']);
        Route::get('pv/industries', [PvPublicCatalogController::class, 'listIndustries']);
        Route::get('pv/recommendations', [CurrentBaselineOperationsController::class, 'pvRecommendations']);

        // Path lịch sử, giữ để client cũ không vỡ; thân hàm đã trỏ về cùng read model.
        Route::get('pv/catalog', [CurrentBaselineOperationsController::class, 'pvCatalog']);
        Route::get('pv/tags', [CanonicalMvpController::class, 'pvTags']);

        // MST-PV-01 — ảnh sản phẩm phục vụ cho mặt ẩn danh. Bucket giữ PRIVATE,
        // ảnh đi qua đây (xem config/pv.php `image_public_base`). Ràng buộc `where`
        // khoá key về đúng dạng `<uuid>.<ext>`: không dấu `/`, không `..`, nên
        // không có đường ghép ra đường dẫn trỏ sang tệp khác trong bucket.
        Route::get('pv/product-images/{key}', [PvProductImageController::class, 'show'])
            ->where('key', PvProductImageController::KEY_PATTERN);
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouse.view');
        Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:warehouse.view');
        Route::patch('warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.manage');

        Route::get('products', [ProductController::class, 'index'])->middleware('permission:sku.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:sku.manage');
        Route::get('products/{id}', [ProductController::class, 'show'])->middleware('permission:sku.view');
        Route::patch('products/{id}', [ProductController::class, 'update'])->middleware('permission:sku.manage');
        // products/{id}/enable|disable KHÔNG khai ở đây: chúng đã được đăng ký tại
        // routes/governance.php (ProductStatusCompatibilityController), file nạp SAU
        // file này nên bản khai ở đây bị ghi đè hoàn toàn — code chết nhưng đọc như
        // đang có hiệu lực. Bất biến "không có route trùng" nay được chốt bằng
        // AuthorizationCoverageInvariantTest::test_khong_co_route_bi_khai_trung.

        // WWHN-289 (DEV289-P1-04): luồng nhập kho phải chịu data scope như luồng xuất
        // (SRS §8.1 enforcement standard = permission + data scope trên MỌI endpoint
        // được bảo vệ). EnsureCanonicalInboundWarehouse chỉ ép kho canonical, không đọc
        // scope_ref của actor, nên không thay thế được guard này.
        Route::get('inbound-documents', [InboundDocumentController::class, 'index'])
            ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);
        Route::post('inbound-documents', [CurrentBaselineDocumentController::class, 'storeInbound'])
            ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);
        Route::get('inbound-documents/{id}', [InboundDocumentController::class, 'show'])
            ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);
        Route::patch('inbound-documents/{id}', [CurrentBaselineDocumentController::class, 'updateInbound'])
            ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);
        // Annex C cancelInboundDocument khai `inbound.create`; baseline seed lại cấp
        // `inbound.cancel` riêng cho WAREHOUSE_APPROVER/KEEPER. Nhận cả hai để vừa
        // đúng contract Annex C vừa không thu hồi quyền đang cấp.
        Route::post('inbound-documents/{id}/cancel', [CurrentBaselineDocumentController::class, 'cancelInbound'])
            ->middleware(['permission:inbound.cancel|inbound.create', EnforceInboundWarehouseScope::class]);
        Route::post('inbound-documents/{id}/boxes', [CurrentBaselineInboundBoxController::class, 'store'])
            ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);
        Route::post('inbound-documents/{id}/scan', [CurrentBaselineStockFlowController::class, 'inboundScan'])
            ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class, EnsureInboundComponentBox::class]);
        // ERD bảng 28 inbound_scan_entries.status ACTIVE/REMOVED "cho sửa trước Post":
        // đối xứng với outbound-documents/{id}/unmatch, stock-neutral.
        Route::post('inbound-documents/{id}/unscan', [CurrentBaselineStockFlowController::class, 'inboundUnscan'])
            ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);
        // Bổ sung thông tin cho SKU MỚI ngay trong phiếu: quyền inbound.scan chứ
        // KHÔNG phải sku.manage — vai Nhân viên kho không có sku.manage, mà chính họ
        // là người đứng quét và Post. Phạm vi bị bó trong controller: chỉ SKU trên
        // dòng của phiếu này, phiếu còn DRAFT/SCANNING.
        Route::patch('inbound-documents/{id}/lines/{lineId}/sku', [InboundSkuInfoController::class, 'complete'])
            ->whereUuid('id')
            ->whereUuid('lineId')
            ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);
        // Inbound Carton Scan v1.0 §3.3: mốc "Đã quét xong thùng" — audit-only,
        // stock-neutral, không đổi container.status, không bump document version.
        Route::post('inbound-documents/{id}/cartons/{containerId}/complete-scan', [CurrentBaselineStockFlowController::class, 'completeInboundCartonScan'])
            ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);
        Route::post('inbound-documents/{id}/post-receipt', [CurrentBaselineStockFlowController::class, 'postReceipt'])
            ->middleware(['permission:inbound.post', EnforceInboundWarehouseScope::class]);
        Route::post('inbound-documents/{id}/reverse', [CurrentBaselineStockFlowController::class, 'reverseInbound'])
            ->middleware(['permission:inventory.reversal.post', EnforceInboundWarehouseScope::class]);

        // "Chứng từ nhập/xuất/hoàn trả": ảnh/scan/PDF đính kèm phiếu nhập (source
        // document) để đối soát sau này. EnforceInboundWarehouseScope đọc route `id`
        // giống các route mutate khác của luồng này nên tái dùng được nguyên trạng.
        Route::get('inbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'indexInbound'])
            ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);
        Route::post('inbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'storeInbound'])
            ->middleware(['permission:inbound.create|inbound.scan', EnforceInboundWarehouseScope::class]);

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
        // WWHN-195 / DEV195-P1-05R2: bảng route phải trùng handler chạy thật.
        // Bốn route dưới đây trước trỏ CurrentBaseline* rồi bị
        // Flow06AlignmentServiceProvider override sang Aligned* lúc boot, nên ai đọc
        // routes/api.php để dựng contract đều mô tả nhầm nghiệp vụ đã bị supersede
        // (nhãn per-item PENDING_PRINT + in ngay lúc scan, code_value max lệch nhau).
        // Nay trỏ thẳng handler canonical.
        Route::get('outbound-documents', [AlignedOutboundDocumentController::class, 'index'])
            ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
        Route::get('outbound-documents/{id}', [OutboundDocumentController::class, 'show'])
            ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/cancel', [CurrentBaselineDocumentController::class, 'cancelOutbound'])
            ->middleware(['permission:outbound.cancel', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/scan', [AlignedOutboundFlowController::class, 'outboundScan'])
            ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/unmatch', [AlignedOutboundFlowController::class, 'outboundUnmatch'])
            ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/post-issue', [AlignedOutboundFlowController::class, 'postIssue'])
            ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/reverse', [CurrentBaselineStockFlowController::class, 'reverseOutbound'])
            ->middleware(['permission:inventory.reversal.post', EnforceOutboundWarehouseScope::class]);

        // "Chứng từ nhập/xuất/hoàn trả": phiếu xuất luôn có outbound_request_imports
        // (Excel) làm chứng từ nguồn bắt buộc; endpoint này chỉ mở thêm để lưu biên
        // bản giao nhận/ảnh khi cần đối soát, không chặn Post.
        Route::get('outbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'indexOutbound'])
            ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
        Route::post('outbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'storeOutbound'])
            ->middleware(['permission:outbound.scan|outbound.post', EnforceOutboundWarehouseScope::class]);

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

        // WWHN-242: nhóm reversal-documents phải chịu warehouse data scope như nhóm
        // inventory (WWHN-211) và inbound (WWHN-289). Thiếu nó thì actor có
        // inventory.reversal.post ghi được stock effect sang kho ngoài phạm vi.
        Route::get('reversal-documents', [ReversalDocumentController::class, 'index'])
            ->middleware(['permission:inventory.view', EnforceReversalWarehouseScope::class]);
        Route::post('reversal-documents', [ReversalDocumentController::class, 'store'])
            ->middleware(['permission:inventory.reversal.post', EnforceReversalWarehouseScope::class]);
        Route::get('reversal-documents/{id}', [ReversalDocumentController::class, 'show'])
            ->middleware(['permission:inventory.view', EnforceReversalWarehouseScope::class]);
        Route::get('reversal-documents/{id}/lines', [ReversalDocumentController::class, 'lines'])
            ->middleware(['permission:inventory.view', EnforceReversalWarehouseScope::class]);

        // "Chứng từ nhập/xuất/hoàn trả": hoàn trả/điều chỉnh LUÔN bắt buộc chứng từ
        // (DocumentEvidenceGuard) — gate thật nằm ở POST .../post, đây chỉ là nơi lưu
        // chứng từ trước khi Post.
        Route::get('reversal-documents/{id}/attachments', [DocumentAttachmentController::class, 'indexReversal'])
            ->middleware(['permission:inventory.view', EnforceReversalWarehouseScope::class]);
        Route::post('reversal-documents/{id}/attachments', [DocumentAttachmentController::class, 'storeReversal'])
            ->middleware(['permission:inventory.reversal.post', EnforceReversalWarehouseScope::class]);

        // Route phẳng theo attachmentId (không có {id} phiếu trong path) — warehouse
        // scope tự kiểm thủ công trong DocumentAttachmentController::assertWarehouseScope().
        Route::get('document-attachments/pending-evidence', [DocumentAttachmentController::class, 'pendingEvidence'])
            ->middleware('permission:inventory.view');
        Route::get('document-attachments/{attachmentId}/download', [DocumentAttachmentController::class, 'download'])
            ->middleware('permission:inbound.view|outbound.view|inventory.view');
        Route::delete('document-attachments/{attachmentId}', [DocumentAttachmentController::class, 'destroy'])
            ->middleware('permission:inbound.create|inbound.scan|outbound.scan|outbound.post|inventory.reversal.post');

        Route::get('document-status-history', [DocumentStatusHistoryController::class, 'index'])->middleware('permission:inventory.view');

        // Flow 06 · Báo cáo: read và export là hai quyền độc lập, BE enforce authoritative.
        //
        // BA 07/09/2026 — quyền đọc tách theo MÀN, không còn một `report.view` cho cả
        // menu (Nhân viên kho thấy 6/9 màn, Nhân viên bảo hành thấy 4/9). Ba nhóm:
        //
        //  1. Endpoint chỉ phục vụ MỘT màn -> gác bằng đúng mã màn đó
        //     (inventory-balance/inventory-reconciliation = RPT-02, import-match =
        //     RPT-04, label-log = RPT-05, warranty-* = RPT-06, outbound-by-* = RPT-07).
        //
        //  2. Endpoint dùng chung giữa vài màn -> OR các mã màn tiêu thụ nó
        //     (document-history, movement-trace, inventory-ledger). Ai vào được ít nhất
        //     một màn tiêu thụ thì đọc được — siết hơn nữa sẽ phải tách endpoint, và
        //     dữ liệu trả về vốn là một tập, chỉ khác bộ lọc/cách trình bày.
        //     RPT-01 và RPT-01B dùng CHUNG component InventoryFlowXntReport nên mã
        //     outbound_flow luôn đi kèm inventory_flow ở nhóm này.
        //
        //  3. Endpoint mà Dashboard cũng đọc -> GIỮ `report.view` (hub, inventory-xnt*,
        //     dashboard-*, catch-all {report_code}). Đổi sang mã màn sẽ làm trắng
        //     Dashboard của vai không có màn tương ứng — Dashboard không thuộc menu
        //     Báo cáo và mọi vai đều thấy nó.
        //
        // Hệ quả: cấp `report.view` cho vai mới KHÔNG còn tự mở 9 màn; phải cấp kèm mã
        // màn (xem AuthDatabaseSeeder::REPORT_SCREENS_ALL).
        Route::get('reports/hub', [OperationalReportController::class, 'hub'])->middleware('permission:report.view');
        Route::get('reports/inventory-balance', [OperationalReportController::class, 'inventoryBalance'])->middleware('permission:report.stock_reconcile.view');
        Route::get('reports/inventory-balance/export-excel', [OperationalReportController::class, 'inventoryBalanceExport'])->middleware('permission:report.export');
        Route::get('reports/inventory-xnt', [OperationalReportController::class, 'inventoryXnt'])->middleware('permission:report.view');
        Route::get('reports/inventory-xnt/export-excel', [OperationalReportController::class, 'inventoryXntExport'])->middleware('permission:report.export');
        Route::get('reports/inventory-xnt-enhanced', [OperationalReportController::class, 'inventoryXntEnhanced'])->middleware('permission:report.view');
        Route::get('reports/inventory-xnt-enhanced/export-excel', [OperationalReportController::class, 'inventoryXntEnhancedExport'])->middleware('permission:report.export');
        // GĐ1 chart FE: chuỗi Nhập/Xuất/Tồn cuối theo ngày (không có time-series
        // nào khác trong hệ thống) — đăng ký trước catch-all như mọi route cụ thể khác.
        Route::get('reports/inventory-xnt-daily', [OperationalReportController::class, 'inventoryXntDaily'])->middleware('permission:report.view');
        Route::get('reports/document-history', [OperationalReportController::class, 'documentHistory'])->middleware('permission:report.inventory_flow.view|report.outbound_flow.view|report.recipient.view|report.io_history.view');
        Route::get('reports/document-history/export-excel', [OperationalReportController::class, 'documentHistoryExport'])->middleware('permission:report.export');
        Route::get('reports/movement-trace', [OperationalReportController::class, 'movementTrace'])->middleware('permission:report.inventory_flow.view|report.outbound_flow.view|report.stock_reconcile.view|report.traceability.view');
        Route::get('reports/movement-trace/export-excel', [OperationalReportController::class, 'movementTraceExport'])->middleware('permission:report.export');
        // RPT-OUT-MATCH-01 / RPT-LABEL-01: trỏ thẳng controller Aligned (giống
        // warranty-defects) thay vì để Flow06AlignmentServiceProvider override runtime.
        // Handler legacy trong OperationalReportController đã được gỡ hẳn.
        Route::get('reports/import-match', [AlignedImportMatchReportController::class, 'index'])->middleware('permission:report.import_quality.view');
        Route::get('reports/import-match/export-excel', [AlignedImportMatchReportController::class, 'export'])->middleware('permission:report.export');
        Route::get('reports/label-log', [AlignedLabelLogReportController::class, 'index'])->middleware('permission:report.packing_label.view');
        Route::get('reports/label-log/export-excel', [AlignedLabelLogReportController::class, 'export'])->middleware('permission:report.export');
        Route::get('reports/outbound-by-agency', [OperationalReportController::class, 'outboundByAgency'])->middleware('permission:report.recipient.view');
        Route::get('reports/outbound-by-agency/export-excel', [OperationalReportController::class, 'outboundByAgencyExport'])->middleware('permission:report.export');
        // GĐ1 scope report (Nơi nhận/Thị trường/Khu vực/SKU) — trỏ thẳng controller GĐ1
        // thay vì để Flow06AlignmentServiceProvider override runtime, cùng lý do với
        // import-match/label-log/warranty-defects ở trên: route cụ thể đăng ký sau
        // "reports/{report_code}" catch-all thì không bao giờ được match tới.
        Route::get('reports/outbound-by-recipient', [BaM1ReportController::class, 'outboundByRecipient'])->middleware('permission:report.recipient.view');
        Route::get('reports/warranty-defect-sku-breakdown', [BaM1ReportController::class, 'defectSkuBreakdown'])->middleware('permission:report.warranty.view');
        // GĐ1 chart FE: top linh kiện đã xuất trong sửa chữa bảo hành (component
        // issue POSTED, join warranty_case theo received_at/created_at cùng quy ước
        // với defectSkuBreakdown/warrantyDefects ở trên).
        Route::get('reports/warranty-component-usage', [BaM1ReportController::class, 'componentUsage'])->middleware('permission:report.warranty.view');
        Route::get('reports/inventory-reconciliation', [OperationalReportController::class, 'inventoryReconciliation'])->middleware('permission:report.stock_reconcile.view');
        Route::get('reports/dashboard-quick', [OperationalReportController::class, 'dashboardQuick'])->middleware('permission:report.view');
        // Màn Dashboard tổng quan: một payload cho cả màn hình (KPI tách theo sku_type,
        // chuỗi nhập-xuất 4 chiều, cơ cấu tồn, bảo hành + quá hạn, vấn đề cần xử lý).
        // Bổ sung cho dashboard-quick chứ không thay thế — hợp đồng cũ vẫn có consumer.
        Route::get('reports/dashboard-overview', [OperationalReportController::class, 'dashboardOverview'])->middleware('permission:report.view');
        // RPT-WAR-01/RPT-WAR-COMP-01 (GĐ1-REQ-003/009): BaM1ReportController đếm theo
        // toàn bộ quan hệ warranty_case_defects (multi-defect) và giữ summary theo bộ
        // status §6 — handler Aligned cũ chỉ join defect chính nên đếm thiếu case
        // nhiều lỗi; trước đây BaM1 chỉ sống nhờ override runtime của
        // Flow06AlignmentServiceProvider, nay trỏ thẳng tại đây.
        Route::get('reports/warranty-defects', [BaM1ReportController::class, 'warrantyDefects'])->middleware('permission:report.warranty.view');
        Route::get('reports/warranty-defects/export-excel', [AlignedWarrantyReportController::class, 'export'])->middleware('permission:report.export');
        // Annex C REQ-SRS-031/032. "reports/{report_code}" là catch-all nên phải
        // đăng ký sau mọi route reports/* cụ thể ở trên.
        Route::get('reports/inventory-ledger', [OperationalReportController::class, 'inventoryLedger'])->middleware('permission:report.inventory_flow.view|report.outbound_flow.view|report.io_history.view');
        Route::post('reports/export', [OperationalReportController::class, 'createExport'])->middleware('permission:report.export');
        // Poll/download của job nền tạo bởi createExport() — cùng lý do phải đứng TRƯỚC
        // catch-all reports/{report_code} dù về mặt số segment không thực sự đụng nhau
        // (WWHN-195 shadow-route: giữ nguyên convention "mọi reports/* cụ thể đứng trước").
        Route::get('reports/export/{export_job_id}', [OperationalReportController::class, 'exportStatus'])->middleware('permission:report.export');
        Route::get('reports/export/{export_job_id}/download', [OperationalReportController::class, 'exportDownload'])->middleware('permission:report.export');
        Route::get('reports/{report_code}', [OperationalReportController::class, 'byCode'])->middleware('permission:report.view');

        // GĐ1-REQ-001: master "nơi nhận" 4 loại (DISTRIBUTOR/AGENCY/PROJECT_CUSTOMER/
        // RETAIL_CUSTOMER) trên bảng agencies lịch sử — trỏ thẳng BaM1RecipientController
        // thay vì để Flow06AlignmentServiceProvider override runtime (mong manh khi
        // route:cache/gỡ provider). Alias /recipients là contract mới, /agencies giữ
        // tương thích client cũ.
        Route::get('agencies', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
        Route::post('agencies', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
        Route::get('agencies/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
        Route::patch('agencies/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');
        Route::get('recipients', [BaM1RecipientController::class, 'index'])->middleware('permission:agency.view');
        Route::post('recipients', [BaM1RecipientController::class, 'store'])->middleware('permission:agency.manage');
        Route::get('recipients/{id}', [BaM1RecipientController::class, 'show'])->middleware('permission:agency.view');
        Route::patch('recipients/{id}', [BaM1RecipientController::class, 'update'])->middleware('permission:agency.manage');
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

        // putaway.view bổ sung: PTW-01 và màn tổng quan khay đọc danh sách khay từ
        // đây, mà vai giám sát chỉ-đọc (LEADERSHIP, WAREHOUSE_MANAGER) được BA cấp
        // putaway.view chứ không cấp container.view — họ vào được màn nhưng lời gọi
        // khay 403, hiện banner "Không có quyền truy cập" trên trang xem được.
        // Cùng bộ quyền mà GET putaway/tasks đã nhận. Ghi khay vẫn giữ container.manage.
        Route::get('containers', [ContainerController::class, 'index'])->middleware('permission:container.view|putaway.view|putaway.manage');
        Route::post('containers', [CurrentBaselineContainerFlowController::class, 'store'])->middleware('permission:container.manage');
        Route::get('containers/{id}', [ContainerController::class, 'show'])->middleware('permission:container.view|putaway.view|putaway.manage');
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
        // RPT-05 đọc nhật ký TỪNG LẦN IN từ đây. Gate cũ chỉ có label.print — một
        // quyền THAO TÁC (gửi lệnh in) — nên mọi vai chỉ-đọc có màn báo cáo nhãn
        // (LEADERSHIP, WAREHOUSE_MANAGER, VIEWER) và cả NV kho (giữ packing_label.*,
        // không giữ label.print) đều nhận 403 ở đây: /reports/label-log trả số nhưng
        // bảng sự kiện và 4 KPI của màn luôn rỗng. Đây là endpoint ĐỌC, gate đúng
        // bằng quyền đọc màn báo cáo tương ứng; label.print giữ lại cho luồng in.
        Route::get('label-print-events', [CurrentBaselineOperationsController::class, 'listLabelPrintEvents'])->middleware('permission:label.print|packing_label.print|report.packing_label.view');
        Route::get('printer-configs', [CanonicalMvpController::class, 'listPrinterConfigs'])->middleware('permission:label.print');
        Route::post('printer-configs', [CurrentBaselineOperationsController::class, 'storePrinterConfig'])->middleware('permission:label.print');
        Route::patch('printer-configs/{id}', [CurrentBaselineOperationsController::class, 'updatePrinterConfig'])->middleware('permission:label.print');

        // WWHN-234 F-05: component_issue_documents (ERD bảng 48) có warehouse_id
        // riêng; Post là action đổi tồn (COMPONENT DECREASE) nên toàn bộ endpoint
        // ghi + show phải chịu cùng "Enforcement standard" §8.1 như inbound/outbound/
        // reversal, không chỉ permission chức năng.
        Route::get('component-issue-documents', [CanonicalMvpController::class, 'listComponentIssueDocuments'])->middleware('permission:warranty.component_issue');
        Route::post('component-issue-documents', [CurrentBaselineComponentFlowController::class, 'store'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::get('component-issue-documents/{id}', [CurrentBaselineComponentIssueQueryController::class, 'show'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::post('component-issue-documents/{id}/scan', [CurrentBaselineComponentFlowController::class, 'scan'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::post('component-issue-documents/{id}/cancel', [CurrentBaselineComponentFlowController::class, 'cancel'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::post('component-issue-documents/{id}/post', [CurrentBaselineComponentFlowController::class, 'post'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);

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
            ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class, EnsureInboundComponentBox::class]);
        Route::post('inbound-documents/{id}/reversals', [CurrentBaselineStockFlowController::class, 'reverseInbound'])
            ->middleware(['permission:inventory.reversal.post', EnforceInboundWarehouseScope::class]);

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

        // WWHN-234 F-05: bí danh Annex C của cùng bề mặt — cùng guard, không lệch
        // với nhóm component-issue-documents ở trên.
        Route::get('warranty-component-issues', [CanonicalMvpController::class, 'listComponentIssueDocuments'])->middleware('permission:warranty.component_issue');
        Route::post('warranty-component-issues', [CurrentBaselineComponentFlowController::class, 'store'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::get('warranty-component-issues/{id}', [CurrentBaselineComponentIssueQueryController::class, 'show'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::post('warranty-component-issues/{id}/scans', [CurrentBaselineComponentFlowController::class, 'scan'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::post('warranty-component-issues/{id}/cancel', [CurrentBaselineComponentFlowController::class, 'cancel'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
        Route::post('warranty-component-issues/{id}/post', [CurrentBaselineComponentFlowController::class, 'post'])->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);

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
            ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

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
        // WWHN-252 (FE252-P1-03): alternative `|sku.manage` biến việc tách quyền
        // thành hình thức — ai sửa được SKU là publish được lên mặt công khai.
        // §3 xếp publish vào cột App PV với permission key riêng nên gate phải
        // đúng một quyền.
        Route::patch('pv/products/{id}/publish', [ProductController::class, 'publishPvProduct'])
            ->middleware('permission:pv.publish');

        // Annex C listPvAdminResources / createPvAdminResource / updatePvAdminResource
        // (REQ-SRS-033) — PV Industry/Tag/Product Mapping/Recommendation Rule.
        Route::get('pv-admin/resources/{resourceType}', [PvAdminResourceController::class, 'index'])
            ->middleware('permission:pv.master.manage');
        Route::post('pv-admin/resources/{resourceType}', [PvAdminResourceController::class, 'store'])
            ->middleware('permission:pv.master.manage');
        Route::patch('pv-admin/resources/{resourceType}/{id}', [PvAdminResourceController::class, 'update'])
            ->middleware('permission:pv.master.manage');

        // MST-PV-01 — upload ảnh sản phẩm lên object storage (MinIO dev / R2 deploy).
        // Cùng quyền với việc sửa hồ sơ hiển thị: ai gắn được ảnh vào sản phẩm thì
        // mới được đẩy ảnh lên.
        Route::post('pv-admin/product-images', [PvProductImageController::class, 'store'])
            ->middleware('permission:pv.master.manage');

        // SRS §6 Reversal state model: DRAFT → (confirm) → post/cancel.
        // WWHN-242: ba thao tác này đổi trạng thái/stock effect nên bắt buộc chịu
        // warehouse data scope, không chỉ function permission.
        Route::post('reversal-documents/{id}/confirm', [ReversalDocumentController::class, 'confirm'])
            ->middleware(['permission:inventory.reversal.post', EnforceReversalWarehouseScope::class]);
        Route::post('reversal-documents/{id}/post', [ReversalDocumentController::class, 'post'])
            ->middleware(['permission:inventory.reversal.post', EnforceReversalWarehouseScope::class]);
        Route::post('reversal-documents/{id}/cancel', [ReversalDocumentController::class, 'cancel'])
            ->middleware(['permission:inventory.reversal.post', EnforceReversalWarehouseScope::class]);

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
