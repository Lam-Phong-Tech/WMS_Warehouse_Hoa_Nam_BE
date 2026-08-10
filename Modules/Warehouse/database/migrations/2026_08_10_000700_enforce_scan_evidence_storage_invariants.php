<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inbound_scan_entries')) {
            DB::table('inbound_scan_entries')->whereNull('captured_at')->update(['captured_at' => DB::raw('created_at')]);
            DB::table('inbound_scan_entries')->whereNull('captured_by')->whereNotNull('created_by')->update(['captured_by' => DB::raw('created_by')]);
        }
        if (Schema::hasTable('outbound_item_matches')) {
            DB::table('outbound_item_matches')->whereNull('matched_at')->update(['matched_at' => DB::raw('created_at')]);
            DB::table('outbound_item_matches')->whereNull('matched_by')->whereNotNull('created_by')->update(['matched_by' => DB::raw('created_by')]);
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_fill_scan_evidence_fields()
            RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'inbound_scan_entries' THEN
                    NEW.status := COALESCE(NEW.status, 'ACTIVE');
                    NEW.captured_at := COALESCE(NEW.captured_at, NEW.created_at, now());
                    NEW.captured_by := COALESCE(NEW.captured_by, NEW.created_by);
                ELSIF TG_TABLE_NAME = 'outbound_item_matches' THEN
                    NEW.status := COALESCE(NEW.status, 'ACTIVE');
                    NEW.matched_at := COALESCE(NEW.matched_at, NEW.created_at, now());
                    NEW.matched_by := COALESCE(NEW.matched_by, NEW.created_by);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_inbound_scan_fill_evidence ON inbound_scan_entries');
        DB::statement('CREATE TRIGGER trg_inbound_scan_fill_evidence BEFORE INSERT ON inbound_scan_entries FOR EACH ROW EXECUTE FUNCTION hn_fill_scan_evidence_fields()');
        DB::statement('DROP TRIGGER IF EXISTS trg_outbound_match_fill_evidence ON outbound_item_matches');
        DB::statement('CREATE TRIGGER trg_outbound_match_fill_evidence BEFORE INSERT ON outbound_item_matches FOR EACH ROW EXECUTE FUNCTION hn_fill_scan_evidence_fields()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_finalize_outbound_matches_on_post()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.doc_type = 'issue' AND OLD.status = 'SCANNING' AND NEW.status = 'POSTED' THEN
                    UPDATE outbound_item_matches m
                    SET status = 'POSTED',
                        version = COALESCE(m.version, 0) + 1,
                        updated_at = now(),
                        updated_by = COALESCE(m.updated_by, NEW.posted_by)
                    FROM stock_document_lines l
                    WHERE l.id = m.outbound_line_id
                      AND l.stock_document_id = NEW.id
                      AND m.status = 'ACTIVE';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_stock_document_finalize_outbound_matches ON stock_documents');
        DB::statement('CREATE TRIGGER trg_stock_document_finalize_outbound_matches AFTER UPDATE OF status ON stock_documents FOR EACH ROW EXECUTE FUNCTION hn_finalize_outbound_matches_on_post()');

        if (! DB::table('inbound_scan_entries')->whereNull('captured_at')->exists()) {
            DB::statement('ALTER TABLE inbound_scan_entries ALTER COLUMN captured_at SET NOT NULL');
        }
        if (! DB::table('outbound_item_matches')->whereNull('matched_at')->exists()) {
            DB::statement('ALTER TABLE outbound_item_matches ALTER COLUMN matched_at SET NOT NULL');
        }
    }

    public function down(): void
    {
        // Evidence timestamps and lifecycle guards are intentionally fail-forward.
    }
};
