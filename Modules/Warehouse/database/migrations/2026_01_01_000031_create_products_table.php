<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stub SKU tối giản nội bộ module (KHÔNG phụ thuộc Modules\Order\Entities\Product của
 * ERP-Backend gốc). Đã có sẵn is_serial_tracked/sku_type để khi nâng cấp theo
 * docs/HoaNam_ERD_v1.0.md chỉ cần đổi tên bảng/model thành `sku` + bổ sung field
 * (sku_code chuẩn, package_variant, brand/category/model_id...), không phải viết lại quan hệ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('sku_code', 100)->nullable()->unique();
            $table->string('name', 255);
            $table->string('unit', 30)->default('cái');
            $table->string('sku_type', 20)->default('PRODUCT'); // PRODUCT | COMPONENT
            $table->string('brand_code', 100)->nullable()->index();
            $table->string('category_code', 100)->nullable()->index();
            $table->string('model_code', 100)->nullable()->index();
            $table->string('power_or_voltage', 100)->nullable();
            $table->string('package_variant', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('published_to_app')->default(false);
            $table->boolean('is_serial_tracked')->default(false);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
