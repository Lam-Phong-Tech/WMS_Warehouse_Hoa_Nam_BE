<?php

namespace Modules\Auth\Support;

use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;

/**
 * Domain cache helper cho RBAC (mục 11). Tập trung key/TTL/invalidation của
 * permission/warehouse-scope tại 1 chỗ thay vì để User model và các controller
 * tự biết cấu trúc key — business service (RoleController/UserController) chỉ gọi
 * `AuthCache::bumpVersionForUsersWithRole()` mà không cần biết key thật sự là gì.
 *
 * Pattern invalidation: mọi permission/scope resolution key theo
 * `auth:user:{id}:v:{user.version}:...`. Bump `app_user.version` = tự động vô hiệu
 * hoá 3 cache key của user đó ngay từ request kế tiếp (không chờ TTL 300s).
 */
final class AuthCache
{
    private const TTL_POLICY = 'rbac';

    private function __construct() {}

    public static function rememberSuperAdminPermissions(User $user, \Closure $resolver): array
    {
        return self::service()->remember(self::key($user, 'super-admin-permissions'), CacheTtl::seconds(self::TTL_POLICY), $resolver);
    }

    public static function rememberPermissions(User $user, \Closure $resolver): array
    {
        return self::service()->remember(self::key($user, 'permissions'), CacheTtl::seconds(self::TTL_POLICY), $resolver);
    }

    public static function rememberWarehouseScope(User $user, \Closure $resolver): ?array
    {
        return self::service()->remember(self::key($user, 'warehouse-scopes'), CacheTtl::seconds(self::TTL_POLICY), $resolver);
    }

    /**
     * Bump `version` cho MỘT user cụ thể — vô hiệu hoá permission/scope cache của
     * riêng user đó ngay lập tức (dùng khi user tự thay đổi role/trạng thái).
     */
    public static function bumpVersionForUser(User $user): void
    {
        DB::afterCommit(static function () use ($user): void {
            $user->newQueryWithoutScopes()->whereKey($user->getKey())->increment('version');
        });
    }

    /**
     * Bump `version` cho MỌI user đang giữ 1 role — dùng khi role đó thay đổi theo
     * cách ảnh hưởng đến permission/scope đã resolve (gán/thu hồi permission, đổi
     * status ACTIVE/INACTIVE, đổi scope_type). Chạy sau khi transaction ghi role
     * commit thành công (mục 12: "invalidate sau commit, không phải trước").
     */
    public static function bumpVersionForUsersWithRole(string $roleId): void
    {
        DB::afterCommit(static function () use ($roleId): void {
            User::query()
                ->whereIn('user_id', function ($query) use ($roleId): void {
                    $query->select('user_id')->from('user_role')->where('role_id', $roleId);
                })
                ->increment('version');
        });
    }

    /**
     * Sinh key dạng `wms:{env}:auth:user:{userId}:v:{version}:{suffix}` — namespace
     * đã gồm sẵn id/version nên truyền thẳng vào CacheKey::of() làm namespace, không
     * qua tham số $parts (tránh định dạng "name-value" mặc định của $parts, không
     * khớp quy ước `v:{version}` yêu cầu).
     */
    private static function key(User $user, string $suffix): string
    {
        return CacheKey::of('auth:user:'.$user->user_id.':v:'.(int) $user->version).':'.$suffix;
    }

    private static function service(): CacheService
    {
        return app(CacheService::class);
    }
}
