<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hand Tools DongCheng (P0): một `family_name` (vd. "Kìm điện đa năng") có nhiều
 * SKU variant (vd. "6 inch"/"7 inch"/"8 inch"). Hệ hiện tại chưa có bảng
 * family/variant riêng — `sku` vẫn là một dòng phẳng — nên grouping chỉ cần hai
 * cột mô tả trên chính `sku`, không cần bảng/khoá ngoại mới: nhóm biến thể được
 * suy ra bằng (brand_id, category_id, family_name) tại tầng đọc, không phải một
 * thực thể độc lập cần quản trị riêng.
 *
 * PostgreSQL: `skus` là bảng vật lý, `sku` chỉ là VIEW + INSTEAD OF trigger
 * (dựng lại lần gần nhất ở 2026_08_29_100000_promote_sku_min_max_stock_to_physical_columns).
 * Không thể ALTER một VIEW — phải: (1) thêm cột thật vào `skus`, (2) dựng lại
 * VIEW `sku` để chiếu thêm 2 cột đó, (3) dựng lại trigger `hn_legacy_sku_write`
 * để INSERT/UPDATE qua view `sku` (chính là cách PvCatalogImportService ghi)
 * thật sự lưu được family_name/variant_label xuống `skus` thay vì bị trigger
 * âm thầm bỏ qua vì không có trong danh sách cột INSERT.
 *
 * sqlite (test/dev): ngược lại, `sku` là bảng vật lý thật — chỉ cần ALTER
 * TABLE bình thường. View `skus` (chiều ngược) không cần cập nhật vì không có
 * code nào đọc family_name/variant_label qua nó.
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

    /**
     * Rollback thật (WWHN-300 `migrations.reversible`, 10/09/2026).
     *
     * pgsql: view `sku` và `hn_legacy_sku_write()` về đúng bản 2026_08_29_100000 (bản
     * nguyên văn, không đánh rơi cột nào khác của view), gắn lại trigger, rồi bỏ index nhóm
     * biến thể và hai cột `family_name` / `variant_label` khỏi `skus`.
     * sqlite: bỏ index `idx_sku_family_group` rồi hai cột trên bảng `sku`.
     *
     * Hệ quả dữ liệu: thông tin nhóm family/variant đã ghi mất theo cột.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->downPostgres(),
            'sqlite' => $this->downSqlite(),
            default => null,
        };
    }

    private function downPostgres(): void
    {
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
                   s.min_stock,
                   s.max_stock,
                   (s.specifications - 'power_or_voltage' - 'package_variant' - 'version_code' - 'warranty_applicable' - 'min_stock' - 'max_stock') AS specification,
                   COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false) AS published_to_app,
                   s.status, s.created_at, s.updated_at, s.version
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
                -- min_stock/max_stock nay là CỘT THẬT nên phải bóc khỏi jsonb,
                -- nếu không giá trị cũ kẹt trong specification sẽ chèn lại vào view.
                v_specs := (COALESCE(NEW.specification::jsonb, '{}'::jsonb)
                    || jsonb_strip_nulls(jsonb_build_object(
                           'power_or_voltage', NEW.power_or_voltage,
                           'package_variant', NEW.package_variant,
                           'version_code', NEW.version_code,
                           'warranty_applicable', NEW.warranty_applicable
                       ))) - 'min_stock' - 'max_stock';
                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    specifications, min_stock, max_stock, status, created_at, updated_at, version
                )
                VALUES (
                    v_sku_id, NEW.sku_code, NEW.sku_name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id, v_specs,
                    NEW.min_stock, NEW.max_stock,
                    COALESCE(NEW.status, 'ACTIVE'), COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 1)
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_code=EXCLUDED.sku_code, sku_name=EXCLUDED.sku_name, sku_type=EXCLUDED.sku_type,
                    tracking_mode=EXCLUDED.tracking_mode, unit_code=EXCLUDED.unit_code,
                    brand_id=EXCLUDED.brand_id, category_id=EXCLUDED.category_id, model_id=EXCLUDED.model_id,
                    power_source_id=EXCLUDED.power_source_id, package_variant_id=EXCLUDED.package_variant_id,
                    specifications=EXCLUDED.specifications,
                    min_stock=EXCLUDED.min_stock, max_stock=EXCLUDED.max_stock,
                    status=EXCLUDED.status,
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

        DB::statement('DROP INDEX IF EXISTS idx_skus_family_group');
        DB::statement('ALTER TABLE skus DROP COLUMN IF EXISTS variant_label');
        DB::statement('ALTER TABLE skus DROP COLUMN IF EXISTS family_name');
    }

    private function downSqlite(): void
    {
        if (! Schema::hasTable('sku')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_sku_family_group');

        $columns = array_values(array_filter(
            ['variant_label', 'family_name'],
            static fn (string $column): bool => Schema::hasColumn('sku', $column),
        ));

        if ($columns !== []) {
            Schema::table('sku', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function upPostgres(): void
    {
        DB::statement('ALTER TABLE skus ADD COLUMN IF NOT EXISTS family_name varchar(200)');
        DB::statement('ALTER TABLE skus ADD COLUMN IF NOT EXISTS variant_label varchar(100)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_skus_family_group ON skus (brand_id, category_id, family_name)');

        $this->recreateSkuViewAndTrigger();
    }

    /**
     * Bản sao nguyên văn view/trigger của 2026_08_29_100000 + thêm
     * family_name/variant_label — KHÔNG được đánh rơi bất kỳ cột/nhánh nào của
     * bản trước, nếu không mọi consumer khác của view `sku` (ScanClassification,
     * PvAdminResourceController, v.v.) sẽ mất dữ liệu âm thầm.
     */
    private function recreateSkuViewAndTrigger(): void
    {
        DB::statement('DROP VIEW IF EXISTS sku CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW sku AS
            SELECT s.id AS sku_id, s.sku_code, s.sku_name, s.sku_type, s.unit_code AS unit,
                   s.tracking_mode = 'SERIAL' AS is_serial_tracked,
                   s.brand_id, s.category_id, s.model_id, s.power_source_id, s.package_variant_id,
                   s.family_name, s.variant_label,
                   (s.specifications->>'power_or_voltage')::varchar(50) AS power_or_voltage,
                   (s.specifications->>'package_variant')::varchar(50) AS package_variant,
                   COALESCE(s.specifications->>'version_code', 'V1')::varchar(30) AS version_code,
                   COALESCE((s.specifications->>'warranty_applicable')::boolean, false) AS warranty_applicable,
                   s.min_stock,
                   s.max_stock,
                   (s.specifications - 'power_or_voltage' - 'package_variant' - 'version_code' - 'warranty_applicable' - 'min_stock' - 'max_stock') AS specification,
                   COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false) AS published_to_app,
                   s.status, s.created_at, s.updated_at, s.version
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
                v_specs := (COALESCE(NEW.specification::jsonb, '{}'::jsonb)
                    || jsonb_strip_nulls(jsonb_build_object(
                           'power_or_voltage', NEW.power_or_voltage,
                           'package_variant', NEW.package_variant,
                           'version_code', NEW.version_code,
                           'warranty_applicable', NEW.warranty_applicable
                       ))) - 'min_stock' - 'max_stock';
                INSERT INTO skus (
                    id, sku_code, sku_name, sku_type, tracking_mode, unit_code,
                    brand_id, category_id, model_id, power_source_id, package_variant_id,
                    family_name, variant_label,
                    specifications, min_stock, max_stock, status, created_at, updated_at, version
                )
                VALUES (
                    v_sku_id, NEW.sku_code, NEW.sku_name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id,
                    NEW.family_name, NEW.variant_label,
                    v_specs,
                    NEW.min_stock, NEW.max_stock,
                    COALESCE(NEW.status, 'ACTIVE'), COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 1)
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_code=EXCLUDED.sku_code, sku_name=EXCLUDED.sku_name, sku_type=EXCLUDED.sku_type,
                    tracking_mode=EXCLUDED.tracking_mode, unit_code=EXCLUDED.unit_code,
                    brand_id=EXCLUDED.brand_id, category_id=EXCLUDED.category_id, model_id=EXCLUDED.model_id,
                    power_source_id=EXCLUDED.power_source_id, package_variant_id=EXCLUDED.package_variant_id,
                    family_name=EXCLUDED.family_name, variant_label=EXCLUDED.variant_label,
                    specifications=EXCLUDED.specifications,
                    min_stock=EXCLUDED.min_stock, max_stock=EXCLUDED.max_stock,
                    status=EXCLUDED.status,
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

    private function upSqlite(): void
    {
        Schema::table('sku', function (Blueprint $table): void {
            if (! Schema::hasColumn('sku', 'family_name')) {
                $table->string('family_name', 200)->nullable()->after('sku_name');
            }
            if (! Schema::hasColumn('sku', 'variant_label')) {
                $table->string('variant_label', 100)->nullable()->after('family_name');
            }
        });

        Schema::table('sku', function (Blueprint $table): void {
            $table->index(['brand_id', 'category_id', 'family_name'], 'idx_sku_family_group');
        });
    }
};
