<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapping item hoặc quantity SKU nằm trong container (ERD §5.2 container_item). Rule XOR
 * (item_id vs sku_id+quantity) enforce ở FormRequest, không dùng DB CHECK để giữ tương thích
 * SQLite (test suite dùng SQLite in-memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_items', function (Blueprint $table) {
            $table->uuid('container_item_id')->primary();
            $table->foreignUuid('container_id')->constrained('containers', 'container_id')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('item_instances', 'item_id')->restrictOnDelete();
            $table->foreignUuid('sku_id')->nullable()->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->nullable()->default(0);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | REMOVED
            $table->timestamps();

            $table->index('status', 'idx_container_items_status');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('container_items');
    }
};
