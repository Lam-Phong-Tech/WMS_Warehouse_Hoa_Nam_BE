<?php

namespace Modules\Auth\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Model implements AuthenticatableContract, JWTSubject
{
    use Authenticatable, HasUuids, SoftDeletes;

    protected $table = 'app_user';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'username', 'display_name', 'email', 'phone', 'password_hash', 'status', 'version',
    ];

    protected $hidden = ['password_hash'];

    /**
     * Active roles are immutable for the lifetime of one HTTP request. Keeping
     * the collection in memory prevents /auth/me, permission and warehouse-scope
     * resolution from executing the same user_role/role query repeatedly.
     *
     * This is deliberately request-local rather than a cross-request cache, so
     * RBAC changes remain visible on the next request without invalidation risk.
     */
    private ?EloquentCollection $activeRolesMemo = null;

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id', 'user_id', 'role_id')
            ->withPivot(['scope_ref', 'effective_from', 'effective_to']);
    }

    public function activeRoles(): BelongsToMany
    {
        return $this->roles()
            ->where('role.status', 'ACTIVE')
            ->where(function ($query) {
                $query->whereNull('user_role.effective_from')
                    ->orWhere('user_role.effective_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('user_role.effective_to')
                    ->orWhere('user_role.effective_to', '>', now());
            });
    }

    /**
     * @return EloquentCollection<int, Role>
     */
    public function activeRoleModels(): EloquentCollection
    {
        if ($this->activeRolesMemo !== null) {
            return $this->activeRolesMemo;
        }

        $this->activeRolesMemo = $this->activeRoles()
            ->orderBy('role_code')
            ->get();

        return $this->activeRolesMemo;
    }

    public function isSuperAdmin(): bool
    {
        return $this->activeRoleModels()->contains(
            static fn (Role $role): bool => $role->role_code === 'SUPER_ADMIN'
        );
    }

    /** @return array<int, string> */
    public function permissionCodes(): array
    {
        if ($this->isSuperAdmin()) {
            return Cache::remember("auth:user:{$this->user_id}:v{$this->version}:super-admin-permissions", 300, function () {
                return Permission::query()
                    ->where('status', 'ACTIVE')
                    ->orderByRaw("CASE WHEN permission_key = 'audit_log.view' THEN 0 ELSE 1 END")
                    ->orderBy('permission_key')
                    ->pluck('permission_key')
                    ->values()
                    ->all();
            });
        }

        return Cache::remember("auth:user:{$this->user_id}:v{$this->version}:permissions", 300, function () {
            $roles = $this->activeRoleModels();
            $roles->loadMissing([
                'permissions' => fn ($query) => $query->where('permission.status', 'ACTIVE'),
            ]);

            return $roles
                ->pluck('permissions')
                ->flatten()
                ->pluck('permission_key')
                ->unique()
                ->sortBy(fn (string $key): string => ($key === 'audit_log.view' ? '0:' : '1:').$key)
                ->values()
                ->all();
        });
    }

    public function hasPermission(string $permissionKey): bool
    {
        return $this->isSuperAdmin() || in_array($permissionKey, $this->permissionCodes(), true);
    }

    /**
     * null = không giới hạn kho (SUPER_ADMIN hoặc role SYSTEM có scope_ref rỗng).
     * [] = không có scope kho hợp lệ. Danh sách UUID = chỉ được truy cập các kho đó.
     *
     * @return array<int, string>|null
     */
    public function warehouseScopeIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return Cache::remember("auth:user:{$this->user_id}:v{$this->version}:warehouse-scopes", 300, function () {
            $roles = $this->activeRoleModels();

            if ($roles->contains(fn (Role $role) => $role->pivot->scope_ref === null || $role->pivot->scope_ref === '')) {
                return null;
            }

            return $roles->pluck('pivot.scope_ref')
                ->filter(fn ($scope) => is_string($scope) && $scope !== '')
                ->unique()
                ->values()
                ->all();
        });
    }

    public function canAccessWarehouse(string $warehouseId): bool
    {
        $scopes = $this->warehouseScopeIds();

        return $scopes === null || in_array($warehouseId, $scopes, true);
    }

    public function getJWTIdentifier(): string
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
