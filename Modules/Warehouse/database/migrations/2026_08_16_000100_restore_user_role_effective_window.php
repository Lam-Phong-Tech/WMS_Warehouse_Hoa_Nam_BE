<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-219 A1 — restore the RBAC assignment window on the canonical IAM schema.
 *
 * `2026_08_10_000790_retire_legacy_runtime_tables.php` replaced the legacy
 * `user_role` table with a view over canonical `user_roles`. The canonical table
 * never carried `scope_ref` / `effective_from` / `effective_to`, so the view
 * fabricated them as NULL literals and the INSTEAD OF trigger wrote only
 * (user_id, role_id, assigned_at, assigned_by).
 *
 * `User::activeRoles()` filters on that window with `IS NULL OR ...`, so a
 * permanently NULL window made every assignment permanently effective: expired
 * and not-yet-valid role grants were honoured, and revoking access by setting
 * `effective_to` did nothing. Measured before this migration — expired: HTTP 200,
 * future: HTTP 200, both expected 403.
 *
 * Disposition: Option A — keep the feature, restore the columns, amend the ERD
 * Data Dictionary (docs/HoaNam_ERD_Moi_Nhat.md §4 user_roles) to match.
 *
 * The primary key stays (user_id, role_id): one assignment row per user+role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // On a fresh migrate these columns are already materialised from the ERD
        // document by 2026_08_10_000750; this is the upgrade path for existing
        // databases and a no-op safety net otherwise.
        DB::statement('ALTER TABLE user_roles ADD COLUMN IF NOT EXISTS scope_ref varchar(100)');
        DB::statement('ALTER TABLE user_roles ADD COLUMN IF NOT EXISTS effective_from timestamptz');
        DB::statement('ALTER TABLE user_roles ADD COLUMN IF NOT EXISTS effective_to timestamptz');

        $this->recreateCompatibilityView();
        $this->recreateWriteTrigger();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Reverting reinstates the NULL-literal projection; the stored window
        // values become unreadable through the compatibility view but are not
        // destroyed, so a re-run of up() restores visibility.
        DB::statement('DROP VIEW IF EXISTS user_role CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW user_role AS
            SELECT ((substr(md5('user_role:' || user_id::text || ':' || role_id::text), 1, 8) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 9, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 13, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 17, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 21, 12))::uuid) AS user_role_id,
                   user_id, role_id, NULL::varchar(100) AS scope_ref,
                   NULL::timestamptz AS effective_from, NULL::timestamptz AS effective_to,
                   assigned_at AS created_at, assigned_at AS updated_at
              FROM user_roles
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_user_role_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM user_roles WHERE user_id=OLD.user_id AND role_id=OLD.role_id; RETURN OLD; END IF;
                INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by)
                VALUES (NEW.user_id, NEW.role_id, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), NULL)
                ON CONFLICT (user_id, role_id) DO UPDATE SET assigned_at=EXCLUDED.assigned_at;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER "trg_hn_legacy_user_role_write" INSTEAD OF INSERT OR UPDATE OR DELETE ON "user_role" FOR EACH ROW EXECUTE FUNCTION "hn_legacy_user_role_write"()');
    }

    /** Project the real window columns instead of NULL literals. */
    private function recreateCompatibilityView(): void
    {
        // CASCADE also removes the INSTEAD OF trigger, which is recreated below.
        DB::statement('DROP VIEW IF EXISTS user_role CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW user_role AS
            SELECT ((substr(md5('user_role:' || user_id::text || ':' || role_id::text), 1, 8) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 9, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 13, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 17, 4) || '-' ||
                     substr(md5('user_role:' || user_id::text || ':' || role_id::text), 21, 12))::uuid) AS user_role_id,
                   user_id, role_id, scope_ref, effective_from, effective_to,
                   assigned_at AS created_at, assigned_at AS updated_at
              FROM user_roles
        SQL);
    }

    /**
     * Persist the window on both INSERT and the ON CONFLICT branch. Omitting the
     * conflict branch would silently drop the window whenever an assignment is
     * re-attached or updated, which is the defect this migration closes.
     */
    private function recreateWriteTrigger(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_user_role_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM user_roles WHERE user_id=OLD.user_id AND role_id=OLD.role_id; RETURN OLD; END IF;
                INSERT INTO user_roles (user_id, role_id, scope_ref, effective_from, effective_to, assigned_at, assigned_by)
                VALUES (NEW.user_id, NEW.role_id, NEW.scope_ref, NEW.effective_from, NEW.effective_to,
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), NULL)
                ON CONFLICT (user_id, role_id) DO UPDATE SET
                    scope_ref=EXCLUDED.scope_ref,
                    effective_from=EXCLUDED.effective_from,
                    effective_to=EXCLUDED.effective_to,
                    assigned_at=EXCLUDED.assigned_at;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER "trg_hn_legacy_user_role_write" INSTEAD OF INSERT OR UPDATE OR DELETE ON "user_role" FOR EACH ROW EXECUTE FUNCTION "hn_legacy_user_role_write"()');
    }
};
