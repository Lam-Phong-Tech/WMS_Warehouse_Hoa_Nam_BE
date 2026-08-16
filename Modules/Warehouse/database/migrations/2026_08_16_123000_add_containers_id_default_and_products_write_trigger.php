<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. containers có cặp cột định danh (container_id chính + id mirror ERD) cùng
 *    NOT NULL nhưng id không có default — mọi writer quên set id (Eloquent
 *    Container model, PutawayController trước fix) đều 500 trên PostgreSQL.
 *    BEFORE INSERT trigger đồng bộ id <-> container_id để cả hai luôn có giá trị.
 *
 * 2. View `products` sau khi project publish thật (LEFT JOIN pv_product_profiles
 *    ở 2026_08_16_121000) không còn auto-updatable; thêm INSTEAD OF trigger
 *    tương đương hn_legacy_sku_write cho các writer legacy dùng model Product.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_containers_sync_identity() RETURNS trigger AS $$
            BEGIN
                NEW.container_id := COALESCE(NEW.container_id, NEW.id, gen_random_uuid());
                NEW.id := COALESCE(NEW.id, NEW.container_id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_containers_sync_identity ON containers');
        DB::statement('CREATE TRIGGER trg_containers_sync_identity BEFORE INSERT ON containers FOR EACH ROW EXECUTE FUNCTION hn_containers_sync_identity()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_products_write() RETURNS trigger AS $$
            DECLARE
                v_sku_id uuid;
                v_specs jsonb;
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM skus WHERE id=OLD.id; RETURN OLD; END IF;
                v_sku_id := COALESCE(NEW.id, gen_random_uuid());
                -- ERD bảng 16: thuộc tính legacy sống trong specifications jsonb.
                v_specs := COALESCE(NEW.specification::jsonb, '{}'::jsonb)
                    || jsonb_strip_nulls(jsonb_build_object(
                           'power_or_voltage', NEW.power_or_voltage,
                           'package_variant', NEW.package_variant,
                           'version_code', NEW.version_code,
                           'warranty_applicable', NEW.warranty_applicable
                       ));
                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, specifications, status,
                    created_at, updated_at, version
                )
                VALUES (
                    v_sku_id, NEW.code, NEW.name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    v_specs, COALESCE(NEW.status, 'ACTIVE'),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), 1
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_code=EXCLUDED.sku_code, sku_name=EXCLUDED.sku_name, sku_type=EXCLUDED.sku_type,
                    tracking_mode=EXCLUDED.tracking_mode, unit_code=EXCLUDED.unit_code,
                    brand_id=EXCLUDED.brand_id, category_id=EXCLUDED.category_id, model_id=EXCLUDED.model_id,
                    specifications=EXCLUDED.specifications, status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at;
                -- REQ-SRS-020: publish qua compat view products cũng persist thật.

                -- REQ-SRS-020: publish qua compat view cũng phải persist thật.
                IF NEW.published_to_app IS NOT NULL THEN
                    INSERT INTO pv_product_profiles (id, sku_id, display_name, published, published_at, sort_order, version, created_at, updated_at)
                    VALUES (gen_random_uuid(), v_sku_id, NEW.name, NEW.published_to_app,
                            CASE WHEN NEW.published_to_app THEN CURRENT_TIMESTAMP END,
                            0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT (sku_id) DO UPDATE SET
                        published = EXCLUDED.published,
                        published_at = CASE WHEN EXCLUDED.published THEN COALESCE(pv_product_profiles.published_at, CURRENT_TIMESTAMP) END,
                        updated_at = CURRENT_TIMESTAMP;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_hn_legacy_products_write ON products');
        DB::statement('CREATE TRIGGER trg_hn_legacy_products_write INSTEAD OF INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION hn_legacy_products_write()');
    }

    public function down(): void
    {
        // Fail-forward.
    }
};
