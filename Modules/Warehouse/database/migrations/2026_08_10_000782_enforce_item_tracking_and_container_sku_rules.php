<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_item_serial_per_sku ON item_instances(sku_id, serial_number) WHERE serial_number IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_item_tracking_policy()
            RETURNS trigger AS $$
            DECLARE
                tracking varchar(20);
            BEGIN
                SELECT tracking_mode INTO tracking FROM skus WHERE id=NEW.sku_id;
                IF tracking IS NULL THEN
                    RAISE EXCEPTION 'SKU_NOT_FOUND: item_instances.sku_id must resolve to canonical skus.id';
                END IF;
                IF tracking='SERIAL' AND (NEW.serial_number IS NULL OR btrim(NEW.serial_number)='') THEN
                    RAISE EXCEPTION 'SERIAL_REQUIRED: SERIAL tracking SKU requires item_instances.serial_number';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_item_tracking_policy ON item_instances');
        DB::statement('CREATE TRIGGER trg_erd_item_tracking_policy BEFORE INSERT OR UPDATE OF sku_id, serial_number ON item_instances FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_item_tracking_policy()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_container_item_primary_sku()
            RETURNS trigger AS $$
            DECLARE
                parent_sku uuid;
            BEGIN
                IF NEW.item_id IS NULL AND NEW.sku_id IS NOT NULL THEN
                    SELECT primary_sku_id INTO parent_sku FROM containers WHERE id=NEW.container_id;
                    IF parent_sku IS NULL THEN
                        RAISE EXCEPTION 'PRIMARY_SKU_REQUIRED: quantity container mapping requires containers.primary_sku_id';
                    END IF;
                    IF parent_sku <> NEW.sku_id THEN
                        RAISE EXCEPTION 'WRONG_SKU: quantity container mapping sku_id must equal containers.primary_sku_id';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_container_item_primary_sku ON container_items');
        DB::statement('CREATE TRIGGER trg_erd_container_item_primary_sku BEFORE INSERT OR UPDATE OF container_id, item_id, sku_id, quantity ON container_items FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_container_item_primary_sku()');
    }

    public function down(): void
    {
        // Fail-forward signed ERD business constraints.
    }
};
