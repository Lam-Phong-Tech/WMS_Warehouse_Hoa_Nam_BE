<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-224 — Import báo cáo: lưu kết quả mỗi lần chạy import SKU (dry-run hoặc commit)
 * để tra cứu/đối soát về sau, không phụ thuộc vào response API tức thời.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_reports', function (Blueprint $table) {
            $table->uuid('import_report_id')->primary();
            $table->string('import_type', 30)->default('SKU');
            $table->string('mode', 10);
            $table->string('status', 20);
            $table->string('file_name', 255)->nullable();
            $table->string('file_sha256', 64);
            $table->string('template_version', 60)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->json('row_errors')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->index('file_sha256', 'idx_import_reports_sha256');
            $table->index(['import_type', 'mode', 'created_at'], 'idx_import_reports_type_mode_created');
            $table->index('actor_id', 'idx_import_reports_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_reports');
    }
};
