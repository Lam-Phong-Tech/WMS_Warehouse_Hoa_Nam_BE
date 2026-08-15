<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserve the legacy SKU import `version_code` contract after the ERD 1.0
     * PostgreSQL cut-over retired the physical `sku` table in favour of a
     * compatibility view over canonical `skus`.
     *
     * `version_code` is deliberately NOT mapped to canonical `skus.version`:
     * - version_code: legacy/business import value (varchar, e.g. V3)
     * - version: canonical optimistic-lock counter (integer / If-Match)
     *
     * The canonical ERD has no dedicated version_code column, so the value is
     * persisted as compatibility metadata inside `skus.specifications` and is
     * stripped from the public legacy `specification` projection. This keeps the
     * physical ERD unchanged while making the legacy import contract lossless.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('skus')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW sku AS
            SELECT id AS sku_id, sku_code, sku_name, sku_type, unit_code AS unit,
                   tracking_mode = 'SERIAL' AS is_serial_tracked,
                   brand_id, category_id, model_id, power_source_id, package_variant_id,
                   NULL::varchar(50) AS power_or_voltage, NULL::varchar(50) AS package_variant,
                   COALESCE(NULLIF(specifications->>'_legacy_version_code', ''), 'V1')::varchar(30) AS version_code,
                   false AS warranty_applicable,
                   CASE
                       WHEN specifications IS NULL THEN NULL
                       ELSE specifications - '_legacy_version_code'
                   END AS specification,
                   false AS published_to_app,
                   status, created_at, updated_at, version
              FROM skus
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW products AS
            SELECT id, sku_code AS code, sku_name AS name, sku_type, unit_code AS unit,
                   tracking_mode = 'SERIAL' AS is_serial_tracked,
                   brand_id, category_id, model_id,
                   NULL::varchar(50) AS power_or_voltage, NULL::varchar(50) AS package_variant,
                   COALESCE(NULLIF(specifications->>'_legacy_version_code', ''), 'V1')::varchar(30) AS version_code,
                   false AS warranty_applicable,
                   CASE
                       WHEN specifications IS NULL THEN NULL
                       ELSE specifications - '_legacy_version_code'
                   END AS specification,
                   false AS published_to_app,
                   status, created_at, updated_at
              FROM skus
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_sku_write() RETURNS trigger AS $$
            DECLARE
                compatibility_specification jsonb;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM skus WHERE id = OLD.sku_id;
                    RETURN OLD;
                END IF;

                compatibility_specification :=
                    COALESCE(NEW.specification::jsonb, '{}'::jsonb)
                    || jsonb_build_object(
                        '_legacy_version_code',
                        COALESCE(NULLIF(BTRIM(NEW.version_code), ''), 'V1')
                    );

                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    specifications, status, created_at, updated_at, version
                )
                VALUES (
                    COALESCE(NEW.sku_id, gen_random_uuid()), NEW.sku_code, NEW.sku_name,
                    COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id, compatibility_specification,
                    COALESCE(NEW.status, 'ACTIVE'), COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 1)
                )
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
                    version = EXCLUDED.version;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward compatibility correction. Reintroducing the lossy V1-only
        // projection would corrupt legacy import semantics, so rollback is no-op.
    }
};
