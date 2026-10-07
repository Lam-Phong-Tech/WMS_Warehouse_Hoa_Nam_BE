<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P04InboundController;
use Modules\Warehouse\Http\Middleware\P03JsonEnvelope;

// No camera, NFC, Post Receipt or stock mutation route belongs to this adapter.
Route::prefix('v1/mini-app/inbound')->middleware(['auth:api', P03JsonEnvelope::class])->group(function (): void {
    Route::get('context', [P04InboundController::class, 'context'])->name('context');
    Route::get('types', [P04InboundController::class, 'types'])->name('types');
    Route::get('suppliers', [P04InboundController::class, 'suppliers'])->name('suppliers');
    Route::put('drafts/{id}', [P04InboundController::class, 'save'])->name('drafts.save');
    Route::get('drafts/{id}', [P04InboundController::class, 'show'])->name('drafts.show');
    Route::get('drafts/{id}/scan-session', [P04InboundController::class, 'scanSession'])->name('drafts.session');
    Route::post('drafts/{id}/validate-code', [P04InboundController::class, 'validateCode'])->name('drafts.validate');
    Route::post('drafts/{id}/review', [P04InboundController::class, 'review'])->name('drafts.review');
    Route::post('drafts/{id}/record', [P04InboundController::class, 'record'])->name('drafts.record');
    Route::get('drafts/{id}/requests/{requestId}', [P04InboundController::class, 'reconcile'])->name('drafts.reconcile');
    Route::post('drafts/{id}/cancel', [P04InboundController::class, 'cancel'])->name('drafts.cancel');
    Route::get('documents/{id}', [P04InboundController::class, 'document'])->name('documents.show');
});
