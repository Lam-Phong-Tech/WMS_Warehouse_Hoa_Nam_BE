<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * hn_erd_reject_posted_change() (migration 2026_08_10_000770) unconditionally
 * raises whenever a BEFORE UPDATE fires on a POSTED row of inbound_documents /
 * outbound_documents / reversal_documents / component_issue_documents.
 *
 * Separately, hn_erd_mirror_<table>() (migration 2026_08_10_000765) is an
 * AFTER STATEMENT trigger on `stock_documents` that blanket-resyncs the ENTIRE
 * legacy projection via `INSERT ... ON CONFLICT DO UPDATE` every time ANY row
 * is written to `stock_documents` — including unrelated new documents. That
 * resync re-touches every already-POSTED legacy row with identical values,
 * which still fires the BEFORE UPDATE ROW trigger and trips the immutability
 * guard, even though nothing is actually changing.
 *
 * Net effect: once a single document reaches POSTED, creating ANY new
 * inbound/outbound document fails with "POSTED ... row is immutable" because
 * the harmless resync of the old row aborts the same transaction. Restrict
 * the guard to genuine mutations (NEW IS DISTINCT FROM OLD) so idempotent
 * mirror resyncs pass through while real attempts to alter a POSTED row are
 * still rejected exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_reject_posted_change()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'POSTED' THEN
                    IF TG_OP = 'UPDATE' AND NEW IS NOT DISTINCT FROM OLD THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'POSTED % row is immutable; use reversal/correction', TG_TABLE_NAME;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward: the stricter unconditional guard predates the mirror
        // resync interaction bug and is not worth restoring.
    }
};
