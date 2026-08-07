<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guard an toàn cho BE2-P1-04: migration 2026_01_01_000031_create_products_table.php đã bị sửa
 * tại chỗ từ `Schema::create('products', ...)` sang `Schema::create('sku', ...)` thay vì có một
 * migration ALTER/RENAME riêng. Trên môi trường đã `migrate` với bản `products` cũ, Laravel coi
 * migration đó là "đã chạy" nên sẽ KHÔNG tự áp dụng lại schema `sku` mới — cần `migrate:fresh`
 * hoặc guard này để nâng cấp tại chỗ mà không mất dữ liệu.
 *
 * up() chỉ hành động khi phát hiện đúng tình huống "còn kẹt ở schema cũ": có bảng `products`
 * nhưng chưa có bảng `sku`. Nếu `sku` đã tồn tại (fresh install hoặc đã migrate:fresh) thì no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sku') || ! Schema::hasTable('products')) {
            return;
        }

        DB::transaction(function () {
            Schema::rename('products', 'sku');

            Schema::table('sku', function (Blueprint $table) {
                $table->renameColumn('id', 'sku_id');
                $table->renameColumn('code', 'sku_code');
                $table->renameColumn('name', 'sku_name');
            });

            Schema::table('sku', function (Blueprint $table) {
                $table->foreignUuid('brand_id')->nullable()->after('unit')->constrained('product_masters', 'master_id')->nullOnDelete();
                $table->foreignUuid('category_id')->nullable()->after('brand_id')->constrained('product_masters', 'master_id')->nullOnDelete();
                $table->foreignUuid('model_id')->nullable()->after('category_id')->constrained('product_masters', 'master_id')->nullOnDelete();
                $table->string('power_or_voltage', 50)->nullable()->after('model_id');
                $table->string('package_variant', 50)->nullable()->after('power_or_voltage');
                $table->string('version_code', 30)->default('V1')->after('package_variant');
                $table->boolean('warranty_applicable')->default(false)->after('version_code');
                $table->json('specification')->nullable()->after('warranty_applicable');
                $table->boolean('published_to_app')->default(false)->after('specification');
            });
        });
    }

    public function down(): void
    {
        // Không revert: sau khi nâng cấp tại chỗ có thể đã phát sinh dữ liệu ở các cột mới
        // (brand_id/category_id/...), hạ ngược sẽ mất dữ liệu. An toàn hơn là no-op có ghi chú.
    }
};
