<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('container_items') || ! Schema::hasColumn('container_items', 'quantity')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            if (DB::table('container_items')->whereNotNull('quantity')->whereRaw('quantity <> trunc(quantity)')->exists()) {
                throw new RuntimeException('Current ERD requires integer container_items.quantity; fractional rows must be reconciled before deployment.');
            }
            DB::statement('ALTER TABLE container_items ALTER COLUMN quantity TYPE BIGINT USING quantity::bigint');
            DB::statement('ALTER TABLE container_items ADD CONSTRAINT chk_container_items_quantity CHECK (quantity IS NULL OR quantity >= 0)');
            DB::statement("ALTER TABLE container_items ADD CONSTRAINT chk_container_items_status CHECK (status IN ('ACTIVE','REMOVED'))");
        }
    }

    public function down(): void
    {
        // Integer inventory semantics are retained.
    }
};
