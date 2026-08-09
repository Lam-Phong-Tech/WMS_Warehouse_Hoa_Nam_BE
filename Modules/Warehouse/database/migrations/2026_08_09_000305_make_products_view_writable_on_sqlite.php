<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite does not make simple views writable automatically. Feature tests and
 * legacy callers intentionally exercise `products`, so add INSTEAD OF triggers
 * that write through to canonical `sku`. No second persisted product table is
 * created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        if (! Schema::hasTable('sku')) {
            throw new RuntimeException('products compatibility requires canonical sku table.');
        }

        $view = DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'view' AND name = 'products' LIMIT 1");
        if ($view === null) {
            throw new RuntimeException('products compatibility view is missing before writable-trigger migration.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS products_compat_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS products_compat_update');
        DB::unprepared('DROP TRIGGER IF EXISTS products_compat_delete');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER products_compat_insert
            INSTEAD OF INSERT ON products
            BEGIN
                INSERT INTO sku (
                    sku_id, sku_code, sku_name, sku_type, unit, is_serial_tracked,
                    brand_id, category_id, model_id, power_or_voltage, package_variant,
                    version_code, warranty_applicable, specification, published_to_app,
                    status, created_at, updated_at
                ) VALUES (
                    NEW.id, NEW.code, NEW.name,
                    COALESCE(NEW.sku_type, 'PRODUCT'),
                    COALESCE(NEW.unit, 'cái'),
                    COALESCE(NEW.is_serial_tracked, 0),
                    NEW.brand_id, NEW.category_id, NEW.model_id,
                    NEW.power_or_voltage, NEW.package_variant,
                    COALESCE(NEW.version_code, 'V1'),
                    COALESCE(NEW.warranty_applicable, 0),
                    NEW.specification,
                    COALESCE(NEW.published_to_app, 0),
                    COALESCE(NEW.status, 'ACTIVE'),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
                );
            END;
        SQL);

        DB::unprepared(<<<'SQL'
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
                    power_or_voltage = NEW.power_or_voltage,
                    package_variant = NEW.package_variant,
                    version_code = NEW.version_code,
                    warranty_applicable = NEW.warranty_applicable,
                    specification = NEW.specification,
                    published_to_app = NEW.published_to_app,
                    status = NEW.status,
                    updated_at = COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
                WHERE sku_id = OLD.id;
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER products_compat_delete
            INSTEAD OF DELETE ON products
            BEGIN
                DELETE FROM sku WHERE sku_id = OLD.id;
            END;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS products_compat_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS products_compat_update');
        DB::unprepared('DROP TRIGGER IF EXISTS products_compat_insert');
    }
};
