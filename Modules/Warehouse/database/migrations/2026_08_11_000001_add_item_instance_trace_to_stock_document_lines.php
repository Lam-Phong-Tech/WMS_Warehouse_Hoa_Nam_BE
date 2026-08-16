<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->foreignUuid('item_instance_id')
                ->nullable()
                ->after('product_id')
                ->constrained('item_instances', 'item_id')
                ->restrictOnDelete();
            $table->index('item_instance_id', 'idx_sdl_item_instance');
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->dropForeign(['item_instance_id']);
            $table->dropIndex('idx_sdl_item_instance');
            $table->dropColumn('item_instance_id');
        });
    }
};
