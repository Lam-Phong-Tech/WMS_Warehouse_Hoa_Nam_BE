<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative append-only ledger. Model chặn update/delete ở tầng Eloquent (boot()).
 * `business_key` unique để chống ghi trùng khi StockLedger được gọi lại (idempotent theo
 * document+line+action). Reversal tham chiếu `original_movement_id`, không sửa dòng gốc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('movement_no', 40)->unique();
            $table->string('business_key', 180)->unique();
            $table->string('movement_type', 30); // RECEIPT_IN | ISSUE_OUT | ADJUST_IN | ADJUST_OUT | RECEIPT_REVERSED | ISSUE_REVERSED
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->string('stock_status', 30)->default('AVAILABLE')->index();
            $table->decimal('qty_before', 18, 3);
            $table->decimal('qty_delta', 18, 3); // dương = tăng, âm = giảm
            $table->decimal('qty_after', 18, 3);
            $table->foreignUuid('stock_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->foreignUuid('stock_document_line_id')->nullable()->constrained('stock_document_lines')->nullOnDelete();
            // Không đặt FK cứng vào chính bảng này (self-reference) để tránh phụ thuộc thứ tự
            // constraint lúc CREATE TABLE trên Postgres — base chưa dùng reversal, cột này để
            // sẵn chỗ cho dev sau (BP-WMS-10) validate ở tầng ứng dụng thay vì DB constraint.
            $table->uuid('original_movement_id')->nullable();
            $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['warehouse_id', 'product_id', 'occurred_at'], 'idx_sm_wh_product_time');
            $table->index(['stock_document_id'], 'idx_sm_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
