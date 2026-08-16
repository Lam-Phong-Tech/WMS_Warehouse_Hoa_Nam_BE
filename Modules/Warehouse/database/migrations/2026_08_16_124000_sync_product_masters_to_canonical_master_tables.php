<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-SRS-029 / ERD bảng 11–14: brand/category/model/power/package canonical là
 * brands, product_categories, product_models, power_sources, package_variants.
 * API compat /product-masters ghi bảng product_masters cô lập nên master tạo
 * qua đó không tham chiếu được từ skus.* (FK fk_erd_skus_* trỏ bảng canonical)
 * → PATCH /products gán brand_id nhận 500 FK violation.
 * Trigger sync product_masters → bảng canonical theo master_type, dùng
 * master_id làm id canonical để FK khớp trực tiếp.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_sync_product_master_to_canonical() RETURNS trigger AS $$
            BEGIN
                IF NEW.master_type = 'BRAND' THEN
                    INSERT INTO brands (id, brand_code, brand_name, status, sort_order, created_at, updated_at)
                    VALUES (NEW.master_id, NEW.code, NEW.name, NEW.status, 0, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)
                    ON CONFLICT (id) DO UPDATE SET brand_code=EXCLUDED.brand_code, brand_name=EXCLUDED.brand_name,
                        status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP;
                ELSIF NEW.master_type = 'CATEGORY' THEN
                    INSERT INTO product_categories (id, category_code, category_name, status, sort_order, created_at, updated_at)
                    VALUES (NEW.master_id, NEW.code, NEW.name, NEW.status, 0, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)
                    ON CONFLICT (id) DO UPDATE SET category_code=EXCLUDED.category_code, category_name=EXCLUDED.category_name,
                        status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP;
                ELSIF NEW.master_type = 'MODEL' THEN
                    INSERT INTO product_models (id, model_code, model_name, brand_id, status, sort_order, created_at, updated_at)
                    VALUES (NEW.master_id, NEW.code, NEW.name, NEW.parent_master_id, NEW.status, 0, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)
                    ON CONFLICT (id) DO UPDATE SET model_code=EXCLUDED.model_code, model_name=EXCLUDED.model_name,
                        brand_id=EXCLUDED.brand_id, status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP;
                ELSIF NEW.master_type = 'POWER' THEN
                    INSERT INTO power_sources (id, power_source_code, power_source_name, status, sort_order, created_at, updated_at)
                    VALUES (NEW.master_id, NEW.code, NEW.name, NEW.status, 0, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)
                    ON CONFLICT (id) DO UPDATE SET power_source_code=EXCLUDED.power_source_code, power_source_name=EXCLUDED.power_source_name,
                        status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP;
                ELSIF NEW.master_type = 'PACKAGE' THEN
                    INSERT INTO package_variants (id, variant_code, variant_name, status, sort_order, created_at, updated_at)
                    VALUES (NEW.master_id, NEW.code, NEW.name, NEW.status, 0, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)
                    ON CONFLICT (id) DO UPDATE SET variant_code=EXCLUDED.variant_code, variant_name=EXCLUDED.variant_name,
                        status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_hn_sync_product_master_to_canonical ON product_masters');
        DB::statement('CREATE TRIGGER trg_hn_sync_product_master_to_canonical AFTER INSERT OR UPDATE ON product_masters FOR EACH ROW EXECUTE FUNCTION hn_sync_product_master_to_canonical()');

        // Backfill các master đã tạo trước khi có trigger.
        DB::statement("UPDATE product_masters SET updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)");
        DB::statement(<<<'SQL'
            DO $$
            DECLARE r RECORD;
            BEGIN
                FOR r IN SELECT * FROM product_masters LOOP
                    UPDATE product_masters SET updated_at = updated_at WHERE master_id = r.master_id;
                END LOOP;
            END $$
        SQL);
    }

    public function down(): void
    {
        // Fail-forward.
    }
};
