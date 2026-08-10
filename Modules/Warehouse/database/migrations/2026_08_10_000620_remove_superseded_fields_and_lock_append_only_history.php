<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropSupersededColumns();
        $this->failClosedOnLegacyDocumentStates();

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresBusinessChecks();
            $this->addPostgresAppendOnlyGuards();
        }
    }

    public function down(): void
    {
        // Fail-forward baseline migration. Superseded partial/shortage fields and
        // append-only protections are intentionally not reintroduced.
    }

    private function dropSupersededColumns(): void
    {
        $columns = [
            'stock_document_lines' => [
                'completion_type',
                'shortage_reason',
                'remaining_qty',
                'cancelled_remainder_qty',
            ],
            'stock_documents' => [
                'remaining_qty',
                'cancelled_remainder_qty',
                'partial_posted_at',
            ],
        ];

        foreach ($columns as $table => $candidates) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($candidates as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }

    private function failClosedOnLegacyDocumentStates(): void
    {
        if (! Schema::hasTable('stock_documents')) {
            return;
        }

        $legacy = DB::table('stock_documents')
            ->whereNotIn('status', ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])
            ->orWhereNotIn('doc_type', ['receipt', 'issue'])
            ->first(['id', 'doc_type', 'status']);

        if ($legacy !== null) {
            throw new RuntimeException(sprintf(
                'Current baseline migration blocked by legacy stock document %s (%s/%s). Reconcile or archive the superseded transfer/partial workflow before deployment.',
                (string) $legacy->id,
                (string) $legacy->doc_type,
                (string) $legacy->status,
            ));
        }
    }

    private function addPostgresBusinessChecks(): void
    {
        foreach ([
            "ALTER TABLE stock_documents ADD CONSTRAINT chk_stock_documents_current_status CHECK (status IN ('DRAFT','SCANNING','POSTED','CANCELLED'))",
            "ALTER TABLE stock_documents ADD CONSTRAINT chk_stock_documents_current_type CHECK (doc_type IN ('receipt','issue'))",
            "ALTER TABLE stock_document_lines ADD CONSTRAINT chk_stock_document_line_integer_positive CHECK (qty_planned > 0 AND qty_actual > 0 AND scanned_qty >= 0 AND scanned_qty <= qty_planned)",
            "ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement_type_current CHECK (movement_type IN ('OPENING','RECEIPT','ISSUE','COMPONENT_ISSUE','REVERSAL'))",
            "ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement_positive_quantity CHECK (quantity > 0)",
            "ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement_stock_status CHECK (stock_status IN ('AVAILABLE','NON_SELLABLE','DEFECTIVE','QUARANTINE'))",
            "ALTER TABLE item_instances ADD CONSTRAINT chk_item_instance_current_status CHECK (item_status IN ('NEW','IN_STOCK','ISSUED','IN_WARRANTY','RETURNED_TO_CUSTOMER','LOST','ADJUSTED_OUT'))",
            "ALTER TABLE component_issue_documents ADD CONSTRAINT chk_component_issue_current_status CHECK (status IN ('DRAFT','SCANNING','POSTED','CANCELLED'))",
            "ALTER TABLE outbound_request_imports ADD CONSTRAINT chk_outbound_import_current_status CHECK (status IN ('UPLOADED','VALIDATED','REJECTED','CONSUMED'))",
            "ALTER TABLE label_snapshots ADD CONSTRAINT chk_label_snapshot_current_status CHECK (status IN ('PENDING_PRINT','PRINTED','PRINT_FAILED'))",
            "ALTER TABLE label_print_events ADD CONSTRAINT chk_label_print_current_status CHECK (status IN ('PENDING_PRINT','PRINTED','PRINT_FAILED'))",
            "ALTER TABLE warranty_case ADD CONSTRAINT chk_warranty_current_status CHECK (status IN ('RECEIVED','CHECKING','REPAIRING','COMPLETED','RETURNED','CANCELLED'))",
            "ALTER TABLE physical_codes ADD CONSTRAINT chk_physical_code_target_type CHECK (assigned_object_type IS NULL OR assigned_object_type IN ('ITEM','CONTAINER'))",
            "ALTER TABLE physical_codes ADD CONSTRAINT chk_rfid_requires_hardware_uid CHECK (carrier_type <> 'RFID' OR hardware_uid IS NOT NULL)",
        ] as $statement) {
            $this->statementIgnoreDuplicateConstraint($statement);
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uk_warranty_case_one_active_item
            ON warranty_case(item_id)
            WHERE item_id IS NOT NULL AND status NOT IN ('RETURNED','CANCELLED')
        SQL);
    }

    private function addPostgresAppendOnlyGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_reject_append_only_change()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only; UPDATE/DELETE is forbidden', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (['stock_movements', 'audit_log'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_append_only ON {$table}");
            DB::statement("CREATE TRIGGER trg_{$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION hn_reject_append_only_change()");
        }

        if (Schema::hasTable('stock_documents')) {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION hn_reject_posted_document_change()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = 'POSTED' THEN
                        RAISE EXCEPTION 'POSTED stock document % is immutable; use reversal/correction', OLD.id;
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('DROP TRIGGER IF EXISTS trg_stock_documents_posted_immutable ON stock_documents');
            DB::statement("CREATE TRIGGER trg_stock_documents_posted_immutable BEFORE UPDATE OR DELETE ON stock_documents FOR EACH ROW EXECUTE FUNCTION hn_reject_posted_document_change()");
        }

        foreach (['reversal_document', 'component_issue_documents'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $function = "hn_reject_posted_{$table}_change";
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION {$function}()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = 'POSTED' THEN
                        RAISE EXCEPTION 'POSTED {$table} is immutable; create a new correction chain';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_posted_immutable ON {$table}");
            DB::statement("CREATE TRIGGER trg_{$table}_posted_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}()");
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
