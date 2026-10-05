<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppWorkShiftController;
use Modules\Warehouse\Support\MiniAppWorkShiftService;

Route::prefix('v1/mini-app/work-shifts')
    ->middleware(['auth:api', 'permission:'.MiniAppWorkShiftService::PERMISSIONS])
    ->group(function (): void {
        Route::get('current', [MiniAppWorkShiftController::class, 'current'])->name('mini-app.work-shifts.current');
        Route::post('start', [MiniAppWorkShiftController::class, 'start'])->name('mini-app.work-shifts.start');
    });
