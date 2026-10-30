<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ImportController;
use Modules\Warehouse\Http\Controllers\ImportReportController;
use Modules\Warehouse\Http\Controllers\PvCatalogImportController;

Route::prefix('v1')->middleware('auth:api')->group(function (): void {
    Route::get('skus/import-template', [ImportController::class, 'downloadTemplate'])
        ->middleware('permission:sku.manage');
    Route::post('skus/import-excel', [ImportController::class, 'importExcel'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports', [ImportReportController::class, 'index'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports/export-excel', [ImportReportController::class, 'exportExcel'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports/{id}', [ImportReportController::class, 'show'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports/{id}/rows', [ImportReportController::class, 'rows'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports/{id}/error-file', [ImportReportController::class, 'downloadErrorFile'])
        ->middleware('permission:sku.manage');

    // App PV — import JSON hàng loạt SKU + hồ sơ trình bày (catalogue DongCheng).
    // Cần cả hai quyền vì một request ghi cả `sku` lẫn hồ sơ App PV.
    Route::post('pv-admin/catalog-import', [PvCatalogImportController::class, 'import'])
        ->middleware(['permission:sku.manage', 'permission:pv.master.manage']);
});
