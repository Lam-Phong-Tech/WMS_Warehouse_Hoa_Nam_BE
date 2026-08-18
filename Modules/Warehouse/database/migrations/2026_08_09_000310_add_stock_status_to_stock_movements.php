<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * StockMovement model/ledger always writes stock_status, but the original table
 * migration predates that dimension. Keep schema parity across PostgreSQL,
 * MySQL and SQLite instead of relying on environment-specific drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements') || Schema::hasColumn('stock_movements', 'stock_status')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('stock_status', 30)->default('AVAILABLE')->after('product_id');
            $table->index(
                ['warehouse_id', 'product_id', 'stock_status', 'occurred_at'],
                'idx_stock_movements_dimension_time'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'stock_status')) {
            return;
        }

        // WWHN-300: a later migration may already have replaced this index, so
        // an unconditional dropIndex() aborts migrate:rollback with SQLSTATE
        // 42704 and leaves the schema half rolled back.
        HoaNamErdSchema::dropIndexIfExists('stock_movements', 'idx_stock_movements_dimension_time');
        HoaNamErdSchema::dropColumnsCascade('stock_movements', ['stock_status']);
    }
};
