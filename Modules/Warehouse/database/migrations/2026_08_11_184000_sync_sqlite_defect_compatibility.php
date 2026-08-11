<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite is used by the executable CI/local test runtime. The production
 * PostgreSQL migration chain replaces `defect_catalog` with a compatibility
 * view over canonical `defect_catalogs`; SQLite intentionally skips those
 * PostgreSQL-only view/trigger migrations, so its legacy FK on
 * warranty_case.defect_id otherwise points at an unsynchronised table.
 *
 * Keep the SQLite compatibility relation mirrored from the canonical master so
 * warranty behaviour is identical across CI/local and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite'
            || ! Schema::hasTable('defect_catalogs')
            || ! Schema::hasTable('defect_catalog')) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT OR REPLACE INTO defect_catalog (
                defect_id, defect_code, defect_name, severity, status, created_at, updated_at
            )
            SELECT defect_id, defect_code, defect_name, NULL, status, created_at, updated_at
              FROM defect_catalogs
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_sqlite_defect_catalog_insert;
            CREATE TRIGGER trg_sqlite_defect_catalog_insert
            AFTER INSERT ON defect_catalogs
            FOR EACH ROW
            BEGIN
                INSERT OR REPLACE INTO defect_catalog (
                    defect_id, defect_code, defect_name, severity, status, created_at, updated_at
                ) VALUES (
                    NEW.defect_id, NEW.defect_code, NEW.defect_name, NULL,
                    NEW.status, NEW.created_at, NEW.updated_at
                );
            END;

            DROP TRIGGER IF EXISTS trg_sqlite_defect_catalog_update;
            CREATE TRIGGER trg_sqlite_defect_catalog_update
            AFTER UPDATE ON defect_catalogs
            FOR EACH ROW
            BEGIN
                INSERT OR REPLACE INTO defect_catalog (
                    defect_id, defect_code, defect_name, severity, status, created_at, updated_at
                ) VALUES (
                    NEW.defect_id, NEW.defect_code, NEW.defect_name, NULL,
                    NEW.status, NEW.created_at, NEW.updated_at
                );
            END;

            DROP TRIGGER IF EXISTS trg_sqlite_defect_catalog_delete;
            CREATE TRIGGER trg_sqlite_defect_catalog_delete
            AFTER DELETE ON defect_catalogs
            FOR EACH ROW
            BEGIN
                DELETE FROM defect_catalog WHERE defect_id = OLD.defect_id;
            END;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_sqlite_defect_catalog_insert;
            DROP TRIGGER IF EXISTS trg_sqlite_defect_catalog_update;
            DROP TRIGGER IF EXISTS trg_sqlite_defect_catalog_delete;
        SQL);
    }
};
