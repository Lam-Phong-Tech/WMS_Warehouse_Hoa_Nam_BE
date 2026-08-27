<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hạng mục "Hiệu năng và triển khai" (checklist khách hàng 27/08): `POST /reports/export`
 * trước đây trả `status=READY` ngay lập tức và toàn bộ query + dựng file XLSX/PDF chạy
 * đồng bộ trong request khi FE gọi `download_url` — đây là bảng theo dõi job nền THẬT,
 * thay thế cho `ExportJob` giả trước đó.
 *
 * KHÔNG phải thực thể ERD (cùng loại bảng vận hành với `import_reports`/`sku_import_claims`
 * — xem DEC-IMP-001 §5): job export là trạng thái tạm thời của một request, không phải
 * dữ liệu nghiệp vụ cần mô hình hoá trong ERD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_export_jobs')) {
            return;
        }

        Schema::create('report_export_jobs', function (Blueprint $table) {
            $table->uuid('export_job_id')->primary();
            $table->string('report_code', 64);
            $table->string('format', 8);
            $table->json('filters');
            $table->uuid('requested_by')->nullable();
            // PENDING (vừa tạo, chờ worker) | PROCESSING (worker đang chạy) |
            // READY (file đã sẵn sàng tải) | FAILED (worker lỗi, xem error_code/error_message).
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('requested_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_export_jobs');
    }
};
