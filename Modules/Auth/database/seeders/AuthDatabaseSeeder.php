<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;

class AuthDatabaseSeeder extends Seeder
{
    /**
     * Seed tối thiểu để smoke test base: 1 role SUPER_ADMIN (bypass mọi permission) + 1 tài
     * khoản admin. Không phải seed đầy đủ theo BRD — chỉ đủ để chứng minh Auth chạy được.
     */
    public function run(): void
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Quản trị hệ thống', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );

        $user = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'display_name' => 'Administrator',
                'email' => 'admin@wms-hoanam.local',
                'password_hash' => Hash::make('ChangeMe123!'),
                'status' => 'ACTIVE',
                'version' => 1,
            ]
        );

        if (! $user->roles()->where('role.role_id', $role->role_id)->exists()) {
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }
    }
}
