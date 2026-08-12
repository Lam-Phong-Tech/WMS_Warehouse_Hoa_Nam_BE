<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility bridge for pre-SKU-rename code/tests.
 *
 * `sku` remains the only persisted source of truth. `products` is an updatable
 * single-table view exposing the legacy column names id/code/name while all
 * inventory foreign keys continue to target sku.sku_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sku')) {
            throw new RuntimeException('Cannot create products compatibility view: canonical sku table is missing.');
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $relation = DB::selectOne(<<<'SQL'
                SELECT c.relkind
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relname = 'products'
                LIMIT 1
            SQL);

            if ($relation !== null && in_array($relation->relkind, ['r', 'p'], true)) {
                throw new RuntimeException(
                    'Both canonical sku and a persisted products table exist. Refusing to create a compatibility view because that would hide a split source of truth.'
                );
            }

            if ($relation !== null && $relation->relkind === 'v') {
                DB::statement('DROP VIEW products');
            }
        } elseif (Schema::hasTable('products')) {
            // Non-Postgres safety guard: never overwrite a persisted legacy table.
            throw new RuntimeException(
                'Canonical sku exists together with persisted products. Resolve the legacy table before enabling the compatibility view.'
            );
        }

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
                power_or_voltage,
                package_variant,
                version_code,
                warranty_applicable,
                specification,
                published_to_app,
                status,
                created_at,
                updated_at
            FROM sku
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS products');
    }
};
