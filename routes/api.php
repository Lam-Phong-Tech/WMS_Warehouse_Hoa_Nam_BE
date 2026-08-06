<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ContainerController;
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

        Route::get('containers', [ContainerController::class, 'index'])
            ->middleware('permission:inventory.view');
        Route::get('containers/{id}', [ContainerController::class, 'show'])
            ->middleware('permission:inventory.view');
        Route::post('containers', [ContainerController::class, 'store'])
            ->middleware('permission:warehouse.manage');
        Route::patch('containers/{id}', [ContainerController::class, 'update'])
            ->middleware('permission:warehouse.manage');
        Route::post('containers/{id}/seal', [ContainerController::class, 'seal'])
            ->middleware('permission:warehouse.manage');
    });
});
