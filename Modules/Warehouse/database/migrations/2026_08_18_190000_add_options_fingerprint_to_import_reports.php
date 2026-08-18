<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-331 (FE331-P1-02R3) — `update_existing` là một phần ĐỊNH DANH của lần import.
 *
 * Trước đây replay chỉ so `file_sha256 + actor_id + cửa sổ 24h`, nên cùng một file nộp
 * lại với `update_existing` khác hẳn (false = chỉ tạo mới; true = cho phép ghi đè SKU
 * đang tồn tại) bị trả về kết quả cũ và KHÔNG chạy — nuốt mất một thao tác người dùng
 * thực sự muốn thực hiện. Cột này lưu vân tay các tùy chọn đó để so đủ định danh.
 *
 * NULL = bản ghi có trước thay đổi này (không biết tùy chọn lúc đó); ImportService xử
 * lý NULL theo hướng bảo toàn hành vi cũ, xem findRecentDuplicateReport().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_reports') || Schema::hasColumn('import_reports', 'options_fingerprint')) {
            return;
        }

        Schema::table('import_reports', function (Blueprint $table) {
            $table->string('options_fingerprint', 64)->nullable()->after('duplicate_of_replay');
            $table->index(['import_type', 'file_sha256', 'actor_id'], 'idx_import_reports_replay_lookup');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('import_reports') || ! Schema::hasColumn('import_reports', 'options_fingerprint')) {
            return;
        }

        Schema::table('import_reports', function (Blueprint $table) {
            $table->dropIndex('idx_import_reports_replay_lookup');
            $table->dropColumn('options_fingerprint');
        });
    }
};
