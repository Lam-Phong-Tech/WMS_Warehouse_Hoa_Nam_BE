<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GĐ1-REQ-010: `estimated_completion_date` là cột canonical duy nhất.
 *
 * `expected_completion_date` (thêm ở 2026_08_25_130000) chỉ còn là alias API —
 * BaM1WarrantyController vẫn nhận input `expected_*` và map sang `estimated_*`,
 * nhưng persistence không được giữ hai cột cùng nghĩa: row cũ mang giá trị
 * `expected_*` lỗi thời từng làm màn danh sách hiển thị ngày khác màn chi tiết.
 * Backfill lần cuối (phòng dữ liệu ghi vào giữa 170000 và migration này) rồi drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warranty_case')
            || ! Schema::hasColumn('warranty_case', 'expected_completion_date')) {
            return;
        }

        if (Schema::hasColumn('warranty_case', 'estimated_completion_date')) {
            DB::table('warranty_case')
                ->whereNull('estimated_completion_date')
                ->whereNotNull('expected_completion_date')
                ->update(['estimated_completion_date' => DB::raw('expected_completion_date')]);
        }

        Schema::table('warranty_case', function (Blueprint $table): void {
            $table->dropColumn('expected_completion_date');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('warranty_case')
            && ! Schema::hasColumn('warranty_case', 'expected_completion_date')) {
            Schema::table('warranty_case', function (Blueprint $table): void {
                $table->date('expected_completion_date')->nullable();
            });
        }
    }
};
