<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Định mức tồn kho an toàn (min/max) theo sản phẩm, tùy chọn theo kho cụ thể (null = toàn hệ thống). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_stock_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('min_qty', 18, 3)->nullable();
            $table->decimal('max_qty', 18, 3)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id'], 'idx_ssc_product_warehouse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_stock_configs');
    }
};
