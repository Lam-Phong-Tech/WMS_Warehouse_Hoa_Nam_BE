<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\StocktakeController;

Route::prefix('v1')->middleware('auth:api')->group(function (): void {
    // Backward-compatible alias is intentionally routed through the scoped controller.
    Route::get('stocktake/sessions', [StocktakeController::class, 'index'])
        ->middleware('permission:inventory.view');
    Route::get('stocktake-sessions', [StocktakeController::class, 'index'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake-sessions', [StocktakeController::class, 'store'])
        ->middleware('permission:stocktake.adjust');
    Route::get('stocktake-sessions/{id}', [StocktakeController::class, 'show'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake-sessions/{id}/start', [StocktakeController::class, 'start'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake-sessions/{id}/counts', [StocktakeController::class, 'recordCounts'])
        ->middleware('permission:stocktake.adjust');
    Route::post('stocktake-sessions/{id}/review', [StocktakeController::class, 'review'])
        ->middleware('permission:stocktake.adjust');
    Route::get('stocktake-sessions/{id}/variances', [StocktakeController::class, 'variances'])
        ->middleware('permission:inventory.view');
    Route::post('stocktake-sessions/{id}/acknowledge-movements', [StocktakeController::class, 'acknowledgeMovements'])
        ->middleware('permission:stocktake.adjust');
});
