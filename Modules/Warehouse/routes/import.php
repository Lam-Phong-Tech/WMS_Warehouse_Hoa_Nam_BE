<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ImportController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::get('skus/import-template', [ImportController::class, 'downloadTemplate'])
        ->middleware('permission:product.manage');
    Route::post('skus/import-excel', [ImportController::class, 'importExcel'])
        ->middleware('permission:product.manage');
});
