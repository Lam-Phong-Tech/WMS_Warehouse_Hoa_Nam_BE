<?php

namespace Modules\Auth\Support;

use Modules\Auth\Models\User;

/**
 * Hồ sơ + quyền của tài khoản trong response xác thực (login, refresh, MFA verify, /auth/me).
 *
 * Tách khỏi AuthController 10/09/2026: MfaController::verify trước đây KHÔNG trả `user`,
 * trong khi FE (auth.service.ts, normalizeLoginResponse) bắt buộc có — đăng nhập hai bước
 * trên web vì thế ném AuthContractError ngay sau khi nhập đúng mã. Test FE mock hẳn
 * `verifyMfa` nên không bắt được. Một nguồn duy nhất cho mọi endpoint phát access token
 * để hình dạng không trôi khỏi nhau lần nữa.
 */
final class AuthUserPayload
{
    /** @return array<string, mixed> */
    public static function full(User $user): array
    {
        return [
            ...self::profile($user),
            ...self::authorization($user),
        ];
    }

    /** @return array<string, mixed> */
    public static function profile(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'version' => (int) $user->version,
            'department' => $user->department === null ? null : [
                'department_id' => $user->department->department_id, 'department_name' => $user->department->department_name,
            ],
            'business_profile' => UserBusinessProfile::read((string) $user->user_id),
            'avatar' => ProfileAvatar::metadata((string) $user->user_id),
        ];
    }

    /** @return array<string, mixed> */
    public static function authorization(User $user): array
    {
        return [
            'roles' => $user->activeRoles()->orderBy('role_code')
                ->get(['role.role_id', 'role.role_code', 'role.role_name'])
                ->map(fn ($role) => [
                    'role_id' => $role->role_id,
                    'role_code' => $role->role_code,
                    'role_name' => $role->role_name,
                    'scope_ref' => $role->pivot->scope_ref,
                ])->values()->all(),
            'permissions' => $user->permissionCodes(),
            'warehouse_scope_ids' => $user->warehouseScopeIds(),
            'account_session_policy' => AccountSessionPolicy::contract(),
            ...app(StableAuthSession::class)->payload($user),
        ];
    }
}
