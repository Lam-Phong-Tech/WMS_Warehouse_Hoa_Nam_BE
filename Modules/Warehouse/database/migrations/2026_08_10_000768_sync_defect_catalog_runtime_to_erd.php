<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('defect_catalogs')
            || ! Schema::hasTable('defect_catalog')) {
            return;
        }

        $this->sync();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_defect_catalog()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO defect_catalog (
                    id, defect_code, defect_name, description, status,
                    created_at, created_by, updated_at, updated_by, version
                )
                SELECT defect_id, defect_code, defect_name, description, status,
                       created_at, NULL, COALESCE(updated_at, created_at), NULL, COALESCE(version, 0)
                  FROM defect_catalogs
                ON CONFLICT (id) DO UPDATE SET
                    defect_code = EXCLUDED.defect_code,
                    defect_name = EXCLUDED.defect_name,
                    description = EXCLUDED.description,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version;

                DELETE FROM defect_catalog c
                 WHERE NOT EXISTS (SELECT 1 FROM defect_catalogs s WHERE s.defect_id = c.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_erd_mirror_defect_catalog_from_runtime ON defect_catalogs');
        DB::statement('CREATE TRIGGER trg_erd_mirror_defect_catalog_from_runtime AFTER INSERT OR UPDATE OR DELETE ON defect_catalogs FOR EACH STATEMENT EXECUTE FUNCTION hn_erd_mirror_defect_catalog()');
    }

    public function down(): void
    {
        // Fail-forward data bridge.
    }

    private function sync(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO defect_catalog (
                id, defect_code, defect_name, description, status,
                created_at, created_by, updated_at, updated_by, version
            )
            SELECT defect_id, defect_code, defect_name, description, status,
                   created_at, NULL, COALESCE(updated_at, created_at), NULL, COALESCE(version, 0)
              FROM defect_catalogs
            ON CONFLICT (id) DO UPDATE SET
                defect_code = EXCLUDED.defect_code,
                defect_name = EXCLUDED.defect_name,
                description = EXCLUDED.description,
                status = EXCLUDED.status,
                updated_at = EXCLUDED.updated_at,
                version = EXCLUDED.version
        SQL);
    }
};
