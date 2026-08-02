<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projection tồn hiện tại — KHÔNG phải nguồn sự thật (đó là stock_movements, append-only).
 * `version` dùng cho optimistic lock khi StockLedger cập nhật.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->decimal('on_hand_qty', 18, 3)->default(0);
            $table->decimal('available_qty', 18, 3)->default(0);
            $table->decimal('reserved_qty', 18, 3)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id'], 'uk_inventory_balances_wh_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
