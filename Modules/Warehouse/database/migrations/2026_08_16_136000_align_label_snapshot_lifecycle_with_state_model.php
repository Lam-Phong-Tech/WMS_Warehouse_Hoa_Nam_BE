<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS v1.12 §6 State Model — Packing Label:
 * DRAFT → PREVIEWED → READY_TO_PRINT → PRINTED (PRINT_FAILED khi in lỗi).
 *
 * CHECK cũ chỉ cho PENDING_PRINT/PRINTED/PRINT_FAILED nên 3 state trung gian của
 * §6 không tồn tại được và endpoint preview/ready theo Annex C không thể triển
 * khai. Giữ PENDING_PRINT để dữ liệu/label đã tạo trước đó vẫn hợp lệ.
 */
return new class extends Migration
{
    private const STATUSES = "'DRAFT','PREVIEWED','READY_TO_PRINT','PENDING_PRINT','PRINTED','PRINT_FAILED'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['label_snapshots' => 'chk_label_snapshot_current_status', 'label_print_events' => 'chk_label_print_current_status'] as $table => $constraint) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (status IN (".self::STATUSES.'))');
        }

        // Ràng buộc ERD sinh tự động cho cùng cột (nếu có) cũng phải nới theo.
        foreach (DB::select(<<<'SQL'
            SELECT conrelid::regclass::text AS table_name, conname
              FROM pg_constraint
             WHERE contype = 'c'
               AND conrelid IN ('label_snapshots'::regclass, 'label_print_events'::regclass)
               AND pg_get_constraintdef(oid) LIKE '%PENDING_PRINT%'
        SQL) as $row) {
            DB::statement("ALTER TABLE {$row->table_name} DROP CONSTRAINT IF EXISTS {$row->conname}");
            DB::statement("ALTER TABLE {$row->table_name} ADD CONSTRAINT {$row->conname} CHECK (status IN (".self::STATUSES.'))');
        }
    }

    public function down(): void
    {
        // Fail-forward.
    }
};
