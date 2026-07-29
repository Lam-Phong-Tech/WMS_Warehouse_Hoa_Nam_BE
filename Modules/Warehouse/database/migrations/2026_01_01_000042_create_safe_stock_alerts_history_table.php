<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only — chỉ ghi khi trạng thái cảnh báo (normal/low_stock/overflow) thực sự đổi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_stock_alerts_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('current_qty', 18, 3);
            $table->decimal('min_qty', 18, 3)->nullable();
            $table->decimal('max_qty', 18, 3)->nullable();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'warehouse_id'], 'idx_ssah_product_warehouse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_stock_alerts_history');
    }
};
