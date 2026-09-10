<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\BroadcastAuthController;
use Modules\Notification\Http\Controllers\NotificationController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    // Đặt SAU unread-count: khai `{id}` trước thì nó nuốt luôn `unread-count` thành id.
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
});
