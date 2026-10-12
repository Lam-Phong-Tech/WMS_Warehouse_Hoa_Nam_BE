<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P12MixedDocumentsController;
use Modules\Warehouse\Http\Middleware\P12MixedDocumentsReadGuard;

Route::get('v1/mini-app/documents', P12MixedDocumentsController::class)
    ->middleware(['auth:api', P12MixedDocumentsReadGuard::class,
        'permission:inbound.view|outbound.view|warranty.view|warranty.manage'])
    ->name('documents.index');
