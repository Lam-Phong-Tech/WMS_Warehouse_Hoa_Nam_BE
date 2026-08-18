<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-330 (DEV330-P1-01/03) — thêm khả năng tra cứu file lỗi (CSV) tải xuống theo
 * report, có expiry rõ ràng, và metadata "trùng lặp" (idempotent replay) khi resubmit
 * cùng một file (theo file_sha256) cho commit thật.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_reports', function (Blueprint $table) {
            $table->string('error_file_path', 500)->nullable()->after('row_errors');
            $table->timestamp('error_file_expires_at')->nullable()->after('error_file_path');
            $table->boolean('duplicate_of_replay')->default(false)->after('error_file_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_reports', function (Blueprint $table) {
            $table->dropColumn(['error_file_path', 'error_file_expires_at', 'duplicate_of_replay']);
        });
    }
};
