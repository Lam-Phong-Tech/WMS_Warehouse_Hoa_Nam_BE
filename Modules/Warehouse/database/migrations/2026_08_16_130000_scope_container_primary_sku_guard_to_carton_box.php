<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ERD bảng 22: containers.primary_sku_id nullable ("SKU chính; BOX quantity nên có"),
 * TRAY là locator. OpenAPI ContainerController cũng chỉ bắt buộc primary_sku_id với
 * CARTON/BOX. Trigger cũ ép PRIMARY_SKU_REQUIRED cho MỌI quantity mapping khiến
 * putaway linh kiện vào TRAY (PTW-01) bị chặn sai — thu hẹp guard về CARTON/BOX.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_container_item_primary_sku()
            RETURNS trigger AS $$
            DECLARE
                parent_sku uuid;
                parent_type varchar(20);
            BEGIN
                IF NEW.item_id IS NULL AND NEW.sku_id IS NOT NULL THEN
                    SELECT primary_sku_id, container_type INTO parent_sku, parent_type
                      FROM containers WHERE container_id=NEW.container_id;
                    IF parent_type IN ('CARTON', 'BOX') THEN
                        IF parent_sku IS NULL THEN
                            RAISE EXCEPTION 'PRIMARY_SKU_REQUIRED: quantity container mapping requires containers.primary_sku_id';
                        END IF;
                        IF parent_sku <> NEW.sku_id THEN
                            RAISE EXCEPTION 'WRONG_SKU: quantity container mapping sku_id must equal containers.primary_sku_id';
                        END IF;
                    ELSIF parent_sku IS NOT NULL AND parent_sku <> NEW.sku_id THEN
                        RAISE EXCEPTION 'WRONG_SKU: quantity container mapping sku_id must equal containers.primary_sku_id';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward signed ERD business constraints.
    }
};
