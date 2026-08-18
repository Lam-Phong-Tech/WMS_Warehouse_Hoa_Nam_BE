<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng `sku` theo docs/ERD/HoaNam_ERD_v1.0.md §5.1 (sku_id/sku_code/sku_name...). Đổi tên từ
 * bảng `products` stub ban đầu (WWHN-37) — 10 bảng khác vẫn FK vào cột `product_id` như cũ,
 * chỉ trỏ lại constraint sang `sku(sku_id)` để không phá luồng nhập/xuất/tồn kho đang có
 * (xem docs/output/BE2-HN-MC-001-sku-item-component/00-summary.md mục "Quyết định thiết kế").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku', function (Blueprint $table) {
            $table->uuid('sku_id')->primary();
            $table->string('sku_code', 100)->unique();
            $table->string('sku_name', 255);
            $table->string('sku_type', 20)->default('PRODUCT'); // PRODUCT | COMPONENT
            $table->string('unit', 30)->default('cái');
            $table->boolean('is_serial_tracked')->default(false);
            $table->foreignUuid('brand_id')->nullable()->constrained('product_masters', 'master_id')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('product_masters', 'master_id')->nullOnDelete();
            $table->foreignUuid('model_id')->nullable()->constrained('product_masters', 'master_id')->nullOnDelete();
            $table->string('power_or_voltage', 50)->nullable();
            $table->string('package_variant', 50)->nullable(); // BARE | KIT | SET | BODY_ONLY
            $table->string('version_code', 30)->default('V1');
            $table->boolean('warranty_applicable')->default(false);
            $table->json('specification')->nullable();
            $table->boolean('published_to_app')->default(false);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('sku');
    }
};
