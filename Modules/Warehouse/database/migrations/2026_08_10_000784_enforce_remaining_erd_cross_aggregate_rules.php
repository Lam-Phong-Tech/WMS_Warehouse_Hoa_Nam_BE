<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->agencyGuard();
        $this->exclusiveActiveItemGuard();
        $this->printerGuard();
        $this->pvPublishGuard();
        $this->recommendationRuleGuards();
        $this->migrationCommitGuard();
    }

    public function down(): void
    {
        // Fail-forward signed ERD business invariants.
    }

    private function agencyGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_outbound_agency_active()
            RETURNS trigger AS $$
            DECLARE
                agency_status varchar(20);
            BEGIN
                IF NEW.agency_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT status INTO agency_status FROM agencies WHERE id=NEW.agency_id;
                IF agency_status IS DISTINCT FROM 'ACTIVE' THEN
                    RAISE EXCEPTION 'AGENCY_NOT_ACTIVE: outbound document requires an ACTIVE agency';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_outbound_agency_active ON outbound_documents');
        DB::statement('CREATE TRIGGER trg_erd_outbound_agency_active BEFORE INSERT OR UPDATE OF agency_id ON outbound_documents FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_outbound_agency_active()');
    }

    private function exclusiveActiveItemGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_outbound_item_exclusive()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.item_id IS NULL OR NEW.status <> 'ACTIVE' THEN
                    RETURN NEW;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM component_issue_items c
                     WHERE c.item_id=NEW.item_id AND c.status='ACTIVE'
                ) THEN
                    RAISE EXCEPTION 'ITEM_ALREADY_ACTIVE: item is already selected by an active component issue';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_outbound_item_exclusive ON outbound_item_matches');
        DB::statement('CREATE TRIGGER trg_erd_outbound_item_exclusive BEFORE INSERT OR UPDATE OF item_id,status ON outbound_item_matches FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_outbound_item_exclusive()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_component_item_exclusive()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.item_id IS NULL OR NEW.status <> 'ACTIVE' THEN
                    RETURN NEW;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM outbound_item_matches o
                     WHERE o.item_id=NEW.item_id AND o.status='ACTIVE'
                ) THEN
                    RAISE EXCEPTION 'ITEM_ALREADY_ACTIVE: item is already matched by an active outbound document';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_component_item_exclusive ON component_issue_items');
        DB::statement('CREATE TRIGGER trg_erd_component_item_exclusive BEFORE INSERT OR UPDATE OF item_id,status ON component_issue_items FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_component_item_exclusive()');
    }

    private function printerGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_print_printer_active()
            RETURNS trigger AS $$
            DECLARE
                printer_status varchar(20);
            BEGIN
                IF NEW.printer_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT status INTO printer_status FROM printer_configs WHERE id=NEW.printer_id;
                IF printer_status IS DISTINCT FROM 'ACTIVE' THEN
                    RAISE EXCEPTION 'PRINTER_NOT_ACTIVE: print event requires an ACTIVE printer';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_print_printer_active ON label_print_events');
        DB::statement('CREATE TRIGGER trg_erd_print_printer_active BEFORE INSERT OR UPDATE OF printer_id ON label_print_events FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_print_printer_active()');
    }

    private function pvPublishGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_pv_publish_active_sku()
            RETURNS trigger AS $$
            DECLARE
                sku_status varchar(20);
            BEGIN
                IF NOT NEW.published THEN
                    RETURN NEW;
                END IF;
                SELECT status INTO sku_status FROM skus WHERE id=NEW.sku_id;
                IF sku_status IS DISTINCT FROM 'ACTIVE' THEN
                    RAISE EXCEPTION 'SKU_NOT_ACTIVE: only ACTIVE SKU may be published to PV';
                END IF;
                NEW.published_at := COALESCE(NEW.published_at, CURRENT_TIMESTAMP);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_pv_publish_active_sku ON pv_product_profiles');
        DB::statement('CREATE TRIGGER trg_erd_pv_publish_active_sku BEFORE INSERT OR UPDATE OF sku_id,published ON pv_product_profiles FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_pv_publish_active_sku()');
    }

    private function recommendationRuleGuards(): void
    {
        DB::statement('ALTER TABLE pv_recommendation_rules DROP CONSTRAINT IF EXISTS chk_erd_pv_recommendation_selector');
        DB::statement('ALTER TABLE pv_recommendation_rules ADD CONSTRAINT chk_erd_pv_recommendation_selector CHECK (sku_id IS NOT NULL OR category_id IS NOT NULL OR tag_id IS NOT NULL)');
        DB::statement('ALTER TABLE pv_recommendation_rules DROP CONSTRAINT IF EXISTS chk_erd_pv_recommendation_priority');
        DB::statement('ALTER TABLE pv_recommendation_rules ADD CONSTRAINT chk_erd_pv_recommendation_priority CHECK (priority >= 0)');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_pv_recommendation_scope_priority
            ON pv_recommendation_rules (
                industry_id,
                COALESCE(sku_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(category_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(tag_id, '00000000-0000-0000-0000-000000000000'::uuid),
                priority
            )
        SQL);
    }

    private function migrationCommitGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_migration_commit_evidence()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.mode='COMMIT' AND NEW.status IN ('COMMITTED','PASSED') THEN
                    IF NEW.reconciliation_status IS DISTINCT FROM 'PASS' THEN
                        RAISE EXCEPTION 'MIGRATION_RECONCILIATION_REQUIRED: COMMIT requires reconciliation PASS';
                    END IF;
                    IF NEW.source_checksum IS NULL OR btrim(NEW.source_checksum)='' THEN
                        RAISE EXCEPTION 'MIGRATION_CHECKSUM_REQUIRED: COMMIT requires source checksum';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_migration_commit_evidence ON migration_runs');
        DB::statement('CREATE TRIGGER trg_erd_migration_commit_evidence BEFORE INSERT OR UPDATE OF mode,status,reconciliation_status,source_checksum ON migration_runs FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_migration_commit_evidence()');
    }
};
