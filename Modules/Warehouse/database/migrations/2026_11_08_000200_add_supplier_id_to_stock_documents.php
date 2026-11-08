<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->uuid('supplier_id')->nullable()->after('dst_warehouse_id');
            $table->index('supplier_id', 'idx_sd_supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->dropIndex('idx_sd_supplier_id');
            $table->dropColumn('supplier_id');
        });
    }
};
