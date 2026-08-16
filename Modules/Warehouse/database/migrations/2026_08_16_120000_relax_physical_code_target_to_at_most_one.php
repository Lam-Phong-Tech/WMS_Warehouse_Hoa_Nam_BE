<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SRS/FRD v1.12 §7.3 chốt cardinality Physical_Code – Item/Container = 1–0..1 và
 * §13.9 định nghĩa lifecycle UNASSIGNED (code đã validate nhưng chưa gắn target).
 * Hai CHECK cũ ép "đúng một target" (=1) khiến không thể tồn tại code UNASSIGNED,
 * làm gãy luồng production tạo container (code tạo trước, gán container_id sau).
 * Nới thành "tối đa một target" (<=1): vẫn cấm map đồng thời item + container.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE physical_codes DROP CONSTRAINT IF EXISTS chk_physical_codes_target_xor');
        DB::statement('ALTER TABLE physical_codes ADD CONSTRAINT chk_physical_codes_target_xor CHECK ((item_id IS NOT NULL)::int + (container_id IS NOT NULL)::int <= 1)');

        DB::statement('ALTER TABLE physical_codes DROP CONSTRAINT IF EXISTS chk_erd_physical_codes_2');
        DB::statement('ALTER TABLE physical_codes ADD CONSTRAINT chk_erd_physical_codes_2 CHECK (((item_id IS NOT NULL)::integer + (container_id IS NOT NULL)::integer) <= 1)');
    }

    public function down(): void
    {
        // Fail-forward: không quay lại ràng buộc "đúng một target" đã bị SRS v1.12 thay thế.
    }
};
