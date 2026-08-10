<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('app_user') && Schema::hasTable('app_users')) {
            DB::statement(<<<'SQL'
                UPDATE app_users c
                   SET username = COALESCE(c.username, s.username),
                       display_name = COALESCE(c.display_name, s.display_name),
                       email = COALESCE(c.email, s.email),
                       password_hash = COALESCE(c.password_hash, s.password_hash),
                       status = COALESCE(c.status, CASE WHEN s.deleted_at IS NULL THEN s.status ELSE 'INACTIVE' END),
                       created_at = COALESCE(c.created_at, s.created_at),
                       updated_at = COALESCE(c.updated_at, s.updated_at, s.created_at),
                       version = COALESCE(c.version, s.version, 0)
                  FROM app_user s
                 WHERE c.id = s.user_id
            SQL);
        }

        if (Schema::hasTable('role') && Schema::hasTable('roles')) {
            DB::statement(<<<'SQL'
                UPDATE roles c
                   SET role_code = COALESCE(c.role_code, s.role_code),
                       role_name = COALESCE(c.role_name, s.role_name),
                       status = COALESCE(c.status, s.status),
                       created_at = COALESCE(c.created_at, s.created_at),
                       updated_at = COALESCE(c.updated_at, s.updated_at, s.created_at),
                       version = COALESCE(c.version, s.version, 0)
                  FROM role s
                 WHERE c.id = s.role_id
            SQL);
        }

        if (Schema::hasTable('permission') && Schema::hasTable('permissions')) {
            DB::statement(<<<'SQL'
                UPDATE permissions c
                   SET permission_key = COALESCE(c.permission_key, s.permission_key),
                       description = COALESCE(c.description, s.permission_name),
                       status = COALESCE(c.status, s.status),
                       created_at = COALESCE(c.created_at, s.created_at),
                       updated_at = COALESCE(c.updated_at, s.updated_at, s.created_at),
                       version = COALESCE(c.version, 0)
                  FROM permission s
                 WHERE c.id = s.permission_id
            SQL);
        }

        if (Schema::hasTable('user_role') && Schema::hasTable('user_roles')) {
            DB::statement(<<<'SQL'
                INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by)
                SELECT s.user_id, s.role_id, s.created_at, NULL
                  FROM user_role s
                ON CONFLICT (user_id, role_id) DO UPDATE SET assigned_at=EXCLUDED.assigned_at
            SQL);
        }

        if (Schema::hasTable('role_permission') && Schema::hasTable('role_permissions')) {
            DB::statement(<<<'SQL'
                INSERT INTO role_permissions (role_id, permission_id, assigned_at, assigned_by)
                SELECT s.role_id, s.permission_id, s.created_at, NULL
                  FROM role_permission s
                ON CONFLICT (role_id, permission_id) DO UPDATE SET assigned_at=EXCLUDED.assigned_at
            SQL);
        }
    }

    public function down(): void
    {
        // Fail-forward canonical data repair.
    }
};
