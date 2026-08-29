<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Định mức tồn theo SKU (min_stock / max_stock) — nâng từ thuộc tính "mở" trong
 * `skus.specifications` (jsonb, đặt ở 2026_08_27_110000) lên CỘT VẬT LÝ.
 *
 * Vì sao đổi quy ước: định mức không còn là thông số kỹ thuật tự do mà là dữ
 * liệu nghiệp vụ có RULE cứng, phục vụ báo cáo định mức tồn:
 *   - min_stock >= 0        (0 là một định mức hợp lệ, không phải "chưa đặt")
 *   - max_stock >= 1
 *   - max_stock >  min_stock
 * Rule đó chỉ ép được ở tầng DB bằng CHECK trên cột thật; nằm trong jsonb thì
 * mọi writer ghi thẳng bảng đều lách được. Cột thật cũng cho phép index riêng
 * cho query "tồn dưới/vượt định mức" thay vì ép kiểu từng dòng.
 *
 * NULL vẫn giữ nghĩa "chưa đặt định mức" — không tính vào cảnh báo nào.
 *
 * PostgreSQL: `skus` là bảng thật; `sku`/`products` là compat VIEW + INSTEAD OF
 * trigger (2026_08_10_000790). Phải DROP VIEW ... CASCADE rồi CREATE lại (Postgres
 * cấm đổi vị trí cột hiện có qua CREATE OR REPLACE VIEW), và CASCADE xoá kèm
 * trigger nên phải dựng lại đủ CẢ HAI trigger.
 *
 * NOTE: 2026_08_27_110000 đã DROP VIEW products CASCADE mà quên dựng lại
 * `trg_hn_legacy_products_write` (2026_08_16_123000) — migration này khôi phục.
 *
 * sqlite (test/dev): ngược lại — `sku` là bảng thật, `skus` là view. Bản
 * 2026_08_27_110000 chỉ chạy trên pgsql nên sqlite CHƯA HỀ có min_stock/max_stock,
 * làm `DB::table('sku')->whereNotNull('min_stock')` ở dashboard-quick vỡ. Nhánh
 * sqlite dưới đây vá luôn khoảng trống đó.
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
        // Fail-forward: cột định mức là dữ liệu nghiệp vụ người dùng đã nhập,
        // rollback sẽ mất trắng. Xem docs/HoaNam_ERD_Moi_Nhat.md bảng 16.
    }

    private function upPostgres(): void
    {
        DB::statement('ALTER TABLE skus ADD COLUMN IF NOT EXISTS min_stock numeric(18,3)');
        DB::statement('ALTER TABLE skus ADD COLUMN IF NOT EXISTS max_stock numeric(18,3)');

        // Chuyển dữ liệu đã cấu hình trong jsonb sang cột thật rồi mới bỏ key đi.
        // Dùng jsonb_exists(...) chứ không dùng toán tử `?` vì PDO đọc `?` là
        // placeholder và sẽ ném "number of bound variables does not match".
        DB::statement(<<<'SQL'
            UPDATE skus
               SET min_stock = COALESCE(min_stock, (specifications->>'min_stock')::numeric(18,3)),
                   max_stock = COALESCE(max_stock, (specifications->>'max_stock')::numeric(18,3))
             WHERE jsonb_exists(specifications, 'min_stock')
                OR jsonb_exists(specifications, 'max_stock')
        SQL);
        DB::statement(<<<'SQL'
            UPDATE skus
               SET specifications = specifications - 'min_stock' - 'max_stock'
             WHERE jsonb_exists(specifications, 'min_stock')
                OR jsonb_exists(specifications, 'max_stock')
        SQL);

        $this->normalizeLegacyThresholds();

        DB::statement('ALTER TABLE skus DROP CONSTRAINT IF EXISTS chk_skus_stock_thresholds');
        DB::statement(<<<'SQL'
            ALTER TABLE skus ADD CONSTRAINT chk_skus_stock_thresholds CHECK (
                    (min_stock IS NULL OR min_stock >= 0)
                AND (max_stock IS NULL OR max_stock >= 1)
                AND (min_stock IS NULL OR max_stock IS NULL OR max_stock > min_stock)
            )
        SQL);

        // Báo cáo định mức tồn chỉ quan tâm SKU ĐÃ đặt định mức — partial index
        // để không phình theo toàn bộ catalog.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_skus_stock_thresholds
                ON skus (min_stock, max_stock)
             WHERE min_stock IS NOT NULL OR max_stock IS NOT NULL
        SQL);

        $this->recreateCompatViewsAndTriggers();
    }

    /**
     * Dữ liệu đặt trước khi rule được chốt có thể vi phạm CHECK mới (max < 1,
     * hoặc max <= min). Hạ về NULL = "chưa đặt định mức" thay vì để migration
     * chết giữa chừng; người dùng nhập lại qua màn Danh sách SKU.
     */
    private function normalizeLegacyThresholds(): void
    {
        DB::statement('UPDATE skus SET min_stock = NULL WHERE min_stock IS NOT NULL AND min_stock < 0');
        DB::statement('UPDATE skus SET max_stock = NULL WHERE max_stock IS NOT NULL AND max_stock < 1');
        DB::statement(<<<'SQL'
            UPDATE skus
               SET max_stock = NULL
             WHERE max_stock IS NOT NULL
               AND min_stock IS NOT NULL
               AND max_stock <= min_stock
        SQL);
    }

    private function recreateCompatViewsAndTriggers(): void
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

        // Khôi phục bản đầy đủ của 2026_08_17_100000 (power_source_id /
        // package_variant_id / version) — bản 2026_08_27_110000 dựng lại view này
        // đã đánh rơi 3 cột đó, kéo theo Product model mất optimistic lock.
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
                   s.min_stock,
                   s.max_stock,
                   (s.specifications - 'power_or_voltage' - 'package_variant' - 'version_code' - 'warranty_applicable' - 'min_stock' - 'max_stock') AS specification,
                   COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false) AS published_to_app,
                   s.status, s.version, s.created_at, s.updated_at
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

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_products_write() RETURNS trigger AS $$
            DECLARE
                v_sku_id uuid;
                v_specs jsonb;
            BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM skus WHERE id=OLD.id; RETURN OLD; END IF;
                v_sku_id := COALESCE(NEW.id, gen_random_uuid());
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
                    specifications, min_stock, max_stock, status,
                    created_at, updated_at, version
                )
                VALUES (
                    v_sku_id, NEW.code, NEW.name, COALESCE(NEW.sku_type, 'PRODUCT'),
                    CASE WHEN COALESCE(NEW.is_serial_tracked, false) THEN 'SERIAL' ELSE 'QUANTITY' END,
                    COALESCE(NEW.unit, 'cai'), NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_source_id, NEW.package_variant_id,
                    v_specs, NEW.min_stock, NEW.max_stock, COALESCE(NEW.status, 'ACTIVE'),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.version, 1)
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_code=EXCLUDED.sku_code, sku_name=EXCLUDED.sku_name, sku_type=EXCLUDED.sku_type,
                    tracking_mode=EXCLUDED.tracking_mode, unit_code=EXCLUDED.unit_code,
                    brand_id=EXCLUDED.brand_id, category_id=EXCLUDED.category_id, model_id=EXCLUDED.model_id,
                    power_source_id=EXCLUDED.power_source_id, package_variant_id=EXCLUDED.package_variant_id,
                    specifications=EXCLUDED.specifications,
                    min_stock=EXCLUDED.min_stock, max_stock=EXCLUDED.max_stock,
                    status=EXCLUDED.status, version=EXCLUDED.version, updated_at=EXCLUDED.updated_at;

                -- REQ-SRS-020: publish qua compat view products cũng persist thật.
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
        DB::statement('CREATE TRIGGER trg_hn_legacy_products_write INSTEAD OF INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION hn_legacy_products_write()');
    }

    /**
     * sqlite: `sku` là bảng vật lý, `skus` là view chiếu lên nó (2026_08_10_000610).
     * CHECK constraint không thêm được bằng ALTER TABLE trên sqlite — rule do
     * Store/UpdateProductRequest chặn; ở đây chỉ cần đủ cột để đọc/ghi.
     */
    private function upSqlite(): void
    {
        // Cùng guard với 2026_08_17_100000: hai view dưới đây chiếu cột `version`,
        // chưa có cột đó thì chưa tới lượt migration này chạy.
        if (! Schema::hasTable('sku') || ! Schema::hasColumn('sku', 'version')) {
            return;
        }

        Schema::table('sku', function (Blueprint $table): void {
            if (! Schema::hasColumn('sku', 'min_stock')) {
                $table->decimal('min_stock', 18, 3)->nullable();
            }
            if (! Schema::hasColumn('sku', 'max_stock')) {
                $table->decimal('max_stock', 18, 3)->nullable();
            }
        });

        DB::statement('DROP VIEW IF EXISTS skus');
        DB::statement(<<<'SQL'
            CREATE VIEW skus AS
            SELECT sku_id AS id,
                   sku_code,
                   sku_name,
                   sku_type,
                   CASE WHEN is_serial_tracked THEN 'SERIAL' ELSE 'QUANTITY' END AS tracking_mode,
                   unit AS unit_code,
                   brand_id,
                   category_id,
                   model_id,
                   power_source_id,
                   package_variant_id,
                   specification AS specifications,
                   min_stock,
                   max_stock,
                   status,
                   created_at,
                   NULL AS created_by,
                   updated_at,
                   NULL AS updated_by,
                   version
            FROM sku
        SQL);

        $this->refreshSqliteProductsCompat();
    }

    /**
     * `products` trên sqlite là view + 3 INSTEAD OF trigger (2026_08_17_100000).
     * Phải dựng lại cả bộ để ScanClassificationService / Product model đọc-ghi
     * được định mức qua tên legacy, giống nhánh pgsql.
     */
    private function refreshSqliteProductsCompat(): void
    {
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
                min_stock,
                max_stock,
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
                    version_code, warranty_applicable, specification,
                    min_stock, max_stock, published_to_app,
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
                    NEW.min_stock, NEW.max_stock,
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
                    min_stock = NEW.min_stock,
                    max_stock = NEW.max_stock,
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
