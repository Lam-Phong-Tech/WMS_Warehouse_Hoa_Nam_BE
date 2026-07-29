<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_request_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_planned', 18, 3)->default(0);
            $table->string('note', 1000)->nullable();
            $table->timestamps();

            $table->unique(['stock_request_id', 'line_no'], 'uk_srl_request_line_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_lines');
    }
};
