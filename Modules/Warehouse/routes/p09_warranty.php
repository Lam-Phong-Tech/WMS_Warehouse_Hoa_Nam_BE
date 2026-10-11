<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppWarrantyController;
use Modules\Warehouse\Http\Controllers\P20ComponentHistoryController;
use Modules\Warehouse\Http\Controllers\P23ScanSessionController;

// Existing list/resolve/create/detail/events/update/attachment routes remain in mini_app.php.
Route::prefix('v1/mini-app')->middleware(['auth:api', 'permission:warranty.view|warranty.manage'])->group(function (): void {
    Route::get('warranty-requests/{requestId}', [MiniAppWarrantyController::class, 'reconcile'])->whereUuid('requestId')->name('warranty.request.read');
    Route::get('warranty-cases/{id}/component-issues', [P20ComponentHistoryController::class, 'index'])->whereUuid('id')->name('warranty.component-links.read');
});

// P23 session aggregate has independent source permissions, not warranty.manage.
// Keep the four P08 history routes unchanged; reuse its existing batch detail.
Route::get('v1/mini-app/scan-sessions', [P23ScanSessionController::class, 'index'])
    ->middleware(['auth:api', 'permission:history.read', 'permission:inventory.view'])
    ->name('p23.scan-sessions.read');
