<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GĐ1 Dashboard: "tồn dưới/vượt định mức" — hệ thống chưa có khái niệm định mức
 * tồn kho ở đâu cả. Chốt theo BA: định mức theo TỪNG SKU, TOÀN HỆ THỐNG (không
 * theo từng kho). NULL = chưa đặt, không tính cảnh báo.
 *
 * `sku`/`products` KHÔNG phải bảng thật — là compat VIEW chiếu từ bảng canonical
 * `skus` (xem `2026_08_16_121000_project_publish_state_through_sku_compat_views.php`).
 * Thuộc tính "mở" (power_or_voltage, package_variant, version_code,
 * warranty_applicable...) sống trong `skus.specifications` (jsonb) theo đúng ERD
 * bảng 16 — min_stock/max_stock đi theo cùng quy ước đó, KHÔNG thêm cột vật lý
 * mới vào `skus` để khỏi lệch pattern đã chốt.
 *
 * Theo đúng migration gốc: DROP VIEW ... CASCADE rồi CREATE lại (không dùng
 * CREATE OR REPLACE VIEW vì Postgres cấm đổi vị trí cột hiện có) — CASCADE xoá
 * kèm trigger ghi nên phải recreate trigger lại full, không chỉ thêm cột.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS sku CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW sku AS
            SELECT s.id AS sku_id, s.sku_code, s.sku_name, s.sku_type, s.unit_code AS unit,
                   s.tracking_mode = 'SERIAL' AS is_serial_tracked,
                   s.brand_id, s.category_id, s.model_id, s.power_source_id, s.package_variant_id,
                   (s.specifications->>'power_or_voltage')::varchar(50) AS power_or_voltage,
                   (s.specifications->>'package_variant')::varchar(50) AS package_variant,
                   COALESCE(s.specifications->>'version_code', 'V1')::varchar(30) AS version_code,
                   COALESCE((s.specifications->>'warranty_applicable')::boolean, false) AS warranty_applicable,
                   (s.specifications->>'min_stock')::numeric(18,3) AS min_stock,
                   (s.specifications->>'max_stock')::numeric(18,3) AS max_stock,
                   (s.specifications - 'power_or_voltage' - 'package_variant' - 'version_code' - 'warranty_applicable' - 'min_stock' - 'max_stock') AS specification,
                   COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false) AS published_to_app,
                   s.status, s.created_at, s.updated_at, s.version
              FROM skus s
        SQL);
        DB::statement('DROP VIEW IF EXISTS products CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW products AS
            SELECT s.id, s.sku_code AS code, s.sku_name AS name, s.sku_type, s.unit_code AS unit,
                   s.tracking_mode = 'SERIAL' AS is_serial_tracked,
                   s.brand_id, s.category_id, s.model_id,
                   (s.specifications->>'power_or_voltage')::varchar(50) AS power_or_voltage,
                   (s.specifications->>'package_variant')::varchar(50) AS package_variant,
                   COALESCE(s.specifications->>'version_code', 'V1')::varchar(30) AS version_code,
                   COALESCE((s.specifications->>'warranty_applicable')::boolean, false) AS warranty_applicable,
                   (s.specifications->>'min_stock')::numeric(18,3) AS min_stock,
                   (s.specifications->>'max_stock')::numeric(18,3) AS max_stock,
                   (s.specifications - 'power_or_voltage' - 'package_variant' - 'version_code' - 'warranty_applicable' - 'min_stock' - 'max_stock') AS specification,
                   COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false) AS published_to_app,
                   s.status, s.created_at, s.updated_at
              FROM skus s
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_sku_write() RETURNS trigger AS $$
            DECLARE
                v_sku_id uuid;
                v_specs jsonb;
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM skus WHERE id=OLD.sku_id; RETURN OLD; END IF;
                v_sku_id := COALESCE(NEW.sku_id, gen_random_uuid());
                -- ERD bảng 16: thuộc tính legacy sống trong specifications jsonb.
                v_specs := COALESCE(NEW.specification::jsonb, '{}'::jsonb)
                    || jsonb_strip_nulls(jsonb_build_object(
                           'power_or_voltage', NEW.power_or_voltage,
                           'package_variant', NEW.package_variant,
                           'version_code', NEW.version_code,
                           'warranty_applicable', NEW.warranty_applicable,
                           'min_stock', NEW.min_stock,
                           'max_stock', NEW.max_stock
                       ));
                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    specifications, status, created_at, updated_at, version
                )
                VALUES (
                    v_sku_id, NEW.sku_code, NEW.sku_name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id, v_specs,
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

                -- REQ-SRS-020: persist publish state vào pv_product_profiles.published (ERD 51).
                IF NEW.published_to_app IS NOT NULL THEN
                    INSERT INTO pv_product_profiles (id, sku_id, display_name, published, published_at, sort_order, version, created_at, updated_at)
                    VALUES (gen_random_uuid(), v_sku_id, NEW.sku_name, NEW.published_to_app,
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
        DB::statement('CREATE TRIGGER trg_hn_legacy_sku_write INSTEAD OF INSERT OR UPDATE OR DELETE ON sku FOR EACH ROW EXECUTE FUNCTION hn_legacy_sku_write()');
    }

    public function down(): void
    {
        // Fail-forward: cột mới additive trong specifications jsonb, không rollback
        // để tránh mất định mức đã cấu hình.
    }
};
