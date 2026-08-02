<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->decimal('qty_planned', 18, 3)->default(0);
            $table->decimal('qty_actual', 18, 3)->default(0);
            $table->decimal('scanned_qty', 18, 3)->default(0); // luỹ kế đã scan qua các lần gọi /scan
            $table->string('completion_type', 20)->default('full'); // full | shortage | loss
            $table->string('shortage_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['stock_document_id', 'line_no'], 'uk_sdl_document_line_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_lines');
    }
};
