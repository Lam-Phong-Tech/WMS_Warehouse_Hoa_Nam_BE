<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('permission') && ! Schema::hasColumn('permission', 'version')) {
            Schema::table('permission', function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(0);
            });
        }

        $this->syncUsers();
        $this->syncRoles();
        $this->syncPermissions();
        $this->syncUserRoles();
        $this->syncRolePermissions();
    }

    public function down(): void
    {
        // Fail-forward IAM parity bridge.
    }

    private function syncUsers(): void
    {
        if (! Schema::hasTable('app_user') || ! Schema::hasTable('app_users')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_app_users()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO app_users (
                    id, username, display_name, email, password_hash, status,
                    last_login_at, created_at, created_by, updated_at, updated_by, version
                )
                SELECT u.user_id, u.username, u.display_name, u.email, u.password_hash,
                       CASE WHEN u.deleted_at IS NULL THEN u.status ELSE 'INACTIVE' END,
                       NULL, u.created_at, NULL, COALESCE(u.updated_at, u.created_at), NULL,
                       COALESCE(u.version, 0)
                  FROM app_user u
                ON CONFLICT (id) DO UPDATE SET
                    username = EXCLUDED.username,
                    display_name = EXCLUDED.display_name,
                    email = EXCLUDED.email,
                    password_hash = EXCLUDED.password_hash,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version;

                UPDATE app_users c
                   SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1
                 WHERE c.status <> 'INACTIVE'
                   AND NOT EXISTS (SELECT 1 FROM app_user u WHERE u.user_id=c.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->recreateStatementTrigger('app_user', 'trg_erd_mirror_app_users_from_app_user', 'hn_erd_mirror_app_users');
    }

    private function syncRoles(): void
    {
        if (! Schema::hasTable('role') || ! Schema::hasTable('roles')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_roles()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO roles (
                    id, role_code, role_name, description, status,
                    created_at, created_by, updated_at, updated_by, version
                )
                SELECT r.role_id, r.role_code, r.role_name, NULL, r.status,
                       r.created_at, NULL, COALESCE(r.updated_at, r.created_at), NULL,
                       COALESCE(r.version, 0)
                  FROM role r
                ON CONFLICT (id) DO UPDATE SET
                    role_code = EXCLUDED.role_code,
                    role_name = EXCLUDED.role_name,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version;

                UPDATE roles c
                   SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1
                 WHERE c.status <> 'INACTIVE'
                   AND NOT EXISTS (SELECT 1 FROM role r WHERE r.role_id=c.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->recreateStatementTrigger('role', 'trg_erd_mirror_roles_from_role', 'hn_erd_mirror_roles');
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permission') || ! Schema::hasTable('permissions')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_permissions()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO permissions (
                    id, permission_key, description, status,
                    created_at, created_by, updated_at, updated_by, version
                )
                SELECT p.permission_id, p.permission_key, p.permission_name, p.status,
                       p.created_at, NULL, COALESCE(p.updated_at, p.created_at), NULL,
                       COALESCE(p.version, 0)
                  FROM permission p
                ON CONFLICT (id) DO UPDATE SET
                    permission_key = EXCLUDED.permission_key,
                    description = EXCLUDED.description,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version;

                UPDATE permissions c
                   SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1
                 WHERE c.status <> 'INACTIVE'
                   AND NOT EXISTS (SELECT 1 FROM permission p WHERE p.permission_id=c.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->recreateStatementTrigger('permission', 'trg_erd_mirror_permissions_from_permission', 'hn_erd_mirror_permissions');
    }

    private function syncUserRoles(): void
    {
        if (! Schema::hasTable('user_role') || ! Schema::hasTable('user_roles')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_user_roles()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by)
                SELECT ur.user_id, ur.role_id, ur.created_at, NULL
                  FROM user_role ur
                ON CONFLICT (user_id, role_id) DO UPDATE SET
                    assigned_at = EXCLUDED.assigned_at;

                DELETE FROM user_roles c
                 WHERE NOT EXISTS (
                    SELECT 1 FROM user_role ur
                     WHERE ur.user_id=c.user_id AND ur.role_id=c.role_id
                 );
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->recreateStatementTrigger('user_role', 'trg_erd_mirror_user_roles_from_user_role', 'hn_erd_mirror_user_roles');
    }

    private function syncRolePermissions(): void
    {
        if (! Schema::hasTable('role_permission') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_role_permissions()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO role_permissions (role_id, permission_id, assigned_at, assigned_by)
                SELECT rp.role_id, rp.permission_id, rp.created_at, NULL
                  FROM role_permission rp
                ON CONFLICT (role_id, permission_id) DO UPDATE SET
                    assigned_at = EXCLUDED.assigned_at;

                DELETE FROM role_permissions c
                 WHERE NOT EXISTS (
                    SELECT 1 FROM role_permission rp
                     WHERE rp.role_id=c.role_id AND rp.permission_id=c.permission_id
                 );
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->recreateStatementTrigger('role_permission', 'trg_erd_mirror_role_permissions_from_role_permission', 'hn_erd_mirror_role_permissions');
    }

    private function recreateStatementTrigger(string $source, string $trigger, string $function): void
    {
        DB::statement('DROP TRIGGER IF EXISTS "'.$trigger.'" ON "'.$source.'"');
        DB::statement('CREATE TRIGGER "'.$trigger.'" AFTER INSERT OR UPDATE OR DELETE ON "'.$source.'" FOR EACH STATEMENT EXECUTE FUNCTION "'.$function.'"()');
    }
};
