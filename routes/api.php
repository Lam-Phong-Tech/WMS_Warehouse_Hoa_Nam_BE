<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ImportController;
use Modules\Warehouse\Http\Controllers\SkuGovernanceController;

Route::prefix('v1')->group(function (): void {
    Route::get('health', [HealthController::class, 'show'])->name('api.v1.health');

    // WWHN-172 write contracts that are not present in the legacy module route file.
    // Route import-template phải đứng trước dynamic GET /v1/skus/{id} của module.
    Route::middleware('auth:api')->group(function (): void {
        Route::get('skus/import-template', [ImportController::class, 'downloadTemplate'])
            ->middleware('permission:sku.manage');
        Route::patch('skus/{id}', [SkuGovernanceController::class, 'update'])
            ->middleware('permission:sku.manage');
        Route::post('skus/{id}/physical-codes', [SkuGovernanceController::class, 'addPhysicalCode'])
            ->middleware('permission:sku.manage');
        Route::delete('skus/{id}/physical-codes/{physicalCodeId}', [SkuGovernanceController::class, 'revokePhysicalCode'])
            ->middleware('permission:sku.manage');

    });
});
