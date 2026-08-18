<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-260 / DEV260-P1-04.
 *
 * SRS §13.12 định nghĩa tiêu chí dry-run PASS là "valid + invalid + duplicate =
 * input row count", và Data Dictionary (§14, dòng `Import_Batch.valid/invalid/
 * duplicate_count`) yêu cầu lưu cả ba con số. ERD bảng 58 `migration_runs` mới
 * chỉ có `total_rows`/`valid_rows`/`error_rows`, nên số dòng trùng bị gộp vào
 * `error_rows` và bất biến ba số không kiểm chứng lại được từ dữ liệu đã lưu.
 *
 * Cột này là bổ sung additive theo ERD-AMD-007.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('migration_runs') || Schema::hasColumn('migration_runs', 'duplicate_rows')) {
            return;
        }

        Schema::table('migration_runs', function (Blueprint $blueprint): void {
            $blueprint->integer('duplicate_rows')->default(0);
        });

        DB::table('migration_runs')->whereNull('duplicate_rows')->update(['duplicate_rows' => 0]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE migration_runs ALTER COLUMN duplicate_rows SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('migration_runs') && Schema::hasColumn('migration_runs', 'duplicate_rows')) {
            Schema::table('migration_runs', fn (Blueprint $blueprint) => $blueprint->dropColumn('duplicate_rows'));
        }
    }
};
