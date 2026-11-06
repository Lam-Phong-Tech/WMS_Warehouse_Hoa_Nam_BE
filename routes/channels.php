<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\WarehouseDataAccess;

// App dùng JWT (auth:api) cho mọi request — channel auth (POST
// /api/v1/broadcasting/auth, xem Modules/Notification/routes/api.php) resolve
// $user qua guard `api`, không phải session mặc định của Laravel.
Broadcast::channel('user.{userId}', function ($user, string $userId): bool {
    return (string) $user->user_id === $userId;
});

Broadcast::channel('warehouse-data', function ($user): bool {
    return $user instanceof User && WarehouseDataAccess::allows($user);
}, ['guards' => ['api']]);
