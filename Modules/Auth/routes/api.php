<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\PermissionController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\UserController;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:api')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('users', [UserController::class, 'index'])->middleware('permission:user.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:user.manage');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:user.view');
        Route::patch('users/{id}', [UserController::class, 'update'])->middleware('permission:user.manage');
        Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('permission:user.manage');
        Route::post('users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:user.manage');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:role.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:role.manage');
        Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:role.view');
        Route::patch('roles/{id}', [RoleController::class, 'update'])->middleware('permission:role.manage');
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:role.manage');
        Route::post('roles/{id}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:role.manage');

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permission.view');
        Route::get('permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:permission.view');

        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('auth/switch-context', [AuthController::class, 'switchContext']);
    });
});
