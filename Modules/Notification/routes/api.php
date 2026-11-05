<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\BroadcastAuthController;
use Modules\Notification\Http\Controllers\NotificationController;
use Modules\Notification\Http\Controllers\NotificationDeliveryController;
use Modules\Notification\Http\Controllers\NotificationDeliverySelfTestController;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::get('notification-delivery/capabilities', [NotificationDeliveryController::class, 'capabilities']);
    Route::post('notification-delivery/test-self',[NotificationDeliverySelfTestController::class,'store']);
    Route::get('notification-delivery/test-self/{requestKey}',[NotificationDeliverySelfTestController::class,'show']);
    Route::get('notification-delivery/installations/{installationId}', [NotificationDeliveryController::class, 'show']);
    Route::put('notification-delivery/installations/{installationId}', [NotificationDeliveryController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('notification-delivery/installations/{installationId}', [NotificationDeliveryController::class, 'destroy'])->middleware('throttle:20,1');
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    // Đặt SAU unread-count: khai `{id}` trước thì nó nuốt luôn `unread-count` thành id.
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
});
