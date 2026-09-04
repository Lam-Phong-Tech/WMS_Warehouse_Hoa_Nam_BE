<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\CanonicalMvpController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineComponentFlowController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineComponentIssueQueryController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineWarrantyAttachmentController;
use Modules\Warehouse\Http\Controllers\DocumentAttachmentController;
use Modules\Warehouse\Http\Controllers\MiniAppInboundController;
use Modules\Warehouse\Http\Controllers\MiniAppInboundStagingController;
use Modules\Warehouse\Http\Controllers\MiniAppNfcController;
use Modules\Warehouse\Http\Controllers\MiniAppOutboundController;
use Modules\Warehouse\Http\Controllers\MiniAppOutboundDraftController;
use Modules\Warehouse\Http\Controllers\MiniAppOutboundStagingController;
use Modules\Warehouse\Http\Controllers\MiniAppProductController;
use Modules\Warehouse\Http\Controllers\MiniAppWarrantyController;
use Modules\Warehouse\Http\Middleware\EnforceComponentIssueWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceInboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnforceOutboundWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureCanonicalInboundWarehouse;
use Modules\Warehouse\Http\Middleware\EnsureInboundComponentBox;

/*
|--------------------------------------------------------------------------
| Warehouse Mini App API
|--------------------------------------------------------------------------
|
| Thin mobile adapters over the canonical WMS domain. Mini App does not own a
| second stock state machine. Inbound changes stock only at Post Receipt;
| outbound changes stock only at Post Issue.
|
*/
Route::prefix('v1/mini-app')->middleware('auth:api')->group(function (): void {
    // ── Shared Mini App support ──────────────────────────────────────────
    Route::get('printer-configs', [CanonicalMvpController::class, 'listPrinterConfigs'])
        ->middleware('permission:label.print');

    // ── Product lookup ───────────────────────────────────────────────────
    // Read-only tra cứu SKU cho Mini App (dùng chung permission sku.view với
    // ProductController canonical). Trả kèm available_qty tại Kho tổng Hoa Nam
    // vì Mini App chỉ vận hành trên đúng 1 kho.
    Route::get('products', [MiniAppProductController::class, 'index'])
        ->middleware('permission:sku.view');
    Route::get('products/{id}', [MiniAppProductController::class, 'show'])
        ->middleware('permission:sku.view');

    // ── NFC (gán chip cho từng hiện vật) ─────────────────────────────────
    // Chip chỉ mang lại đúng payload QR của hiện vật (DEC-QR-001) nên quét NFC
    // dùng được ở MỌI endpoint scan sẵn có (/scan/classify, inbound, outbound,
    // warranty); nhóm route dưới đây chỉ lo vòng đời mapping item ↔ hardware_uid.
    // Toàn bộ stock-neutral: không endpoint nào ở đây tạo movement hay đổi
    // inventory_balances.
    //
    // Ghi chip là thao tác vật lý không hoàn tác được nên tách hai bước
    // prepare -> confirm (PATCH), thay vì một lần ghi thẳng.
    Route::get('items', [MiniAppNfcController::class, 'listItems'])
        ->middleware('permission:physical_code.assign_rfid|item_instance.view|inventory.view');

    Route::post('items/{itemId}/nfc/prepare', [MiniAppNfcController::class, 'prepare'])
        ->middleware('permission:physical_code.assign_rfid');

    Route::patch('items/{itemId}/nfc', [MiniAppNfcController::class, 'confirm'])
        ->middleware('permission:physical_code.assign_rfid');

    Route::post('nfc/resolve-code', [MiniAppNfcController::class, 'resolveCode'])
        ->middleware('permission:physical_code.assign_rfid');

    Route::post('nfc/resolve-tag', [MiniAppNfcController::class, 'resolveTag'])
        ->middleware('permission:physical_code.assign_rfid|inventory.trace|warranty.view');

    Route::get('nfc-tags', [MiniAppNfcController::class, 'listTags'])
        ->middleware('permission:physical_code.assign_rfid|inventory.trace');

    Route::post('nfc-tags/{physicalCodeId}/deactivate', [MiniAppNfcController::class, 'deactivate'])
        ->middleware('permission:physical_code.assign_rfid');

    // ── Inbound ──────────────────────────────────────────────────────────
    Route::post('inbound/resolve-code', [MiniAppInboundStagingController::class, 'resolveCode'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::post('inbound/record', [MiniAppInboundStagingController::class, 'record'])
        ->middleware([
            'permission:inbound.create',
            'permission:inbound.scan',
            EnforceInboundWarehouseScope::class,
            EnsureCanonicalInboundWarehouse::class,
        ]);

    Route::get('inbound-documents', [MiniAppInboundController::class, 'index'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents', [MiniAppInboundController::class, 'store'])
        ->middleware(['permission:inbound.create', EnforceInboundWarehouseScope::class, EnsureCanonicalInboundWarehouse::class]);

    Route::get('inbound-documents/{id}', [MiniAppInboundController::class, 'show'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/cancel', [MiniAppInboundController::class, 'cancel'])
        ->middleware(['permission:inbound.cancel|inbound.create', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/boxes', [MiniAppInboundController::class, 'registerBox'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/scan', [MiniAppInboundController::class, 'scan'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class, EnsureInboundComponentBox::class]);

    Route::post('inbound-documents/{id}/unscan', [MiniAppInboundController::class, 'unscan'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::get('inbound-documents/{id}/scan-entries', [MiniAppInboundController::class, 'scanEntries'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/cartons/{containerId}/complete-scan', [MiniAppInboundController::class, 'completeCartonScan'])
        ->middleware(['permission:inbound.scan', EnforceInboundWarehouseScope::class]);

    Route::post('inbound-documents/{id}/post-receipt', [MiniAppInboundController::class, 'approve'])
        ->middleware(['permission:inbound.post', EnforceInboundWarehouseScope::class]);

    // "Chứng từ nhập/xuất/hoàn trả": cùng route/permission với api.php (chỉ khác
    // prefix v1/mini-app) — DocumentAttachmentController dùng chung cho cả 2 kênh.
    Route::get('inbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'indexInbound'])
        ->middleware(['permission:inbound.view', EnforceInboundWarehouseScope::class]);
    Route::post('inbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'storeInbound'])
        ->middleware(['permission:inbound.create|inbound.scan', EnforceInboundWarehouseScope::class]);

    // ── Outbound ─────────────────────────────────────────────────────────
    // Preferred Mini App UX mirrors inbound: keep the camera batch local,
    // resolve each code without a WMS document, then atomically record N/N.
    Route::post('outbound/resolve-code', [MiniAppOutboundStagingController::class, 'resolveCode'])
        ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound/record', [MiniAppOutboundStagingController::class, 'record'])
        ->middleware([
            'permission:outbound.request.manual_create|outbound.import',
            'permission:outbound.scan',
            EnforceOutboundWarehouseScope::class,
        ]);

    // Compatibility surface: clients may still create a SKU-planned DRAFT first.
    // New Mini App screens should prefer outbound/resolve-code + outbound/record.
    Route::post('outbound-documents', [MiniAppOutboundDraftController::class, 'store'])
        ->middleware(['permission:outbound.request.manual_create|outbound.import', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents', [MiniAppOutboundController::class, 'index'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents/{id}', [MiniAppOutboundController::class, 'show'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/cancel', [MiniAppOutboundController::class, 'cancel'])
        ->middleware(['permission:outbound.cancel', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/scan', [MiniAppOutboundController::class, 'scan'])
        ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/unmatch', [MiniAppOutboundController::class, 'unmatch'])
        ->middleware(['permission:outbound.scan', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents/{id}/scan-entries', [MiniAppOutboundController::class, 'scanEntries'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::get('outbound-documents/{id}/packing-label', [MiniAppOutboundController::class, 'packingLabel'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/preview', [MiniAppOutboundController::class, 'previewPackingLabel'])
        ->middleware(['permission:packing_label.preview|label.print', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/ready', [MiniAppOutboundController::class, 'readyPackingLabel'])
        ->middleware(['permission:packing_label.ready|label.print', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/print', [MiniAppOutboundController::class, 'printPackingLabel'])
        ->middleware(['permission:packing_label.print|label.print', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/reprint', [MiniAppOutboundController::class, 'reprintPackingLabel'])
        ->middleware(['permission:packing_label.reprint|label.reprint', EnforceOutboundWarehouseScope::class]);

    Route::post('outbound-documents/{id}/packing-labels/{labelId}/apply', [MiniAppOutboundController::class, 'applyPackingLabel'])
        ->middleware(['permission:label.print', EnforceOutboundWarehouseScope::class]);

    // This is the only Mini App outbound endpoint that decreases inventory.
    Route::post('outbound-documents/{id}/post-issue', [MiniAppOutboundController::class, 'postIssue'])
        ->middleware(['permission:outbound.post', EnforceOutboundWarehouseScope::class]);

    // "Chứng từ nhập/xuất/hoàn trả": xem ghi chú ở nhóm inbound-documents/attachments
    // phía trên — cùng route/permission với api.php.
    Route::get('outbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'indexOutbound'])
        ->middleware(['permission:outbound.view', EnforceOutboundWarehouseScope::class]);
    Route::post('outbound-documents/{id}/attachments', [DocumentAttachmentController::class, 'storeOutbound'])
        ->middleware(['permission:outbound.scan|outbound.post', EnforceOutboundWarehouseScope::class]);

    // Route phẳng theo attachmentId, dùng chung inbound/outbound (reversal không có
    // màn hình Mini App) — cùng route/permission với nhóm tương ứng trong api.php.
    Route::get('document-attachments/pending-evidence', [DocumentAttachmentController::class, 'pendingEvidence'])
        ->middleware('permission:inventory.view');
    Route::get('document-attachments/{attachmentId}/download', [DocumentAttachmentController::class, 'download'])
        ->middleware('permission:inbound.view|outbound.view|inventory.view');
    Route::delete('document-attachments/{attachmentId}', [DocumentAttachmentController::class, 'destroy'])
        ->middleware('permission:inbound.create|inbound.scan|outbound.scan|outbound.post');

    // â”€â”€ Warranty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::post('warranty/resolve-code', [MiniAppWarrantyController::class, 'resolveCode'])
        ->middleware('permission:warranty.view|warranty.manage');

    Route::get('warranty-cases', [MiniAppWarrantyController::class, 'index'])
        ->middleware('permission:warranty.view|warranty.manage');
    Route::post('warranty-cases', [MiniAppWarrantyController::class, 'store'])
        ->middleware('permission:warranty.manage');
    Route::get('warranty-cases/{id}', [MiniAppWarrantyController::class, 'show'])
        ->middleware('permission:warranty.view|warranty.manage');
    Route::patch('warranty-cases/{id}', [MiniAppWarrantyController::class, 'update'])
        ->middleware('permission:warranty.manage');
    Route::post('warranty-cases/{id}/status', [MiniAppWarrantyController::class, 'update'])
        ->middleware('permission:warranty.manage');
    Route::get('warranty-cases/{id}/events', [MiniAppWarrantyController::class, 'events'])
        ->middleware('permission:warranty.view|warranty.manage');

    Route::get('warranty-cases/{id}/attachments', [CurrentBaselineWarrantyAttachmentController::class, 'index'])
        ->middleware('permission:warranty.view|warranty.manage');
    Route::post('warranty-cases/{id}/attachments', [CurrentBaselineWarrantyAttachmentController::class, 'store'])
        ->middleware('permission:warranty.manage');
    Route::get('warranty-attachments/{attachmentId}/download', [CurrentBaselineWarrantyAttachmentController::class, 'download'])
        ->middleware('permission:warranty.view|warranty.manage');
    Route::delete('warranty-attachments/{attachmentId}', [CurrentBaselineWarrantyAttachmentController::class, 'destroy'])
        ->middleware('permission:warranty.manage');

    Route::get('warranty-component-issues', [CanonicalMvpController::class, 'listComponentIssueDocuments'])
        ->middleware('permission:warranty.component_issue');
    Route::post('warranty-component-issues', [CurrentBaselineComponentFlowController::class, 'store'])
        ->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
    Route::get('warranty-component-issues/{id}', [CurrentBaselineComponentIssueQueryController::class, 'show'])
        ->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
    Route::post('warranty-component-issues/{id}/scans', [CurrentBaselineComponentFlowController::class, 'scan'])
        ->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
    Route::post('warranty-component-issues/{id}/cancel', [CurrentBaselineComponentFlowController::class, 'cancel'])
        ->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
    Route::post('warranty-component-issues/{id}/post', [CurrentBaselineComponentFlowController::class, 'post'])
        ->middleware(['permission:warranty.component_issue', EnforceComponentIssueWarehouseScope::class]);
});
