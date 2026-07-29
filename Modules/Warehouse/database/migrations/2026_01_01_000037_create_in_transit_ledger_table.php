<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Theo dõi hàng đang trên đường điều chuyển giữa 2 kho (1 dòng / stock_document_line). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_transit_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->foreignUuid('stock_document_line_id')->constrained('stock_document_lines')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('src_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('dst_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty_dispatched', 18, 3)->default(0);
            $table->decimal('qty_received', 18, 3)->default(0);
            $table->decimal('qty_returned', 18, 3)->default(0);
            $table->decimal('qty_return_in_transit', 18, 3)->default(0);
            $table->decimal('qty_lost', 18, 3)->default(0);
            // in_transit | partially_received | completed | completed_with_loss | return_in_transit | returned
            $table->string('status', 30)->default('in_transit');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->unique('stock_document_line_id', 'uk_itl_document_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_transit_ledger');
    }
};
