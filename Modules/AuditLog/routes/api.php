<?php

use Illuminate\Support\Facades\Route;
use Modules\AuditLog\Http\Controllers\AuditLogController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit-log.view');
});
