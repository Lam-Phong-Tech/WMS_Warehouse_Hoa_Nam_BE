<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P05OutboundController;
use Modules\Warehouse\Http\Middleware\P03JsonEnvelope;

// Read-only P05 capability and source adapters. The existing outbound/record
// route accepts optional source linkage through P05OutboundSource middleware.
// No new Post Issue, camera, NFC or inventory mutation endpoint is registered.
Route::prefix('v1/mini-app/outbound')->middleware(['auth:api', P03JsonEnvelope::class])->group(function (): void {
    Route::get('context', [P05OutboundController::class, 'context'])->name('context');
    Route::get('sources', [P05OutboundController::class, 'sources'])->name('sources.index');
    Route::get('sources/{id}', [P05OutboundController::class, 'source'])->name('sources.show');
    Route::get('documents/{id}', [P05OutboundController::class, 'document'])->name('documents.show');
});
