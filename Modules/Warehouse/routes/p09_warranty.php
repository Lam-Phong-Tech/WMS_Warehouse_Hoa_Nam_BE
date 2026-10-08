<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppWarrantyController;

// Existing list/resolve/create/detail/events/update/attachment routes remain in mini_app.php.
Route::prefix('v1/mini-app')->middleware(['auth:api', 'permission:warranty.view|warranty.manage'])->group(function (): void {
    Route::get('warranty-requests/{requestId}', [MiniAppWarrantyController::class, 'reconcile'])->whereUuid('requestId')->name('warranty.request.read');
    Route::get('warranty-cases/{id}/component-issues', [MiniAppWarrantyController::class, 'componentIssues'])->whereUuid('id')->name('warranty.component-links.read');
});
