<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * assigned_object_type là cột legacy-compat (không thuộc ERD bảng 21 — canonical chỉ
 * có item_id/container_id <=1). Contract WWHN-172 lưu SKU-level barcode qua legacy view
 * `physical_code` với assigned_object_type='SKU'; CHECK cũ chỉ cho ITEM/CONTAINER làm
 * mirror trigger nổ 23514 và giết luôn SKU aggregate create. Canonical invariant
 * chk_physical_codes_target_xor (item/container <=1) giữ nguyên, không đổi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('physical_codes')) {
            return;
        }

        DB::statement('ALTER TABLE physical_codes DROP CONSTRAINT IF EXISTS chk_physical_code_target_type');
        DB::statement("ALTER TABLE physical_codes ADD CONSTRAINT chk_physical_code_target_type CHECK (assigned_object_type IS NULL OR assigned_object_type IN ('ITEM','CONTAINER','SKU'))");
    }

    public function down(): void
    {
        // Fail-forward signed ERD business constraints.
    }
};
