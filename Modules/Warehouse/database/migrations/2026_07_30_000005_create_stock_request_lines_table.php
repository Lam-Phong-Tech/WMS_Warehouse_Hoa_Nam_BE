<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_request_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->decimal('qty_planned', 18, 3);
            $table->decimal('unit_cost', 18, 3)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'line_no'], 'uk_stock_request_lines_request_line');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('stock_request_lines');
    }
};
