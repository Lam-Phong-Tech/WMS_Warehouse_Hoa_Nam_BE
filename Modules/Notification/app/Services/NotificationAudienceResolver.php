<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Models\User;
use Throwable;

/**
 * Xác định user_id nào nhận một thông báo. Dùng lại nguyên trạng
 * User::hasPermission()/canAccessWarehouse() (Modules/Auth) — không phát minh
 * cơ chế scope mới cho riêng module Notification.
 */
class NotificationAudienceResolver
{
    private const AUDIENCE_CACHE_SECONDS = 5;

    /**
     * @param  array<int,string>  $excludeUserIds
     * @return array<int,string>
     */
    public function byPermission(string $permissionKey, ?string $warehouseId = null, array $excludeUserIds = []): array
    {
        $resolve = fn (): array => $this->activeUsers()
            ->filter(fn (User $user): bool => $user->hasPermission($permissionKey))
            ->filter(fn (User $user): bool => $warehouseId === null || $user->canAccessWarehouse($warehouseId))
            ->pluck('user_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        try {
            $version = (int) Cache::get('notification:audience:version', 1);
            $cacheKey = 'notification:audience:v'.$version.':'.sha1($permissionKey.'|'.($warehouseId ?? '*'));
            $ids = Cache::remember($cacheKey, now()->addSeconds(self::AUDIENCE_CACHE_SECONDS), $resolve);
        } catch (Throwable) {
            // Cache is optional; resolve fresh if Valkey/cache is unavailable.
            $ids = $resolve();
        }

        return array_values(array_diff($ids, $excludeUserIds));
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
        $now = now();

        return User::query()
            ->where('status', 'ACTIVE')
            ->whereIn('user_id', function ($query) use ($roleId, $now): void {
                $query->select('user_id')
                    ->from('user_role')
                    ->where('role_id', $roleId)
                    ->where(fn ($window) => $window
                        ->whereNull('effective_from')
                        ->orWhere('effective_from', '<=', $now))
                    ->where(fn ($window) => $window
                        ->whereNull('effective_to')
                        ->orWhere('effective_to', '>', $now));
            })
            ->pluck('user_id')
            ->diff($excludeUserIds)
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, User> */
    private function activeUsers(): Collection
    {
        return User::query()->where('status', 'ACTIVE')->get();
    }
}
