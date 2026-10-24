<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\DepartmentController;
use Modules\Auth\Http\Controllers\MfaController;
use Modules\Auth\Http\Controllers\PasswordResetController;
use Modules\Auth\Http\Controllers\PermissionController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\UserController;
use Modules\Auth\Http\Controllers\WarehouseContextController;
use Modules\Auth\Http\Middleware\EnsureMutableRole;
use Modules\Auth\Http\Middleware\PersistRefreshTokenCookie;
use Modules\Auth\Http\Middleware\PreventSelfRoleAssignment;
use Modules\Auth\Http\Middleware\PreventSuperAdminRoleGrant;
use Modules\Auth\Http\Middleware\ProtectSuperAdminTarget;
use Modules\Auth\Support\P11SecurityCommands;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware(PersistRefreshTokenCookie::class);
    Route::post('auth/refresh', [AuthController::class, 'refresh'])
        ->middleware([PersistRefreshTokenCookie::class, 'throttle:auth-refresh']);

    // Quên mật khẩu bằng OTP email — public, không cần auth:api. Gọi lại
    // forgot-password để "gửi lại mã" (không có endpoint resend riêng).
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('auth/verify-otp', [PasswordResetController::class, 'verifyOtp']);
    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword']);

    // WWHN-SEC-08 — bước 2 của đăng nhập. KHÔNG có `auth:api`: đúng lúc gọi nó
    // người dùng chưa có access token nào (mật khẩu mới chỉ đúng nửa quá trình).
    // Chống dò 6 chữ số bằng cả throttle theo IP ở đây LẪN trần theo tài khoản
    // trong MfaService — chỉ một trong hai là không đủ: throttle IP bị vượt bằng
    // botnet, còn trần theo tài khoản không chặn được kẻ rải mã qua nhiều tài khoản.
    Route::post('auth/mfa/verify', [MfaController::class, 'verify'])
        ->middleware(['throttle:auth-mfa-verify', PersistRefreshTokenCookie::class]);

    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->middleware(PersistRefreshTokenCookie::class);
        Route::get('auth/me', [AuthController::class, 'me']);
        // Sửa hồ sơ của CHÍNH mình: chỉ display_name/phone, không bao giờ vai trò/quyền.
        Route::patch('auth/me', [AuthController::class, 'updateMe']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword'])
            ->middleware(PersistRefreshTokenCookie::class);
        Route::post('auth/switch-context', [WarehouseContextController::class, 'switchContext']);

        // Thiết bị đang đăng nhập của CHÍNH mình — mỗi phiên là một refresh token còn
        // hiệu lực. Lọc cứng theo user đang gọi; id phiên của người khác trả 404.
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession']);
        // Explicit candidate protocol, OFF by default; revoked tokens remain blocked.
        Route::get('auth/security-requests/{key}', [P11SecurityCommands::class, 'show']);
        Route::post('auth/security-requests/{key}/verify', [P11SecurityCommands::class, 'verify'])
            ->middleware('throttle:auth-mfa-verify');

        // WWHN-SEC-08 — quản lý MFA của CHÍNH tài khoản đang đăng nhập.
        // Không có `permission:` vì đây là self-service, cùng nhóm với
        // change-password và auth/me.
        Route::get('auth/mfa/status', [MfaController::class, 'status']);
        Route::post('auth/mfa/enroll', [MfaController::class, 'enroll']);
        Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
        Route::post('auth/mfa/disable', [MfaController::class, 'disable']);

        Route::get('users', [UserController::class, 'index'])->middleware('permission:iam.user.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:iam.user.manage');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:iam.user.view');
        // SCR-IAM-03: sửa tài khoản đang giữ SUPER_ADMIN (đặt lại mật khẩu, khoá
        // tài khoản) là đường leo thang trực tiếp — chỉ super admin khác mới được.
        Route::patch('users/{id}', [UserController::class, 'update'])
            ->middleware(['permission:iam.user.manage', ProtectSuperAdminTarget::class]);
        // SCR-IAM-01 chặn tự đổi role của mình; SCR-IAM-04 chặn đường vòng "tạo
        // tài khoản mới rồi cấp SUPER_ADMIN cho nó và đăng nhập bằng nó".
        Route::post('users/{id}/roles', [UserController::class, 'assignRoles'])
            ->middleware([
                'permission:iam.user.manage',
                PreventSelfRoleAssignment::class,
                ProtectSuperAdminTarget::class,
                PreventSuperAdminRoleGrant::class,
            ]);

        // Quyền cấp riêng theo tài khoản: cộng thêm vào quyền theo role (grant-only).
        Route::get('users/{id}/permissions', [UserController::class, 'listPermissionOverrides'])
            ->middleware('permission:iam.user.view');
        Route::post('users/{id}/permissions', [UserController::class, 'grantPermission'])
            ->middleware(['permission:iam.user.manage', PreventSelfRoleAssignment::class, ProtectSuperAdminTarget::class]);
        Route::delete('users/{id}/permissions/{permissionId}', [UserController::class, 'revokePermission'])
            ->middleware(['permission:iam.user.manage', PreventSelfRoleAssignment::class, ProtectSuperAdminTarget::class]);

        // GĐ1 REQ-011: department is a managed master, not a hard-coded enum.
        Route::get('departments', [DepartmentController::class, 'index'])
            ->middleware('permission:iam.user.view');
        Route::post('departments', [DepartmentController::class, 'store'])
            ->middleware('permission:iam.user.manage');
        Route::patch('departments/{id}', [DepartmentController::class, 'update'])
            ->middleware('permission:iam.user.manage');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:iam.role.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:iam.role.manage');
        Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:iam.role.view');
        Route::patch('roles/{id}', [RoleController::class, 'update'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);
        Route::post('roles/{id}/permissions', [RoleController::class, 'assignPermissions'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);
        Route::put('roles/{id}/permissions', [RoleController::class, 'assignPermissions'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:iam.permission.view');
        Route::get('permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:iam.permission.view');
    });
});
