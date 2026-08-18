<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ImportController;
use Modules\Warehouse\Http\Controllers\ImportReportController;

Route::prefix('v1')->middleware('auth:api')->group(function (): void {
    Route::get('skus/import-template', [ImportController::class, 'downloadTemplate'])
        ->middleware('permission:sku.manage');
    Route::post('skus/import-excel', [ImportController::class, 'importExcel'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports', [ImportReportController::class, 'index'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports/{id}', [ImportReportController::class, 'show'])
        ->middleware('permission:sku.manage');
    Route::get('skus/import-reports/{id}/error-file', [ImportReportController::class, 'downloadErrorFile'])
        ->middleware('permission:sku.manage');
});
