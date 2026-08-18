<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ScanController;

Route::prefix('v1')->group(function (): void {
    Route::get('health', [HealthController::class, 'show'])->name('api.v1.health');

    // Canonical scan-classify contract. ScanController publishes this exact path
    // in OpenAPI and FE m1 consumes /scan/classify relative to /api/v1.
    // Keep the legacy /scan-batches/classify route in the Warehouse module for
    // backward compatibility; both paths execute the same read-only handler.
    Route::post('scan/classify', [ScanController::class, 'classify'])
        ->middleware(['auth:api', 'permission:scan.execute|scan.classify'])
        ->name('api.v1.scan.classify');
});
