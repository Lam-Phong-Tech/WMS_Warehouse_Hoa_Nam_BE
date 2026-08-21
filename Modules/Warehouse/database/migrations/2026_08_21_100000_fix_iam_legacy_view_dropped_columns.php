<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 2026_08_10_000790_retire_legacy_runtime_tables rebuilt the IAM
     * compatibility views (app_user, role, user_role) + their INSTEAD OF
     * triggers over the canonical app_users/roles/user_roles tables, but
     * silently dropped several columns that "Danh sách người dùng & phân
     * quyền" (IAM) actually writes through the UI:
     *
     *  - app_user.phone: view hardcoded NULL::varchar(30) AS phone instead of
     *    reading app_users.phone; hn_legacy_app_user_write() never listed
     *    phone in its INSERT/UPDATE — even though app_users physically has
     *    the column. Every create/edit user silently lost the phone number.
     *
     *  - role.scope_type: the canonical `roles` table never got a
     *    scope_type column at all when it was split off from the original
     *    `role` table (which does have one, see
     *    2026_01_01_000021_create_role_table). The compat view hardcoded
     *    'FUNCTION'::varchar(30) as a constant, so every role created with
     *    scope_type SYSTEM/WAREHOUSE via IamRolePermissionScreen silently
     *    became FUNCTION.
     *
     *  - user_role.scope_ref / effective_from / effective_to: the canonical
     *    `user_roles` table has these columns (BR-IAM-08 "scope assignment
     *    explicit"), but the view hardcoded them NULL and
     *    hn_legacy_user_role_write() never wrote them. Every role
     *    assignment made through IamUserScreen's "Phạm vi" (warehouse
     *    scope) + hiệu lực window silently became an unscoped, permanent
     *    assignment — a security-relevant data loss, not just a display bug.
     *
     * StoreUserRequest/UpdateUserRequest/StoreRoleRequest, the Eloquent
     * $fillable lists, and UserController::assignRoles() were all already
     * correct; the loss happened entirely inside this view/trigger layer,
     * which only exists on PostgreSQL (sqlite tests run against the
     * original physical `role`/`user_role`/`app_user` tables directly, so
     * this class of bug never surfaced in the test suite).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->fixAppUserPhone();
        $this->fixRoleScopeType();
        $this->fixUserRoleScopeAndWindow();
    }

    public function down(): void
    {
        // Fail-forward, matching 2026_08_10_000790's convention.
    }

    private function fixAppUserPhone(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW app_user AS
            SELECT id AS user_id, username, display_name, email, phone,
                   password_hash, status, created_at, updated_at, NULL::timestamptz AS deleted_at, version
              FROM app_users
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_app_user_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE app_users SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1 WHERE id=OLD.user_id;
                    RETURN OLD;
                END IF;
                INSERT INTO app_users (id, username, display_name, email, phone, password_hash, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.user_id, gen_random_uuid()), NEW.username, NEW.display_name, NEW.email, NEW.phone, NEW.password_hash,
                        CASE WHEN NEW.deleted_at IS NULL THEN COALESCE(NEW.status, 'ACTIVE') ELSE 'INACTIVE' END,
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET
                    username=EXCLUDED.username, display_name=EXCLUDED.display_name, email=EXCLUDED.email,
                    phone=EXCLUDED.phone, password_hash=EXCLUDED.password_hash, status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function fixRoleScopeType(): void
    {
        if (! Schema::hasColumn('roles', 'scope_type')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('scope_type', 30)->nullable()->after('role_name');
            });
        }

        // Every row so far only ever had 'FUNCTION' visible through the compat
        // view (the hardcoded constant this migration removes) — backfill so
        // existing roles keep reading the same effective scope_type they did
        // before this fix, instead of turning NULL.
        DB::table('roles')->whereNull('scope_type')->update(['scope_type' => 'FUNCTION']);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW "role" AS
            SELECT id AS role_id, role_code, role_name, COALESCE(scope_type, 'FUNCTION')::varchar(30) AS scope_type,
                   status, created_at, updated_at, version
              FROM roles
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_role_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM roles WHERE id=OLD.role_id; RETURN OLD; END IF;
                INSERT INTO roles (id, role_code, role_name, scope_type, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.role_id, gen_random_uuid()), NEW.role_code, NEW.role_name, COALESCE(NEW.scope_type, 'FUNCTION'), COALESCE(NEW.status, 'ACTIVE'),
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET role_code=EXCLUDED.role_code, role_name=EXCLUDED.role_name,
                    scope_type=EXCLUDED.scope_type, status=EXCLUDED.status, updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function fixUserRoleScopeAndWindow(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW user_role AS
            SELECT ((substr(md5('user_role:' || user_id::text || ':' || role_id::text), 1, 8) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 9, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 13, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 17, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 21, 12))::uuid) AS user_role_id,
                   user_id, role_id, scope_ref,
                   effective_from, effective_to,
                   assigned_at AS created_at, assigned_at AS updated_at
              FROM user_roles
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_user_role_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM user_roles WHERE user_id=OLD.user_id AND role_id=OLD.role_id; RETURN OLD; END IF;
                INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by, scope_ref, effective_from, effective_to)
                VALUES (NEW.user_id, NEW.role_id, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), NULL, NEW.scope_ref, NEW.effective_from, NEW.effective_to)
                ON CONFLICT (user_id, role_id) DO UPDATE SET
                    assigned_at=EXCLUDED.assigned_at, scope_ref=EXCLUDED.scope_ref,
                    effective_from=EXCLUDED.effective_from, effective_to=EXCLUDED.effective_to;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }
};
