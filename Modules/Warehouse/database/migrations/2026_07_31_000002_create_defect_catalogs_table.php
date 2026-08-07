<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục bệnh/lỗi máy dùng cho bảo hành và báo cáo (docs/HoaNam_ERD_v1.0.md §defect_catalog).
 * INACTIVE vẫn giữ lịch sử — không hard-delete khi đã có warranty_case tham chiếu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defect_catalogs', function (Blueprint $table) {
            $table->uuid('defect_id')->primary();
            $table->string('defect_code', 50)->unique();
            $table->string('defect_name', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_catalogs');
    }
};
