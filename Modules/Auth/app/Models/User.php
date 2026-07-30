<?php

namespace Modules\Auth\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Models\Permission;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Model implements AuthenticatableContract, JWTSubject
{
    use Authenticatable, HasUuids, SoftDeletes;

    protected $table = 'app_user';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'username',
        'display_name',
        'email',
        'phone',
        'password_hash',
        'status',
        'version',
    ];

    protected $hidden = [
        'password_hash',
    ];

    /** Laravel's Auth facade/hashing expects `password` — map to password_hash column. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id', 'user_id', 'role_id')
            ->withPivot(['scope_ref', 'effective_from', 'effective_to']);
    }

    /** SUPER_ADMIN bypass mọi permission check — port từ quy ước isSuperAdmin() của ERP-Backend. */
    public function isSuperAdmin(): bool
    {
        return $this->roles()->where('role_code', 'SUPER_ADMIN')->exists();
    }

    /**
     * Danh sách permission_key user đang có qua các role đang gán (cache 5 phút theo user).
     *
     * @return array<int, string>
     */
    public function permissionCodes(): array
    {
        if ($this->isSuperAdmin()) {
            return Cache::remember("auth:user:{$this->user_id}:super-admin-permissions", 300, function () {
                return Permission::query()
                    ->where('status', 'ACTIVE')
                    ->orderBy('permission_key')
                    ->pluck('permission_key')
                    ->values()
                    ->all();
            });
        }

        return Cache::remember("auth:user:{$this->user_id}:permissions", 300, function () {
            return $this->roles()
                ->where('role.status', 'ACTIVE')
                ->with(['permissions' => fn ($q) => $q->where('permission.status', 'ACTIVE')])
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->pluck('permission_key')
                ->unique()
                ->values()
                ->all();
        });
    }

    public function hasPermission(string $permissionKey): bool
    {
        return $this->isSuperAdmin() || in_array($permissionKey, $this->permissionCodes(), true);
    }

    // --- JWTSubject ---

    public function getJWTIdentifier(): string
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
