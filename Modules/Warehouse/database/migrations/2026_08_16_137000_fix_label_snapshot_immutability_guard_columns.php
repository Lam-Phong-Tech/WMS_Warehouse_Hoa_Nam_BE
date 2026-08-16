<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUG: hn_erd_lock_label_snapshot_payload được viết cho shape label snapshot cũ
 * (label_code, customer_name, product_name, item_code_value, outbound_no,
 * template_version, payload…). Bảng canonical `label_snapshots` không có những
 * cột đó, nên trigger BEFORE UPDATE nổ 42703 "record old has no field label_code"
 * với MỌI lệnh UPDATE: in nhãn (dispatchLabel), xác nhận đã dán
 * (applyPackingLabel) và preview/ready đều 500 trên PostgreSQL — trong khi
 * label PRINTED + applied lại là completion gate của Post Issue (§5.2).
 *
 * Giữ nguyên ý định BR (snapshot bất biến sau khi chốt) nhưng khoá đúng các cột
 * nội dung thực tế; status, applied_at, applied_by, version và updated_at vẫn
 * được phép đổi vì đó chính là lifecycle §6:
 * DRAFT → PREVIEWED → READY_TO_PRINT → PRINTED → đã dán nhãn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_snapshots')) {
            return;
        }

        $immutable = array_values(array_filter(
            ['label_payload', 'outbound_document_id', 'outbound_item_match_id', 'sku_id', 'item_id'],
            fn (string $column): bool => Schema::hasColumn('label_snapshots', $column)
        ));

        $conditions = implode("\n                   OR ", array_map(
            fn (string $column): string => "OLD.{$column} IS DISTINCT FROM NEW.{$column}",
            $immutable
        ));

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION hn_erd_lock_label_snapshot_payload()
            RETURNS trigger AS \$\$
            BEGIN
                IF {$conditions} THEN
                    RAISE EXCEPTION 'LABEL_SNAPSHOT_IMMUTABLE: business payload cannot be changed after capture';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward.
    }
};
