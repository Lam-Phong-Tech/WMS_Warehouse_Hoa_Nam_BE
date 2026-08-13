<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\PermissionController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\UserController;
use Modules\Auth\Http\Middleware\EnsureMutableRole;
use Modules\Auth\Http\Middleware\PersistRefreshTokenCookie;
use Modules\Auth\Http\Middleware\PreventSelfRoleAssignment;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware(PersistRefreshTokenCookie::class);
    Route::post('auth/refresh', [AuthController::class, 'refresh'])
        ->middleware(PersistRefreshTokenCookie::class);

    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->middleware(PersistRefreshTokenCookie::class);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword'])
            ->middleware(PersistRefreshTokenCookie::class);
        Route::post('auth/switch-context', [AuthController::class, 'switchContext']);

        Route::get('users', [UserController::class, 'index'])->middleware('permission:iam.user.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:iam.user.manage');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:iam.user.view');
        Route::patch('users/{id}', [UserController::class, 'update'])->middleware('permission:iam.user.manage');
        Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('permission:iam.user.manage');
        Route::post('users/{id}/roles', [UserController::class, 'assignRoles'])
            ->middleware(['permission:iam.user.manage', PreventSelfRoleAssignment::class]);

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:iam.role.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:iam.role.manage');
        Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:iam.role.view');
        Route::patch('roles/{id}', [RoleController::class, 'update'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);
        Route::post('roles/{id}/permissions', [RoleController::class, 'assignPermissions'])
            ->middleware(['permission:iam.role.manage', EnsureMutableRole::class]);

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:iam.permission.view');
        Route::get('permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:iam.permission.view');
    });
});
