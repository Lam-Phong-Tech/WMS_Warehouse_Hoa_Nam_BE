<?php

namespace Modules\Auth\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Auth\Support\AuthCache;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Model implements AuthenticatableContract, JWTSubject
{
    use Authenticatable, HasUuids, SoftDeletes;

    protected $table = 'app_user';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'username', 'display_name', 'email', 'phone', 'department_id', 'password_hash', 'status', 'version',
        'must_change_password', 'first_login_deadline_at', 'last_login_at',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'must_change_password' => 'boolean',
        'first_login_deadline_at' => 'datetime',
        'last_login_at' => 'datetime',
        // WWHN-SEC-02: password_changed_at CỐ Ý KHÔNG khai cast 'datetime' — xem
        // markPasswordChangedNow()/passwordChangedAtEpoch() bên dưới.
    ];

    private ?EloquentCollection $activeRolesMemo = null;

    private ?bool $isSuperAdminMemo = null;

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * WWHN-SEC-02 — ghi mốc đổi mật khẩu, dùng để vô hiệu hoá access token cũ.
     *
     * PHẢI dùng hai hàm này thay cho việc đọc/ghi thẳng thuộc tính, và cột CỐ Ý
     * không khai cast 'datetime'. Lý do là một cái bẫy đã đo được trên chính hệ
     * thống này:
     *
     *   `now()` trả Carbon ở Asia/Ho_Chi_Minh (+07). Laravel bind giá trị datetime
     *   xuống DB bằng format 'Y-m-d H:i:s' — KHÔNG kèm offset. PostgreSQL nhận
     *   chuỗi "trần" đó và hiểu theo TimeZone của session (UTC), nên thời điểm
     *   16:29:35+07 bị lưu thành 16:29:35+00, tức lệch +7 GIỜ so với thực tế.
     *   Đo được: mốc vừa ghi xong đứng trước "hiện tại" 25 170 giây.
     *
     * Hệ quả nếu để nguyên: `password_changed_at` nằm ở TƯƠNG LAI nên
     * EnforceCredentialFreshness từ chối cả token vừa cấp — người dùng đổi mật
     * khẩu xong không đăng nhập lại được suốt 7 giờ.
     *
     * Cách xử lý ở đây là chuẩn hoá cả hai đầu về UTC "trần", không phụ thuộc
     * driver: ghi bằng ->utc() nên chuỗi bind là UTC; đọc thì parse thẳng chuỗi
     * thô với timezone UTC. Đúng trên cả PostgreSQL (chạy thật) lẫn sqlite (test).
     *
     * PostgreSQL bindings now preserve offsets globally (PostgresTimestampGrammar).
     * Keep these explicit UTC security helpers for SQLite and historical values;
     * changing the writer does not reinterpret previously stored timestamps.
     */
    public function markPasswordChangedNow(): void
    {
        $this->forceFill(['password_changed_at' => now()->utc()])->save();
    }

    /** Mốc đổi mật khẩu quy về epoch giây, hoặc null nếu chưa từng đổi. */
    public function passwordChangedAtEpoch(): ?int
    {
        $raw = $this->getAttributeValue('password_changed_at');

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->getTimestamp();
        }

        return Carbon::parse((string) $raw, 'UTC')->getTimestamp();
    }

    /** GĐ1-REQ-011: user thuộc một phòng ban (nullable). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id', 'user_id', 'role_id')
            ->withPivot(['scope_ref', 'effective_from', 'effective_to']);
    }

    /** Quyền cấp riêng cho tài khoản, cộng thêm vào quyền theo role (chỉ cấp thêm, không thu hồi quyền của role). */
    public function grantedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission', 'user_id', 'permission_id', 'user_id', 'permission_id')
            ->withPivot(['user_permission_id', 'granted_by', 'granted_at'])
            ->withTimestamps();
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

    /** @return EloquentCollection<int, Role> */
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
        // Nhớ trong instance để một request không hỏi cache/DB nhiều lần —
        // hasPermission(), permissionCodes() và warehouseScopeIds() đều gọi hàm
        // này. Lần đầu resolve qua AuthCache (Valkey, khoá theo user.version).
        if ($this->isSuperAdminMemo !== null) {
            return $this->isSuperAdminMemo;
        }

        return $this->isSuperAdminMemo = AuthCache::rememberIsSuperAdmin($this, function (): bool {
            return $this->activeRoleModels()->contains(
                static fn (Role $role): bool => $role->role_code === 'SUPER_ADMIN'
            );
        });
    }

    /** @return array<int, string> */
    public function permissionCodes(): array
    {
        if ($this->isSuperAdmin()) {
            return AuthCache::rememberSuperAdminPermissions($this, function () {
                return Permission::query()
                    ->where('status', 'ACTIVE')
                    ->orderByRaw("CASE WHEN permission_key = 'audit_log.view' THEN 0 ELSE 1 END")
                    ->orderBy('permission_key')
                    ->pluck('permission_key')
                    ->values()
                    ->all();
            });
        }

        return AuthCache::rememberPermissions($this, function () {
            $roles = $this->activeRoleModels();
            $roles->loadMissing([
                'permissions' => fn ($query) => $query->where('permission.status', 'ACTIVE'),
            ]);
            $this->loadMissing([
                'grantedPermissions' => fn ($query) => $query->where('permission.status', 'ACTIVE'),
            ]);

            return $roles
                ->pluck('permissions')
                ->flatten()
                ->merge($this->grantedPermissions)
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

    /** @return array<int, string>|null */
    public function warehouseScopeIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return AuthCache::rememberWarehouseScope($this, function () {
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
