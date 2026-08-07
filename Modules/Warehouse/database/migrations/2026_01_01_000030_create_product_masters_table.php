<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục chuẩn hóa BRAND/CATEGORY/MODEL/PACKAGE/POWER dùng làm brand_id/category_id/model_id
 * cho bảng sku (docs/ERD/HoaNam_ERD_v1.0.md §5.1 product_master).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_masters', function (Blueprint $table) {
            $table->uuid('master_id')->primary();
            $table->string('master_type', 20); // BRAND | CATEGORY | MODEL | PACKAGE | POWER
            $table->string('code', 80);
            $table->string('name', 200);
            $table->uuid('parent_master_id')->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();

            $table->unique(['master_type', 'code'], 'uk_product_masters_type_code');
            $table->index('master_type', 'idx_product_masters_type');
        });

        // FK tự tham chiếu thêm sau khi bảng đã tồn tại (Postgres cần PK đã commit trước).
        Schema::table('product_masters', function (Blueprint $table) {
            $table->foreign('parent_master_id')->references('master_id')->on('product_masters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_masters');
    }
};
