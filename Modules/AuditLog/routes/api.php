<?php

use Illuminate\Support\Facades\Route;
use Modules\AuditLog\Http\Controllers\AuditLogController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit_log.view');
    // Tự-xem lịch sử thay đổi quyền/role trên CHÍNH tài khoản mình — không cần
    // permission audit_log.view, chỉ cần đăng nhập. object_type/object_id bị
    // ép cứng về user hiện tại trong controller, không tin client truyền lên.
    Route::get('audit-logs/me', [AuditLogController::class, 'myHistory']);
});
