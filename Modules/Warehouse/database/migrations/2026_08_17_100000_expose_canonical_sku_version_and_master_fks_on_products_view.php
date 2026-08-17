<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD bảng 16 (skus) + SRS §13.6 Headers (If-Match/ETag).
 *
 * Compat view `products` trước đây KHÔNG chiếu `skus.version` và cũng không chiếu
 * `power_source_id` / `package_variant_id`. Hệ quả:
 *
 * 1. P0 — Product model phải "chế" ra `version` từ `version_code` ('V1' -> 1) và
 *    ghi ngược lại khi optimistic lock tăng version. Mỗi lần Sửa SKU hoặc bật/tắt
 *    publish qua MST-01 đều đẩy version_code V1 -> V2 -> V3, tức là optimistic
 *    lock đang ghi đè một trường nghiệp vụ (master "version" của REQ-SRS-029,
 *    đồng thời là hậu tố trong quy ước mã SKU `...-220V-V1`).
 * 2. P1 — MST-02 không thể gắn Nguồn điện / Quy cách đóng gói theo FK ERD; giá trị
 *    chỉ nằm ở text tự do `power_or_voltage`, còn `package_variant` là enum cứng.
 *    Master /power-sources và /package-variants vì thế không có SKU nào trỏ tới.
 *
 * Migration này chiếu đủ 3 cột và cập nhật write-path (INSTEAD OF trigger trên
 * PostgreSQL, compat trigger trên sqlite) để cả đọc lẫn ghi đi qua cột thật.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->upPostgres(),
            'sqlite' => $this->upSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        // Fail-forward: view rộng hơn không phá client cũ.
    }

    private function upPostgres(): void
    {
        if (! Schema::hasTable('skus')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS products CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW products AS
            SELECT s.id, s.sku_code AS code, s.sku_name AS name, s.sku_type, s.unit_code AS unit,
                   s.tracking_mode = 'SERIAL' AS is_serial_tracked,
                   s.brand_id, s.category_id, s.model_id,
                   s.power_source_id, s.package_variant_id,
                   (s.specifications->>'power_or_voltage')::varchar(50) AS power_or_voltage,
                   (s.specifications->>'package_variant')::varchar(50) AS package_variant,
                   COALESCE(s.specifications->>'version_code', 'V1')::varchar(30) AS version_code,
                   COALESCE((s.specifications->>'warranty_applicable')::boolean, false) AS warranty_applicable,
                   (s.specifications - 'power_or_voltage' - 'package_variant' - 'version_code' - 'warranty_applicable') AS specification,
                   COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false) AS published_to_app,
                   s.status, s.version, s.created_at, s.updated_at
              FROM skus s
        SQL);

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
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    specifications, status, created_at, updated_at, version
                )
                VALUES (
                    v_sku_id, NEW.code, NEW.name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id,
                    v_specs, COALESCE(NEW.status, 'ACTIVE'),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.version, 1)
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_code=EXCLUDED.sku_code, sku_name=EXCLUDED.sku_name, sku_type=EXCLUDED.sku_type,
                    tracking_mode=EXCLUDED.tracking_mode, unit_code=EXCLUDED.unit_code,
                    brand_id=EXCLUDED.brand_id, category_id=EXCLUDED.category_id, model_id=EXCLUDED.model_id,
                    power_source_id=EXCLUDED.power_source_id, package_variant_id=EXCLUDED.package_variant_id,
                    specifications=EXCLUDED.specifications, status=EXCLUDED.status,
                    version=EXCLUDED.version,
                    updated_at=EXCLUDED.updated_at;

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

    private function upSqlite(): void
    {
        if (! Schema::hasTable('sku') || ! Schema::hasColumn('sku', 'version')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS products');
        DB::statement(<<<'SQL'
            CREATE VIEW products AS
            SELECT
                sku_id AS id,
                sku_code AS code,
                sku_name AS name,
                sku_type,
                unit,
                is_serial_tracked,
                brand_id,
                category_id,
                model_id,
                power_source_id,
                package_variant_id,
                power_or_voltage,
                package_variant,
                version_code,
                warranty_applicable,
                specification,
                published_to_app,
                status,
                version,
                created_at,
                updated_at
            FROM sku
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS products_compat_insert;
            CREATE TRIGGER products_compat_insert
            INSTEAD OF INSERT ON products
            BEGIN
                INSERT INTO sku (
                    sku_id, sku_code, sku_name, sku_type, unit, is_serial_tracked,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    power_or_voltage, package_variant,
                    version_code, warranty_applicable, specification, published_to_app,
                    status, version, created_at, updated_at
                ) VALUES (
                    NEW.id, NEW.code, NEW.name,
                    COALESCE(NEW.sku_type, 'PRODUCT'),
                    COALESCE(NEW.unit, 'cái'),
                    COALESCE(NEW.is_serial_tracked, 0),
                    NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id,
                    NEW.power_or_voltage, NEW.package_variant,
                    COALESCE(NEW.version_code, 'V1'),
                    COALESCE(NEW.warranty_applicable, 0),
                    NEW.specification,
                    COALESCE(NEW.published_to_app, 0),
                    COALESCE(NEW.status, 'ACTIVE'),
                    COALESCE(NEW.version, 1),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
                );
            END;

            DROP TRIGGER IF EXISTS products_compat_update;
            CREATE TRIGGER products_compat_update
            INSTEAD OF UPDATE ON products
            BEGIN
                UPDATE sku SET
                    sku_code = NEW.code,
                    sku_name = NEW.name,
                    sku_type = NEW.sku_type,
                    unit = NEW.unit,
                    is_serial_tracked = NEW.is_serial_tracked,
                    brand_id = NEW.brand_id,
                    category_id = NEW.category_id,
                    model_id = NEW.model_id,
                    power_source_id = NEW.power_source_id,
                    package_variant_id = NEW.package_variant_id,
                    power_or_voltage = NEW.power_or_voltage,
                    package_variant = NEW.package_variant,
                    version_code = NEW.version_code,
                    warranty_applicable = NEW.warranty_applicable,
                    specification = NEW.specification,
                    published_to_app = NEW.published_to_app,
                    status = NEW.status,
                    version = COALESCE(NEW.version, 1),
                    updated_at = COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
                WHERE sku_id = OLD.id;
            END;

            DROP TRIGGER IF EXISTS products_compat_delete;
            CREATE TRIGGER products_compat_delete
            INSTEAD OF DELETE ON products
            BEGIN
                DELETE FROM sku WHERE sku_id = OLD.id;
            END;
        SQL);
    }
};
