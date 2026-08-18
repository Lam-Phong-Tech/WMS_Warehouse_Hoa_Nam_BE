<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-310 (bàn giao dữ liệu) - đóng quan hệ tài liệu
 * `reversal_lines.original_movement_id -> stock_movements.id` và
 * `reversal_lines.posted_movement_id -> stock_movements.id`
 * (docs/contracts/HoaNam_Current_Baseline_Contract.json, ERD bảng 34).
 *
 * Hiện trạng: hai cột này chỉ có FK trỏ về `inventory_movements` - bảng MIRROR do
 * trigger `trg_erd_mirror_inventory_movements_from_stock_movements` sinh ra bằng
 * `INSERT ... ON CONFLICT (id) DO NOTHING`. Ledger authoritative mà ứng dụng ghi
 * vào là `stock_movements` (DEC-INV-001). Ràng buộc chỉ neo vào mirror nghĩa là
 * tính toàn vẹn tham chiếu của chuỗi reversal phụ thuộc một tầng phái sinh: mirror
 * bỏ sót hoặc bị chặn thì dòng reversal vẫn "hợp lệ" về mặt DB trong khi movement
 * gốc không tồn tại trong ledger thật.
 *
 * Mirror giữ nguyên `id`, nên FK canonical thêm vào đây thoả mãn với toàn bộ dữ
 * liệu hiện có; hai FK cũ về mirror được giữ lại để không nới lỏng ràng buộc nào.
 */
return new class extends Migration
{
    private const RELATIONSHIPS = [
        ['original_movement_id', 'fk_canonical_reversal_lines_original_movement_id'],
        ['posted_movement_id', 'fk_canonical_reversal_lines_posted_movement_id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (! Schema::hasTable('reversal_lines') || ! Schema::hasTable('stock_movements')) {
            return;
        }

        foreach (self::RELATIONSHIPS as [$column, $constraint]) {
            if (! Schema::hasColumn('reversal_lines', $column)) {
                continue;
            }

            DB::statement("ALTER TABLE reversal_lines DROP CONSTRAINT IF EXISTS {$constraint}");

            $orphans = (int) DB::scalar(
                "SELECT COUNT(*) FROM reversal_lines line
                 LEFT JOIN stock_movements movement ON movement.id = line.{$column}
                 WHERE line.{$column} IS NOT NULL AND movement.id IS NULL"
            );

            // Fail-safe cho cutover: dữ liệu lịch sử lệch không được làm hỏng lần
            // migrate, nhưng dữ liệu mới vẫn bị chặn ngay bằng NOT VALID.
            DB::statement(
                "ALTER TABLE reversal_lines ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) ".
                'REFERENCES stock_movements(id) ON DELETE RESTRICT'.($orphans > 0 ? ' NOT VALID' : '')
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('reversal_lines')) {
            return;
        }

        foreach (self::RELATIONSHIPS as [, $constraint]) {
            DB::statement("ALTER TABLE reversal_lines DROP CONSTRAINT IF EXISTS {$constraint}");
        }
    }
};
