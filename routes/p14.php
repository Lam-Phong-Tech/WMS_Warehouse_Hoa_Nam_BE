<?php

use App\Http\Controllers\P14RecoveryController;
use App\Http\Controllers\P14ShiftController;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Support\MiniAppWorkShiftService;

// Six implemented candidate routes, not a declaration of production activation.
Route::prefix('api/v1')->middleware('api')->group(function (): void {
    Route::post('auth/recovery-requests', [P14RecoveryController::class, 'store']);
    Route::get('auth/recovery-requests/operations/{operationId}', [P14RecoveryController::class, 'show']);
    Route::prefix('mini-app/work-shifts')->middleware(['auth:api', 'permission:'.MiniAppWorkShiftService::PERMISSIONS])
        ->group(function (): void {
            Route::get('{shiftId}/close-check', [P14ShiftController::class, 'check'])->whereUuid('shiftId');
            Route::post('{shiftId}/end', [P14ShiftController::class, 'end'])->whereUuid('shiftId');
            Route::get('{shiftId}/requests/{requestId}', [P14ShiftController::class, 'reconcile'])->whereUuid(['shiftId', 'requestId']);
            Route::get('{shiftId}/summary', [P14ShiftController::class, 'summary'])->whereUuid('shiftId');
        });
});
