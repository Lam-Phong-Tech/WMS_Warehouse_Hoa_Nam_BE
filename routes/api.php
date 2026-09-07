<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ScanController;

Route::prefix('v1')->group(function (): void {
    Route::get('health', [HealthController::class, 'show'])->name('api.v1.health');

    // Canonical scan-classify contract. ScanController publishes this exact path
    // in OpenAPI and FE m1 consumes /scan/classify relative to /api/v1.
    // Keep the legacy /scan-batches/classify route in the Warehouse module for
    // backward compatibility; both paths execute the same read-only handler.
    // RUM: beacon Web Vitals bắn lúc tab bị ẩn/đóng nên KHÔNG gắn auth:api —
    // lúc đó access token trong bộ nhớ có thể đã không còn, mà đúng những phiên
    // ấy mới là phiên đáng đo. Endpoint chỉ ghi bộ đếm trong cache; chặn lạm
    // dụng bằng throttle 120 request/phút cho mỗi IP.
    Route::post('telemetry/vitals', [TelemetryController::class, 'vitals'])
        ->middleware('throttle:120,1')
        ->name('api.v1.telemetry.vitals');

    Route::post('scan/classify', [ScanController::class, 'classify'])
        ->middleware(['auth:api', 'permission:scan.execute|scan.classify'])
        ->name('api.v1.scan.classify');
});
