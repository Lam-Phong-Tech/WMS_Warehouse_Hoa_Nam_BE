<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->copyLegacyRowsIntoCanonicalTables();
        $this->dropLegacyPhysicalTables();
        $this->createLegacyCompatibilityViews();
    }

    public function down(): void
    {
        // Fail-forward: the canonical ERD tables are now the only physical runtime tables.
    }

    private function copyLegacyRowsIntoCanonicalTables(): void
    {
        $statements = [
            <<<'SQL'
                INSERT INTO app_users (
                    id, username, display_name, email, password_hash, status,
                    last_login_at, created_at, created_by, updated_at, updated_by, version
                )
                SELECT user_id, username, display_name, email, password_hash,
                       CASE WHEN deleted_at IS NULL THEN status ELSE 'INACTIVE' END,
                       NULL, created_at, NULL, COALESCE(updated_at, created_at, CURRENT_TIMESTAMP), NULL,
                       COALESCE(version, 0)
                  FROM app_user
                ON CONFLICT (id) DO UPDATE SET
                    username = EXCLUDED.username,
                    display_name = EXCLUDED.display_name,
                    email = EXCLUDED.email,
                    password_hash = EXCLUDED.password_hash,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version
            SQL,
            <<<'SQL'
                INSERT INTO roles (
                    id, role_code, role_name, description, status,
                    created_at, created_by, updated_at, updated_by, version
                )
                SELECT role_id, role_code, role_name, NULL, status,
                       created_at, NULL, COALESCE(updated_at, created_at, CURRENT_TIMESTAMP), NULL,
                       COALESCE(version, 0)
                  FROM "role"
                ON CONFLICT (id) DO UPDATE SET
                    role_code = EXCLUDED.role_code,
                    role_name = EXCLUDED.role_name,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version
            SQL,
            <<<'SQL'
                INSERT INTO permissions (
                    id, permission_key, description, status,
                    created_at, created_by, updated_at, updated_by, version
                )
                SELECT permission_id, permission_key, permission_name, status,
                       created_at, NULL, COALESCE(updated_at, created_at, CURRENT_TIMESTAMP), NULL,
                       COALESCE(version, 0)
                  FROM permission
                ON CONFLICT (id) DO UPDATE SET
                    permission_key = EXCLUDED.permission_key,
                    description = EXCLUDED.description,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version
            SQL,
            <<<'SQL'
                INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by)
                SELECT user_id, role_id, COALESCE(created_at, CURRENT_TIMESTAMP), NULL
                  FROM user_role
                ON CONFLICT (user_id, role_id) DO UPDATE SET
                    assigned_at = EXCLUDED.assigned_at
            SQL,
            <<<'SQL'
                INSERT INTO role_permissions (role_id, permission_id, assigned_at, assigned_by)
                SELECT role_id, permission_id, COALESCE(created_at, CURRENT_TIMESTAMP), NULL
                  FROM role_permission
                ON CONFLICT (role_id, permission_id) DO UPDATE SET
                    assigned_at = EXCLUDED.assigned_at
            SQL,
            <<<'SQL'
                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    specifications, status, created_at, created_by, updated_at, updated_by, version
                )
                SELECT sku_id, sku_code, sku_name, sku_type,
                       CASE WHEN is_serial_tracked THEN 'SERIAL' ELSE 'QUANTITY' END,
                       unit, brand_id, category_id, model_id, power_source_id, package_variant_id,
                       specification::jsonb, status, created_at, NULL,
                       COALESCE(updated_at, created_at, CURRENT_TIMESTAMP), NULL, COALESCE(version, 1)
                  FROM sku
                ON CONFLICT (id) DO UPDATE SET
                    sku_code = EXCLUDED.sku_code,
                    sku_name = EXCLUDED.sku_name,
                    sku_type = EXCLUDED.sku_type,
                    tracking_mode = EXCLUDED.tracking_mode,
                    unit_code = EXCLUDED.unit_code,
                    brand_id = EXCLUDED.brand_id,
                    category_id = EXCLUDED.category_id,
                    model_id = EXCLUDED.model_id,
                    power_source_id = EXCLUDED.power_source_id,
                    package_variant_id = EXCLUDED.package_variant_id,
                    specifications = EXCLUDED.specifications,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version
            SQL,
            <<<'SQL'
                INSERT INTO sku_aliases (
                    id, sku_id, source_system, alias_code, status,
                    created_at, created_by, updated_at, updated_by, version
                )
                SELECT alias_id, product_id, COALESCE(alias_type, 'LEGACY'), alias_code, 'ACTIVE',
                       created_at, NULL, COALESCE(updated_at, created_at, CURRENT_TIMESTAMP), NULL, 0
                  FROM sku_alias
                ON CONFLICT (id) DO UPDATE SET
                    sku_id = EXCLUDED.sku_id,
                    source_system = EXCLUDED.source_system,
                    alias_code = EXCLUDED.alias_code,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at
            SQL,
            <<<'SQL'
                INSERT INTO audit_logs (
                    request_id, actor_id, action, entity_type, entity_id, result,
                    before_data, after_data, metadata, created_at
                )
                SELECT request_id, actor_id, action, object_type,
                       CASE WHEN object_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                            THEN object_id::uuid ELSE NULL END,
                       'SUCCESS', before_data::jsonb, after_data::jsonb,
                       jsonb_strip_nulls(jsonb_build_object(
                           'legacy_id', id::text,
                           'legacy_object_id', object_id,
                           'legacy_reason', reason
                       )),
                       created_at
                  FROM audit_log l
                 WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.metadata->>'legacy_id' = l.id::text)
            SQL,
        ];

        foreach ($statements as $statement) {
            $this->statementIfSourcesExist($statement);
        }
    }

    private function dropLegacyPhysicalTables(): void
    {
        $tables = [
            'products',
            'audit_log',
            'sku_alias',
            'sku',
            'role_permission',
            'user_role',
            'permission',
            'role',
            'app_user',
        ];

        foreach ($tables as $table) {
            $kind = HoaNamErdSchema::relationKind($table);
            if ($kind === 'v') {
                DB::statement('DROP VIEW '.HoaNamErdSchema::quote($table).' CASCADE');
            } elseif ($kind === 'r' || $kind === 'p') {
                DB::statement('DROP TABLE '.HoaNamErdSchema::quote($table).' CASCADE');
            }
        }
    }

    private function createLegacyCompatibilityViews(): void
    {
        $this->createIamViews();
        $this->createSkuViews();
        $this->createAuditView();
    }

    private function createIamViews(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW app_user AS
            SELECT id AS user_id, username, display_name, email, NULL::varchar(30) AS phone,
                   password_hash, status, created_at, updated_at, NULL::timestamptz AS deleted_at, version
              FROM app_users
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW "role" AS
            SELECT id AS role_id, role_code, role_name, 'FUNCTION'::varchar(30) AS scope_type,
                   status, created_at, updated_at, version
              FROM roles
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW permission AS
            SELECT id AS permission_id, permission_key, description AS permission_name,
                   'FUNCTION'::varchar(30) AS scope_type, status, created_at, updated_at, version
              FROM permissions
        SQL);
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
            CREATE VIEW role_permission AS
            SELECT ((substr(md5('role_permission:' || role_id::text || ':' || permission_id::text), 1, 8) || '-' ||
                     substr(md5('role_permission:' || role_id::text || ':' || permission_id::text), 9, 4) || '-' ||
                     substr(md5('role_permission:' || role_id::text || ':' || permission_id::text), 13, 4) || '-' ||
                     substr(md5('role_permission:' || role_id::text || ':' || permission_id::text), 17, 4) || '-' ||
                     substr(md5('role_permission:' || role_id::text || ':' || permission_id::text), 21, 12))::uuid) AS role_permission_id,
                   role_id, permission_id, assigned_at AS created_at, assigned_at AS updated_at
              FROM role_permissions
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_app_user_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE app_users SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1 WHERE id=OLD.user_id;
                    RETURN OLD;
                END IF;
                INSERT INTO app_users (id, username, display_name, email, password_hash, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.user_id, gen_random_uuid()), NEW.username, NEW.display_name, NEW.email, NEW.password_hash,
                        CASE WHEN NEW.deleted_at IS NULL THEN COALESCE(NEW.status, 'ACTIVE') ELSE 'INACTIVE' END,
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET
                    username=EXCLUDED.username, display_name=EXCLUDED.display_name, email=EXCLUDED.email,
                    password_hash=EXCLUDED.password_hash, status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('app_user', 'hn_legacy_app_user_write');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_role_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM roles WHERE id=OLD.role_id; RETURN OLD; END IF;
                INSERT INTO roles (id, role_code, role_name, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.role_id, gen_random_uuid()), NEW.role_code, NEW.role_name, COALESCE(NEW.status, 'ACTIVE'),
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET role_code=EXCLUDED.role_code, role_name=EXCLUDED.role_name,
                    status=EXCLUDED.status, updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('role', 'hn_legacy_role_write');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_permission_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM permissions WHERE id=OLD.permission_id; RETURN OLD; END IF;
                INSERT INTO permissions (id, permission_key, description, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.permission_id, gen_random_uuid()), NEW.permission_key, NEW.permission_name, COALESCE(NEW.status, 'ACTIVE'),
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET permission_key=EXCLUDED.permission_key, description=EXCLUDED.description,
                    status=EXCLUDED.status, updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('permission', 'hn_legacy_permission_write');

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
        $this->insteadOf('user_role', 'hn_legacy_user_role_write');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_role_permission_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM role_permissions WHERE role_id=OLD.role_id AND permission_id=OLD.permission_id; RETURN OLD; END IF;
                INSERT INTO role_permissions (role_id, permission_id, assigned_at, assigned_by)
                VALUES (NEW.role_id, NEW.permission_id, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), NULL)
                ON CONFLICT (role_id, permission_id) DO UPDATE SET assigned_at=EXCLUDED.assigned_at;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('role_permission', 'hn_legacy_role_permission_write');
    }

    private function createSkuViews(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW sku AS
            SELECT id AS sku_id, sku_code, sku_name, sku_type, unit_code AS unit,
                   tracking_mode = 'SERIAL' AS is_serial_tracked,
                   brand_id, category_id, model_id, power_source_id, package_variant_id,
                   NULL::varchar(50) AS power_or_voltage, NULL::varchar(50) AS package_variant,
                   'V1'::varchar(30) AS version_code, false AS warranty_applicable,
                   specifications AS specification, false AS published_to_app,
                   status, created_at, updated_at, version
              FROM skus
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW products AS
            SELECT id, sku_code AS code, sku_name AS name, sku_type, unit_code AS unit,
                   tracking_mode = 'SERIAL' AS is_serial_tracked,
                   brand_id, category_id, model_id,
                   NULL::varchar(50) AS power_or_voltage, NULL::varchar(50) AS package_variant,
                   'V1'::varchar(30) AS version_code, false AS warranty_applicable,
                   specifications AS specification, false AS published_to_app,
                   status, created_at, updated_at
              FROM skus
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW sku_alias AS
            SELECT id AS alias_id, sku_id AS product_id, alias_code, source_system AS alias_type,
                   created_at, updated_at
              FROM sku_aliases
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_sku_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM skus WHERE id=OLD.sku_id; RETURN OLD; END IF;
                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    specifications, status, created_at, updated_at, version
                )
                VALUES (
                    COALESCE(NEW.sku_id, gen_random_uuid()), NEW.sku_code, NEW.sku_name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id, NEW.specification::jsonb,
                    COALESCE(NEW.status, 'ACTIVE'), COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 1)
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_code=EXCLUDED.sku_code, sku_name=EXCLUDED.sku_name, sku_type=EXCLUDED.sku_type,
                    tracking_mode=EXCLUDED.tracking_mode, unit_code=EXCLUDED.unit_code,
                    brand_id=EXCLUDED.brand_id, category_id=EXCLUDED.category_id, model_id=EXCLUDED.model_id,
                    power_source_id=EXCLUDED.power_source_id, package_variant_id=EXCLUDED.package_variant_id,
                    specifications=EXCLUDED.specifications, status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('sku', 'hn_legacy_sku_write');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_sku_alias_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM sku_aliases WHERE id=OLD.alias_id; RETURN OLD; END IF;
                INSERT INTO sku_aliases (id, sku_id, source_system, alias_code, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.alias_id, gen_random_uuid()), NEW.product_id, COALESCE(NEW.alias_type, 'LEGACY'),
                        NEW.alias_code, 'ACTIVE', COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                        COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), 0)
                ON CONFLICT (id) DO UPDATE SET sku_id=EXCLUDED.sku_id, source_system=EXCLUDED.source_system,
                    alias_code=EXCLUDED.alias_code, updated_at=EXCLUDED.updated_at;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('sku_alias', 'hn_legacy_sku_alias_write');
    }

    private function createAuditView(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW audit_log AS
            SELECT COALESCE(
                       NULLIF(metadata->>'legacy_id', '')::uuid,
                       ((substr(md5('audit_log:' || id::text), 1, 8) || '-' ||
                         substr(md5('audit_log:' || id::text), 9, 4) || '-' ||
                         substr(md5('audit_log:' || id::text), 13, 4) || '-' ||
                         substr(md5('audit_log:' || id::text), 17, 4) || '-' ||
                         substr(md5('audit_log:' || id::text), 21, 12))::uuid)
                   ) AS id,
                   actor_id, action, entity_type AS object_type,
                   COALESCE(metadata->>'legacy_object_id', entity_id::text) AS object_id,
                   metadata->>'legacy_reason' AS reason, request_id,
                   before_data, after_data, created_at
              FROM audit_logs
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_audit_log_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'audit_log compatibility view is append-only';
                END IF;
                INSERT INTO audit_logs (
                    request_id, actor_id, action, entity_type, entity_id, result,
                    before_data, after_data, metadata, created_at
                )
                VALUES (
                    NEW.request_id, NEW.actor_id, NEW.action, COALESCE(NEW.object_type, 'LEGACY'),
                    CASE WHEN NEW.object_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                         THEN NEW.object_id::uuid ELSE NULL END,
                    'SUCCESS', NEW.before_data::jsonb, NEW.after_data::jsonb,
                    jsonb_strip_nulls(jsonb_build_object(
                        'legacy_id', COALESCE(NEW.id::text, gen_random_uuid()::text),
                        'legacy_object_id', NEW.object_id,
                        'legacy_reason', NEW.reason
                    )),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP)
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $this->insteadOf('audit_log', 'hn_legacy_audit_log_write', 'INSERT');
    }

    private function insteadOf(string $view, string $function, string $events = 'INSERT OR UPDATE OR DELETE'): void
    {
        $trigger = substr('trg_'.$function, 0, 63);
        DB::statement('CREATE TRIGGER '.HoaNamErdSchema::quote($trigger).' INSTEAD OF '.$events.' ON '.HoaNamErdSchema::quote($view).' FOR EACH ROW EXECUTE FUNCTION '.HoaNamErdSchema::quote($function).'()');
    }

    private function statementIfSourcesExist(string $statement): void
    {
        try {
            DB::statement($statement);
        } catch (Throwable) {
            // Some environments may already have retired a legacy source. Cleanup below is idempotent.
        }
    }
};
