<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignStockDocuments();
        $this->alignStockDocumentLines();
        $this->alignImportAndScanEvidence();
        $this->alignReversalContract();
        $this->enforceRelationships();

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresGuards();
            $this->refreshCanonicalDocumentViews();
        }
    }

    public function down(): void
    {
        // Fail-forward baseline migration: canonical fields/constraints are retained.
    }

    private function alignStockDocuments(): void
    {
        if (! Schema::hasTable('stock_documents')) {
            return;
        }

        Schema::table('stock_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_documents', 'source_name')) {
                $table->string('source_name', 255)->nullable();
            }
            if (! Schema::hasColumn('stock_documents', 'outbound_request_id')) {
                $table->uuid('outbound_request_id')->nullable();
            }
            if (! Schema::hasColumn('stock_documents', 'agency_id')) {
                $table->uuid('agency_id')->nullable();
            }
            if (! Schema::hasColumn('stock_documents', 'recipient_name')) {
                $table->string('recipient_name', 200)->nullable();
            }
            if (! Schema::hasColumn('stock_documents', 'recipient_address')) {
                $table->text('recipient_address')->nullable();
            }
            if (! Schema::hasColumn('stock_documents', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
        });

        DB::table('stock_documents')->whereNull('version')->update(['version' => 1]);
        $this->addIndex('stock_documents', ['outbound_request_id'], 'idx_stock_documents_outbound_request');
        $this->addIndex('stock_documents', ['agency_id'], 'idx_stock_documents_agency');
    }

    private function alignStockDocumentLines(): void
    {
        if (! Schema::hasTable('stock_document_lines')) {
            return;
        }

        Schema::table('stock_document_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_document_lines', 'outbound_request_line_id')) {
                $table->uuid('outbound_request_line_id')->nullable();
            }
            if (! Schema::hasColumn('stock_document_lines', 'import_batch_id')) {
                $table->uuid('import_batch_id')->nullable();
            }
            if (! Schema::hasColumn('stock_document_lines', 'note')) {
                $table->text('note')->nullable();
            }
            if (! Schema::hasColumn('stock_document_lines', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
        });

        $this->addIndex('stock_document_lines', ['outbound_request_line_id'], 'idx_stock_document_lines_request_line');
        $this->addIndex('stock_document_lines', ['import_batch_id'], 'idx_stock_document_lines_import_batch');
    }

    private function alignImportAndScanEvidence(): void
    {
        if (Schema::hasTable('outbound_request_imports') && ! Schema::hasColumn('outbound_request_imports', 'source_key')) {
            Schema::table('outbound_request_imports', function (Blueprint $table): void {
                $table->string('source_key', 160)->nullable();
            });
            DB::table('outbound_request_imports')->whereNull('source_key')->orderBy('id')->each(function (object $row): void {
                DB::table('outbound_request_imports')->where('id', $row->id)->update([
                    'source_key' => 'CHECKSUM:'.(string) $row->source_checksum,
                ]);
            });
            $this->addUnique('outbound_request_imports', ['source_key'], 'uk_outbound_import_source_key');
        }

        foreach (['inbound_scan_entries', 'outbound_item_matches'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'scan_event_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('scan_event_id')->nullable();
            });
        }
    }

    private function alignReversalContract(): void
    {
        if (Schema::hasTable('reversal_document')) {
            Schema::table('reversal_document', function (Blueprint $table): void {
                if (! Schema::hasColumn('reversal_document', 'warehouse_id')) {
                    $table->uuid('warehouse_id')->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'original_document_type')) {
                    $table->string('original_document_type', 50)->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'original_document_id')) {
                    $table->uuid('original_document_id')->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'reason_code')) {
                    $table->string('reason_code', 80)->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'reason_text')) {
                    $table->text('reason_text')->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'physical_return_status')) {
                    $table->string('physical_return_status', 30)->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'updated_by')) {
                    $table->uuid('updated_by')->nullable();
                }
            });

            DB::table('reversal_document')->whereNull('original_document_id')->whereNotNull('stock_document_id')->update([
                'original_document_id' => DB::raw('stock_document_id'),
            ]);
            DB::table('reversal_document')->whereNull('reason_text')->whereNotNull('reason')->update([
                'reason_text' => DB::raw('reason'),
            ]);
        }

        if (Schema::hasTable('reversal_line')) {
            Schema::table('reversal_line', function (Blueprint $table): void {
                if (! Schema::hasColumn('reversal_line', 'line_no')) {
                    $table->unsignedInteger('line_no')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'quantity_to_reverse')) {
                    $table->bigInteger('quantity_to_reverse')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'return_condition')) {
                    $table->string('return_condition', 30)->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'downstream_check_result')) {
                    $table->json('downstream_check_result')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'posted_movement_id')) {
                    $table->uuid('posted_movement_id')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'created_by')) {
                    $table->uuid('created_by')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'updated_by')) {
                    $table->uuid('updated_by')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'version')) {
                    $table->unsignedInteger('version')->default(1);
                }
            });

            DB::table('reversal_line')->whereNull('quantity_to_reverse')->update([
                'quantity_to_reverse' => DB::raw('reversal_qty'),
            ]);
        }
    }

    private function enforceRelationships(): void
    {
        foreach ([
            ['stock_documents', 'outbound_request_id', 'outbound_requests', 'id', 'fk_stock_document_outbound_request', 'set null'],
            ['stock_documents', 'agency_id', 'agencies', 'agency_id', 'fk_stock_document_agency', 'set null'],
            ['stock_document_lines', 'outbound_request_line_id', 'outbound_request_lines', 'id', 'fk_stock_line_outbound_request_line', 'set null'],
            ['stock_document_lines', 'import_batch_id', 'import_batches', 'import_batch_id', 'fk_stock_line_import_batch', 'set null'],
            ['inbound_scan_entries', 'scan_event_id', 'scan_event', 'scan_event_id', 'fk_inbound_scan_event', 'set null'],
            ['outbound_item_matches', 'scan_event_id', 'scan_event', 'scan_event_id', 'fk_outbound_match_scan_event', 'set null'],
            ['reversal_document', 'warehouse_id', 'warehouses', 'id', 'fk_reversal_document_warehouse', 'restrict'],
            ['reversal_line', 'posted_movement_id', 'stock_movements', 'id', 'fk_reversal_line_posted_movement', 'set null'],
        ] as [$table, $column, $parent, $parentColumn, $name, $delete]) {
            $this->addForeignKey($table, $column, $parent, $parentColumn, $name, $delete);
        }
    }

    private function addPostgresGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uk_warehouses_single_active
            ON warehouses ((1))
            WHERE status = 'ACTIVE'
        SQL);

        foreach ([
            'ALTER TABLE stock_documents ADD CONSTRAINT chk_stock_documents_version CHECK (version >= 0)',
            "ALTER TABLE reversal_document ADD CONSTRAINT chk_reversal_document_status_current CHECK (status IN ('DRAFT','POSTED','CANCELLED'))",
            "ALTER TABLE reversal_document ADD CONSTRAINT chk_reversal_physical_return_status CHECK (physical_return_status IS NULL OR physical_return_status IN ('PENDING_RETURN','CONFIRMED'))",
            'ALTER TABLE reversal_line ADD CONSTRAINT chk_reversal_quantity_current CHECK (quantity_to_reverse IS NULL OR quantity_to_reverse > 0)',
            "ALTER TABLE reversal_line ADD CONSTRAINT chk_reversal_return_condition CHECK (return_condition IS NULL OR return_condition IN ('SELLABLE','DEFECTIVE','NON_SELLABLE'))",
        ] as $statement) {
            $this->statementIgnoreDuplicateConstraint($statement);
        }

        if (Schema::hasTable('label_snapshots')) {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION hn_lock_label_snapshot_business_payload()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.outbound_item_match_id IS DISTINCT FROM NEW.outbound_item_match_id
                       OR OLD.sku_id IS DISTINCT FROM NEW.sku_id
                       OR OLD.item_id IS DISTINCT FROM NEW.item_id
                       OR OLD.label_payload IS DISTINCT FROM NEW.label_payload THEN
                        RAISE EXCEPTION 'label snapshot business payload is immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('DROP TRIGGER IF EXISTS trg_label_snapshot_payload_immutable ON label_snapshots');
            DB::statement('CREATE TRIGGER trg_label_snapshot_payload_immutable BEFORE UPDATE ON label_snapshots FOR EACH ROW EXECUTE FUNCTION hn_lock_label_snapshot_business_payload()');
        }
    }

    private function refreshCanonicalDocumentViews(): void
    {
        DB::statement('DROP VIEW IF EXISTS inbound_documents');
        DB::statement(<<<'SQL'
            CREATE VIEW inbound_documents AS
            SELECT id, doc_no AS inbound_no, dst_warehouse_id AS warehouse_id, source_name,
                   doc_date AS document_date, status, note, posted_at, posted_by,
                   cancelled_at, cancelled_by, cancel_reason,
                   created_at, created_by, updated_at, updated_by, version
            FROM stock_documents
            WHERE doc_type = 'receipt'
        SQL);

        DB::statement('DROP VIEW IF EXISTS outbound_documents');
        DB::statement(<<<'SQL'
            CREATE VIEW outbound_documents AS
            SELECT id, doc_no AS outbound_no, src_warehouse_id AS warehouse_id,
                   outbound_request_id, agency_id, recipient_name, recipient_address,
                   doc_date AS document_date, status, note, posted_at, posted_by,
                   cancelled_at, cancelled_by, cancel_reason,
                   created_at, created_by, updated_at, updated_by, version
            FROM stock_documents
            WHERE doc_type = 'issue'
        SQL);

        DB::statement('DROP VIEW IF EXISTS inbound_lines');
        DB::statement(<<<'SQL'
            CREATE VIEW inbound_lines AS
            SELECT l.id, l.stock_document_id AS inbound_document_id, l.line_no,
                   l.product_id AS sku_id, l.import_batch_id, l.qty_planned AS expected_qty,
                   l.scanned_qty, l.note, l.created_at, d.created_by,
                   l.updated_at, d.updated_by, l.version
            FROM stock_document_lines l
            JOIN stock_documents d ON d.id = l.stock_document_id
            WHERE d.doc_type = 'receipt'
        SQL);

        DB::statement('DROP VIEW IF EXISTS outbound_lines');
        DB::statement(<<<'SQL'
            CREATE VIEW outbound_lines AS
            SELECT l.id, l.stock_document_id AS outbound_document_id, l.outbound_request_line_id,
                   l.line_no, l.product_id AS sku_id, l.qty_planned AS required_qty,
                   l.scanned_qty, l.note, l.created_at, d.created_by,
                   l.updated_at, d.updated_by, l.version
            FROM stock_document_lines l
            JOIN stock_documents d ON d.id = l.stock_document_id
            WHERE d.doc_type = 'issue'
        SQL);
    }

    private function addForeignKey(string $table, string $column, string $parent, string $parentColumn, string $name, string $delete): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable($table) || ! Schema::hasTable($parent) || ! Schema::hasColumn($table, $column) || $this->foreignKeyExists($table, $name)) {
            return;
        }

        $this->assertNoOrphans($table, $column, $parent, $parentColumn);
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $parentColumn, $name, $delete): void {
            $foreign = $blueprint->foreign($column, $name)->references($parentColumn)->on($parent);
            match ($delete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'set null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    private function assertNoOrphans(string $table, string $column, string $parent, string $parentColumn): void
    {
        if (DB::table("{$table} as c")
            ->leftJoin("{$parent} as p", "p.{$parentColumn}", '=', "c.{$column}")
            ->whereNotNull("c.{$column}")
            ->whereNull("p.{$parentColumn}")
            ->exists()) {
            throw new RuntimeException("Cannot add {$table}.{$column} relationship: orphan rows exist.");
        }
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function addUnique(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }
        if (DB::table($table)->select($columns)->whereNotNull($columns[0])->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException("Cannot add unique index {$name}: duplicate baseline data exists.");
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
        } catch (Throwable) {
            return false;
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        try {
            return collect(Schema::getForeignKeys($table))->contains(fn (array $foreign): bool => ($foreign['name'] ?? null) === $name);
        } catch (Throwable) {
            return false;
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
