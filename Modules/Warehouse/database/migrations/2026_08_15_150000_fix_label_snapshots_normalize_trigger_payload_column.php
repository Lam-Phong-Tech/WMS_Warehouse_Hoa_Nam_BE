<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: hn_erd_normalize_runtime_label_snapshots() (created by
 * 2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync) unconditionally
 * assigns NEW.payload, NEW.label_code, NEW.template_version, NEW.customer_name,
 * NEW.customer_address, NEW.product_name, NEW.sku_code, NEW.item_code_value and
 * NEW.outbound_no. None of these columns were ever added to the physical
 * `label_snapshots` table by any migration (2026_08_10_000766_backfill_structured_erd_
 * payload_columns only backfills them conditionally behind Schema::hasColumn(), and
 * 2026_08_15_140000_align_packing_label_to_outbound_document — the most recent schema
 * change to this table — only adds outbound_document_id/applied_at/applied_by).
 *
 * Effect: every INSERT/UPDATE on label_snapshots (including the mandatory
 * "print label before Post Issue" step used by CurrentBaselineStockFlowController /
 * outboundScan) fails with `record "new" has no field "payload"`, breaking the outbound
 * flow for every caller, not just seed data.
 *
 * Fix: recreate the trigger function to only touch columns that actually exist on
 * label_snapshots today (label_payload, outbound_document_id + best-effort backfill of
 * outbound_document_id from outbound_item_matches when omitted), leaving the rest of the
 * normalize/mirror pipeline (000765/000766/000770) untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_snapshots')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_label_snapshots()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.outbound_document_id IS NULL AND NEW.outbound_item_match_id IS NOT NULL THEN
                    SELECT l.stock_document_id
                      INTO NEW.outbound_document_id
                      FROM outbound_item_matches m
                      JOIN stock_document_lines l ON l.id = m.outbound_line_id
                     WHERE m.id = NEW.outbound_item_match_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward: restoring the broken NEW.payload-referencing body would
        // reintroduce the outage this migration fixes.
    }
};
