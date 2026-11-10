<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\SupplierWriteController;
use Modules\Warehouse\Http\Middleware\P03JsonEnvelope;

// Web-admin supplier writes live outside the Mini App namespace: they are global
// master data, not shift-scoped owner commands, so P14ShiftOwnerFence must not
// intercept them. The Mini App keeps its read route and legacy write routes.
Route::prefix('v1/master-data/suppliers')
    ->middleware(['auth:api', P03JsonEnvelope::class])
    ->group(function (): void {
        Route::post('/', [SupplierWriteController::class, 'store'])
            ->middleware('permission:supplier.create')
            ->name('master-data.suppliers.store');
        Route::patch('/{id}', [SupplierWriteController::class, 'update'])
            ->middleware('permission:supplier.update')
            ->name('master-data.suppliers.update');
    });
