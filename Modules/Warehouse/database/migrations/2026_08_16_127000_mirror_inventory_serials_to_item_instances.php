<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD bảng 20: item_instances là serial subledger canonical; bảng runtime legacy
 * inventory_serials (luồng warranty cũ) đứng cô lập nên warranty_cases.item_id
 * (FK item_instances) không thể trỏ serial tạo qua đường legacy → FK violation.
 * Mirror inventory_serials → item_instances (id dùng chung) như các compat layer
 * khác, kèm backfill. physical_code_id bên legacy là varchar — chỉ cast khi là
 * UUID hợp lệ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('inventory_serials')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_mirror_inventory_serial_to_item_instances() RETURNS trigger AS $$
            BEGIN
                INSERT INTO item_instances (
                    id, item_id, item_code, sku_id, serial_number, physical_code_id,
                    item_status, status, item_condition, condition, version, created_at, updated_at
                )
                VALUES (
                    NEW.id, NEW.id, COALESCE(NEW.serial_no, NEW.id::text), NEW.product_id,
                    NEW.serial_no,
                    CASE WHEN NEW.physical_code_id::text ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                         THEN NEW.physical_code_id::uuid END,
                    COALESCE(NEW.status, 'NEW'), COALESCE(NEW.status, 'NEW'),
                    CASE WHEN NEW.item_condition IN ('NORMAL','DEMO','DEFECTIVE','WARRANTY_RETURN','NON_SELLABLE') THEN NEW.item_condition ELSE 'NORMAL' END,
                    CASE WHEN NEW.condition IN ('NORMAL','DEFECTIVE','NON_SELLABLE','UNKNOWN') THEN NEW.condition ELSE 'NORMAL' END,
                    0, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
                )
                ON CONFLICT (id) DO UPDATE SET
                    sku_id = EXCLUDED.sku_id,
                    serial_number = EXCLUDED.serial_number,
                    physical_code_id = EXCLUDED.physical_code_id,
                    item_status = EXCLUDED.item_status,
                    status = EXCLUDED.status,
                    item_condition = EXCLUDED.item_condition,
                    condition = EXCLUDED.condition,
                    updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_hn_mirror_inventory_serial ON inventory_serials');
        DB::statement('CREATE TRIGGER trg_hn_mirror_inventory_serial AFTER INSERT OR UPDATE ON inventory_serials FOR EACH ROW EXECUTE FUNCTION hn_mirror_inventory_serial_to_item_instances()');

        // Backfill serial legacy đã tồn tại trước trigger.
        DB::statement(<<<'SQL'
            INSERT INTO item_instances (
                id, item_id, item_code, sku_id, serial_number, physical_code_id,
                item_status, status, item_condition, condition, version, created_at, updated_at
            )
            SELECT s.id, s.id, COALESCE(s.serial_no, s.id::text), s.product_id,
                   s.serial_no,
                   CASE WHEN s.physical_code_id::text ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                        THEN s.physical_code_id::uuid END,
                   COALESCE(s.status, 'NEW'), COALESCE(s.status, 'NEW'),
                   CASE WHEN s.item_condition IN ('NORMAL','DEMO','DEFECTIVE','WARRANTY_RETURN','NON_SELLABLE') THEN s.item_condition ELSE 'NORMAL' END,
                   CASE WHEN s.condition IN ('NORMAL','DEFECTIVE','NON_SELLABLE','UNKNOWN') THEN s.condition ELSE 'NORMAL' END,
                   0, COALESCE(s.created_at, CURRENT_TIMESTAMP), COALESCE(s.updated_at, CURRENT_TIMESTAMP)
              FROM inventory_serials s
            ON CONFLICT (id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // Fail-forward.
    }
};
