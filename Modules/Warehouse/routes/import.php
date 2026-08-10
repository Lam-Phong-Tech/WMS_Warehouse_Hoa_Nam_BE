<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ImportController;

Route::prefix('v1')->middleware('auth:api')->group(function (): void {
    Route::get('skus/import-template', [ImportController::class, 'downloadTemplate'])
        ->middleware('permission:sku.manage');
    Route::post('skus/import-excel', [ImportController::class, 'importExcel'])
        ->middleware('permission:sku.manage');
});
