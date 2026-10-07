<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P03DraftController;
use Modules\Warehouse\Http\Middleware\P03JsonEnvelope;

Route::prefix('v1/mini-app')->middleware(['auth:api', P03JsonEnvelope::class])->group(function (): void {
    Route::get('p03/context', [P03DraftController::class, 'context'])->name('context');
    // Per-operation permissions and actor/session/warehouse intersection are enforced
    // inside the same transaction as the draft/receipt, before any replay is returned.
    Route::get('drafts', [P03DraftController::class, 'index'])->name('drafts.index');
    Route::get('drafts/{id}/requests/{requestId}', [P03DraftController::class, 'reconcile'])->name('drafts.reconcile');
    Route::get('drafts/{id}', [P03DraftController::class, 'show'])->name('drafts.show');
    Route::put('drafts/{id}', [P03DraftController::class, 'save'])->name('drafts.save');
    Route::post('drafts/{id}/cancel', [P03DraftController::class, 'cancel'])->name('drafts.cancel');
});
