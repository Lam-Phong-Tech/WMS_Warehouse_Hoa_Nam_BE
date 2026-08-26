<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;

/**
 * Xác định user_id nào nhận một thông báo. Dùng lại nguyên trạng
 * User::hasPermission()/canAccessWarehouse() (Modules/Auth) — không phát minh
 * cơ chế scope mới cho riêng module Notification.
 */
class NotificationAudienceResolver
{
    /**
     * @param  array<int,string>  $excludeUserIds
     * @return array<int,string>
     */
    public function byPermission(string $permissionKey, ?string $warehouseId = null, array $excludeUserIds = []): array
    {
        return $this->activeUsers()
            ->filter(fn (User $user): bool => $user->hasPermission($permissionKey))
            ->filter(fn (User $user): bool => $warehouseId === null || $user->canAccessWarehouse($warehouseId))
            ->pluck('user_id')
            ->diff($excludeUserIds)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Người đang giữ một role cụ thể (bất kể scope_ref) — dùng cho thông báo
     * "quyền của bạn vừa thay đổi", không cần lọc theo effective window.
     *
     * @param  array<int,string>  $excludeUserIds
     * @return array<int,string>
     */
    public function byRoleId(string $roleId, array $excludeUserIds = []): array
    {
        return DB::table('user_role')
            ->where('role_id', $roleId)
            ->pluck('user_id')
            ->diff($excludeUserIds)
            ->unique()
            ->values()
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function activeUsers(): \Illuminate\Support\Collection
    {
        return User::query()->where('status', 'ACTIVE')->get();
    }
}
