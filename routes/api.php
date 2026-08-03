<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ContainerController;
use Modules\Warehouse\Http\Controllers\SkuGovernanceController;

Route::get('health', [HealthController::class, 'show'])->name('api.health');

// WWHN-172 write contracts that are not present in the legacy module route file.
// Existing GET/POST /v1/skus remain registered by Modules/Warehouse/routes/api.php.
Route::prefix('v1')->middleware('auth:api')->group(function (): void {
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

// Route nghiệp vụ còn lại nằm trong Routes/api.php của từng module (nwidart/laravel-modules),
// tự đăng ký qua <Module>ServiceProvider::boot().
