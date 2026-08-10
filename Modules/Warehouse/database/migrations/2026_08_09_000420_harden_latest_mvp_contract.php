<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropStocktakeResidue();
        $this->addCanonicalInventoryAliases();
        $this->normalizeLegacyStates();
    }

    public function down(): void
    {
        // Irreversible MVP hardening migration.
    }

    private function dropStocktakeResidue(): void
    {
        if (Schema::hasColumn('stock_movements', 'stocktake_variance_id')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropColumn('stocktake_variance_id');
            });
        }

        Schema::dropIfExists('stocktake_variance');
    }

    private function addCanonicalInventoryAliases(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_balances', 'sku_id')) {
                $table->uuid('sku_id')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('inventory_balances', 'quantity')) {
                $table->decimal('quantity', 18, 3)->default(0)->after('stock_status');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'sku_id')) {
                $table->uuid('sku_id')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('stock_movements', 'quantity')) {
                $table->decimal('quantity', 18, 3)->default(0)->after('qty_delta');
            }
        });

        DB::table('inventory_balances')->update([
            'sku_id' => DB::raw('product_id'),
            'quantity' => DB::raw('on_hand_qty'),
        ]);
        DB::table('stock_movements')->update([
            'sku_id' => DB::raw('product_id'),
            'quantity' => DB::raw('ABS(qty_delta)'),
        ]);
    }

    private function normalizeLegacyStates(): void
    {
        DB::table('stock_documents')
            ->whereIn('status', ['draft', 'pending', 'approved'])
            ->update(['status' => 'DRAFT']);
        DB::table('stock_documents')
            ->whereIn('status', ['scanning'])
            ->update(['status' => 'SCANNING']);
        DB::table('stock_documents')
            ->whereIn('status', ['received', 'issued', 'closed', 'posted'])
            ->update(['status' => 'POSTED']);
        DB::table('stock_documents')
            ->whereIn('status', ['cancelled', 'rejected'])
            ->update(['status' => 'CANCELLED']);

        DB::table('stock_movements')
            ->whereIn('movement_type', ['RECEIPT_IN', 'RECEIPT_POSTED'])
            ->update(['movement_type' => 'RECEIPT']);
        DB::table('stock_movements')
            ->whereIn('movement_type', ['ISSUE_OUT', 'ISSUE_POSTED'])
            ->update(['movement_type' => 'ISSUE']);
        DB::table('stock_movements')
            ->whereIn('movement_type', ['RECEIPT_REVERSED', 'ISSUE_REVERSED', 'ADJUST_IN', 'ADJUST_OUT'])
            ->update(['movement_type' => 'REVERSAL']);
    }
};
