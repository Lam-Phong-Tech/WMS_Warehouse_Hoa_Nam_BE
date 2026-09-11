<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nội dung file export báo cáo lưu TRONG DATABASE thay vì ổ đĩa của từng tiến trình
 * (11/09/2026).
 *
 * Triệu chứng trên khohoanamfe.lptech.info.vn — BE đã có bản vá 7cbc837: job export chạy
 * xong READY nhưng bấm tải về luôn nhận 404 "Không tìm thấy file export (có thể đã bị dọn
 * dẹp)", còn stack local thì chạy bình thường. File do một tiến trình KHÁC ghi (container
 * `queue`, hoặc một instance backend khác) vào ổ đĩa riêng của nó; tiến trình phục vụ tải
 * về không nhìn thấy ổ đó. Local không lộ vì compose dev bind-mount CÙNG một thư mục cho mọi
 * container.
 *
 * Database là nơi CHẮC CHẮN dùng chung giữa mọi tiến trình: trạng thái READY mà backend đọc
 * được chính là do worker ghi vào bảng `report_export_jobs`. Lưu nội dung file cạnh đó thì
 * tải về đúng ở mọi topology (nhiều container, nhiều instance, container bị dựng lại) mà
 * không phải cấu hình ổ dùng chung trên máy chủ.
 *
 * Bảng riêng, không thêm cột vào `report_export_jobs`: các lần poll trạng thái đọc cả dòng
 * job và không được kéo theo vài MB dữ liệu. Base64 trong `longText` thay vì `binary`: PDO
 * pgsql gửi tham số chuỗi dưới dạng text, byte không hợp lệ UTF-8 của XLSX/PDF làm hỏng câu
 * INSERT vào cột bytea.
 *
 * KHÔNG phải thực thể ERD — cùng loại bảng vận hành với `report_export_jobs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_export_files')) {
            return;
        }

        Schema::create('report_export_files', function (Blueprint $table) {
            $table->uuid('export_job_id')->primary();
            $table->longText('content_base64');
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->foreign('export_job_id')
                ->references('export_job_id')
                ->on('report_export_jobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_export_files');
    }
};
