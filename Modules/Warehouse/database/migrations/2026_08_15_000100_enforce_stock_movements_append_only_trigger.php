<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-205: `stock_movements` is the physical table CurrentBaselineStockService and
 * CurrentBaselineReversalService write to directly (DB::table('stock_movements')), and is
 * guarded against UPDATE/DELETE only at the Eloquent layer (StockMovement::booted()).
 *
 * Migration 2026_08_10_000770 (installHistoryGuards()) intended a DB-level append-only
 * trigger for the ledger, but its loop targets ['audit_logs', 'inventory_movements',
 * 'warranty_events'] and gates each on Schema::hasTable(), which — on PostgreSQL — checks
 * pg_class.relkind IN ('r','p'), i.e. tables only. `inventory_movements` is a compatibility
 * VIEW over `stock_movements` (see 2026_08_09_000400/2026_08_10_000600 createView calls) at
 * the point 000770 runs; it is only later materialized into its own separate physical copy
 * by the AFTER-INSERT mirror trigger from 2026_08_10_000765
 * (hn_erd_mirror_inventory_movements, source `stock_movements`). So the append-only trigger
 * ends up protecting the materialized mirror table, never the live `stock_movements` table
 * the application actually writes to and reads from for balance/reconciliation.
 *
 * This adds the same guard directly on `stock_movements`, reusing the existing
 * hn_erd_reject_append_only_change() function (already created by 000770) so behavior is
 * identical to the other append-only ledgers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! $this->relationExists('stock_movements')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_reject_append_only_change()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only under HN-WMS-ERD-DD-001', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_stock_movements_append_only ON stock_movements');
        DB::statement('CREATE TRIGGER trg_stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION hn_erd_reject_append_only_change()');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_stock_movements_append_only ON stock_movements');
    }

    private function relationExists(string $table): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 AS present FROM pg_class c, pg_namespace n WHERE n.nspname = current_schema() AND c.relname = ? AND c.relkind IN ('r','p') AND n.oid = c.relnamespace",
            [$table],
        );
    }
};
