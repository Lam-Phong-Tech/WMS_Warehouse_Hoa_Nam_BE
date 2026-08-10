<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS uk_inbound_scan_active_item');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_guard_inbound_item_active_document()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.item_id IS NULL OR NEW.status <> 'ACTIVE' THEN
                    RETURN NEW;
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM inbound_scan_entries e
                    JOIN stock_document_lines l ON l.id = e.inbound_line_id
                    JOIN stock_documents d ON d.id = l.stock_document_id
                    WHERE e.item_id = NEW.item_id
                      AND e.id <> NEW.id
                      AND e.status = 'ACTIVE'
                      AND d.status IN ('DRAFT','SCANNING')
                ) THEN
                    RAISE EXCEPTION 'item already belongs to another active inbound document';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_inbound_scan_active_item_guard ON inbound_scan_entries');
        DB::statement('CREATE TRIGGER trg_inbound_scan_active_item_guard BEFORE INSERT OR UPDATE OF item_id,status ON inbound_scan_entries FOR EACH ROW EXECUTE FUNCTION hn_guard_inbound_item_active_document()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_release_stock_evidence_on_cancel()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('DRAFT','SCANNING') AND NEW.status = 'CANCELLED' THEN
                    IF NEW.doc_type = 'receipt' THEN
                        UPDATE inbound_scan_entries e
                        SET status = 'REMOVED',
                            removed_at = COALESCE(e.removed_at, now()),
                            removed_by = COALESCE(e.removed_by, NEW.cancelled_by),
                            version = COALESCE(e.version, 0) + 1,
                            updated_at = now(),
                            updated_by = COALESCE(e.updated_by, NEW.cancelled_by)
                        FROM stock_document_lines l
                        WHERE l.id = e.inbound_line_id
                          AND l.stock_document_id = NEW.id
                          AND e.status = 'ACTIVE';
                    ELSIF NEW.doc_type = 'issue' THEN
                        UPDATE outbound_item_matches m
                        SET status = 'REMOVED',
                            removed_at = COALESCE(m.removed_at, now()),
                            removed_by = COALESCE(m.removed_by, NEW.cancelled_by),
                            version = COALESCE(m.version, 0) + 1,
                            updated_at = now(),
                            updated_by = COALESCE(m.updated_by, NEW.cancelled_by)
                        FROM stock_document_lines l
                        WHERE l.id = m.outbound_line_id
                          AND l.stock_document_id = NEW.id
                          AND m.status = 'ACTIVE';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_stock_document_release_evidence ON stock_documents');
        DB::statement('CREATE TRIGGER trg_stock_document_release_evidence AFTER UPDATE OF status ON stock_documents FOR EACH ROW EXECUTE FUNCTION hn_release_stock_evidence_on_cancel()');

        $this->assertNoLegacyIssueWithoutRequest();
        $this->statementIgnoreDuplicateConstraint(
            "ALTER TABLE stock_documents ADD CONSTRAINT chk_issue_requires_outbound_request CHECK (doc_type <> 'issue' OR outbound_request_id IS NOT NULL)"
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_guard_stock_line_sku_scope()
            RETURNS trigger AS $$
            DECLARE
                v_doc_type text;
                v_sku_type text;
                v_status text;
            BEGIN
                SELECT doc_type INTO v_doc_type FROM stock_documents WHERE id = NEW.stock_document_id;
                SELECT sku_type, status INTO v_sku_type, v_status FROM sku WHERE sku_id = NEW.product_id;
                IF v_status IS DISTINCT FROM 'ACTIVE' THEN
                    RAISE EXCEPTION 'stock document line requires ACTIVE sku';
                END IF;
                IF v_doc_type = 'issue' AND v_sku_type IS DISTINCT FROM 'PRODUCT' THEN
                    RAISE EXCEPTION 'outbound stock document accepts PRODUCT only; COMPONENT requires warranty component issue';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_stock_line_sku_scope ON stock_document_lines');
        DB::statement('CREATE TRIGGER trg_stock_line_sku_scope BEFORE INSERT OR UPDATE OF product_id,stock_document_id ON stock_document_lines FOR EACH ROW EXECUTE FUNCTION hn_guard_stock_line_sku_scope()');

        $this->assertNoDuplicateActiveWarranty();
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_warranty_case_one_active_item ON warranty_case(item_id) WHERE item_id IS NOT NULL AND status NOT IN ('RETURNED','CANCELLED')");

        $this->statementIgnoreDuplicateConstraint(
            'ALTER TABLE sku_compatible_accessories ADD CONSTRAINT chk_sku_accessory_not_self CHECK (sku_id <> accessory_sku_id)'
        );
    }

    public function down(): void
    {
        // Business invariants are fail-forward.
    }

    private function assertNoLegacyIssueWithoutRequest(): void
    {
        if (DB::table('stock_documents')->where('doc_type', 'issue')->whereNull('outbound_request_id')->exists()) {
            throw new RuntimeException('Current baseline requires every outbound stock document to reference outbound_request_id; reconcile legacy issue documents before migration.');
        }
    }

    private function assertNoDuplicateActiveWarranty(): void
    {
        $duplicate = DB::table('warranty_case')
            ->select('item_id')
            ->whereNotNull('item_id')
            ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
            ->groupBy('item_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException('An item has more than one active warranty case; reconcile before enforcing current baseline.');
        }
    }

    private function statementIgnoreDuplicateConstraint(string $statement): void
    {
        try {
            DB::statement($statement);
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (! str_contains($message, 'already exists') && ! str_contains($message, 'duplicate object')) {
                throw $e;
            }
        }
    }
};
