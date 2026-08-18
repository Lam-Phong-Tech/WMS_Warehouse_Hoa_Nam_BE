<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ chi tiết hàng đang vận chuyển khi điều chuyển kho — 1 dòng / stock_document_line.
 * Base này KHÔNG có bucket tồn "in_transit" riêng trên inventory_balances (đơn giản hoá so
 * với ERP-Backend gốc): qty được trừ khỏi kho nguồn ngay lúc dispatch (TRANSFER_OUT) và chỉ
 * cộng vào kho đích khi receive (TRANSFER_IN) thực sự xảy ra — bảng này chỉ để truy vết,
 * không phải nguồn tính tồn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_transit_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->foreignUuid('document_line_id')->constrained('stock_document_lines')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->foreignUuid('src_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('dst_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty_dispatched', 18, 3)->default(0);
            $table->decimal('qty_received', 18, 3)->default(0);
            $table->decimal('qty_return_in_transit', 18, 3)->default(0);
            $table->decimal('qty_returned', 18, 3)->default(0);
            $table->decimal('qty_lost', 18, 3)->default(0);
            $table->string('status', 20)->default('in_transit')->comment('in_transit|return_in_transit|completed|completed_with_loss|returned|cancelled');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->unique('document_line_id', 'uk_itl_document_line');
            $table->index(['src_warehouse_id', 'dst_warehouse_id'], 'idx_itl_src_dst');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('in_transit_ledger');
    }
};
