<?php

use App\Support\HoaNamErdSchema;
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

        $schema = HoaNamErdSchema::parse();
        $this->backfillRequiredCompatibilityFields();
        $this->enforceNullability($schema);
        $this->enforceKeysAndRelationships($schema);
        $this->enforceDocumentedIndexes($schema);
        $this->enforceBusinessChecks();
        $this->installHistoryGuards();
    }

    public function down(): void
    {
        // Fail-forward signed ERD constraints.
    }

    /** @param array<string,array{section:string,fields:array<string,array{type:string,nullable:bool,key:string}>}> $schema */
    private function enforceNullability(array $schema): void
    {
        foreach ($schema as $table => $definition) {
            foreach ($definition['fields'] as $field => $spec) {
                if ($spec['nullable']) {
                    continue;
                }

                if (DB::table($table)->whereNull($field)->exists()) {
                    throw new RuntimeException("ERD NOT NULL alignment blocked by {$table}.{$field}; reconcile legacy rows before deployment.");
                }

                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s SET NOT NULL',
                    HoaNamErdSchema::quote($table),
                    HoaNamErdSchema::quote($field),
                ));
            }
        }
    }

    /** @param array<string,array{section:string,fields:array<string,array{type:string,nullable:bool,key:string}>}> $schema */
    private function enforceKeysAndRelationships(array $schema): void
    {
        foreach ($schema as $table => $definition) {
            $pk = [];

            foreach ($definition['fields'] as $field => $spec) {
                if (preg_match('/\bPK(?:\s+part)?\b/i', $spec['key'])) {
                    $pk[] = $field;
                }
                if (preg_match('/\bUNIQUE\b/i', $spec['key'])) {
                    $this->addUnique($table, [$field], 'ux_erd_'.$table.'_'.$field);
                }
                if (preg_match('/\bFK\s+([a-z0-9_]+)\.([a-z0-9_]+)/i', $spec['key'], $fk)) {
                    $this->addForeignKey($table, $field, strtolower($fk[1]), strtolower($fk[2]));
                }
            }
            $compositeOverride = $this->canonicalCompositeKey($table);
            $pk = $compositeOverride ?? $pk;

            if ($pk !== []) {
                $this->ensureCanonicalKey($table, $pk);
            }

            if ($compositeOverride === null && preg_match_all('/\bPK\(([^)]+)\)/i', $definition['section'], $matches)) {
                foreach ($matches[1] as $columns) {
                    $parsed = $this->parseColumns($columns);
                    if ($parsed !== []) {
                        $this->ensureCanonicalKey($table, $parsed);
                    }
                }
            }
            if (preg_match_all('/\bUNIQUE\(([^)]+)\)/i', $definition['section'], $matches)) {
                foreach ($matches[1] as $columns) {
                    $parsed = $this->parseColumns($columns);
                    if ($parsed !== []) {
                        $this->addUnique($table, $parsed, 'ux_erd_'.$table.'_'.substr(md5(implode('_', $parsed)), 0, 8));
                    }
                }
            }
        }
    }

    private function backfillRequiredCompatibilityFields(): void
    {
        if (Schema::hasTable('user_roles') && Schema::hasColumn('user_roles', 'assigned_at')) {
            DB::statement('UPDATE user_roles SET assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP) WHERE assigned_at IS NULL');
        }

        if (Schema::hasTable('role_permissions') && Schema::hasColumn('role_permissions', 'assigned_at')) {
            DB::statement('UPDATE role_permissions SET assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP) WHERE assigned_at IS NULL');
        }
    }

    /** @return array<int,string>|null */
    private function canonicalCompositeKey(string $table): ?array
    {
        return match ($table) {
            'user_roles' => ['user_id', 'role_id'],
            'role_permissions' => ['role_id', 'permission_id'],
            'pv_product_industries' => ['profile_id', 'industry_id'],
            'pv_product_tags' => ['profile_id', 'tag_id'],
            default => null,
        };
    }

    /** @param array<string,array{section:string,fields:array<string,array{type:string,nullable:bool,key:string}>}> $schema */
    private function enforceDocumentedIndexes(array $schema): void
    {
        foreach ($schema as $table => $definition) {
            if (! preg_match_all('/\bIDX\(([^)]+)\)/i', $definition['section'], $matches)) {
                continue;
            }
            foreach ($matches[1] as $columns) {
                $parsed = $this->parseColumns($columns);
                if ($parsed === [] || collect($parsed)->contains(fn (string $field): bool => ! Schema::hasColumn($table, $field))) {
                    continue;
                }
                $name = substr('idx_erd_'.$table.'_'.implode('_', $parsed), 0, 63);
                DB::statement('CREATE INDEX IF NOT EXISTS '.HoaNamErdSchema::quote($name).' ON '.HoaNamErdSchema::quote($table).' ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $parsed)).')');
            }
        }
    }

    private function enforceBusinessChecks(): void
    {
        $checks = [
            'app_users' => ["status IN ('ACTIVE','INACTIVE','LOCKED')"],
            'roles' => ["status IN ('ACTIVE','INACTIVE')"],
            'permissions' => ["status IN ('ACTIVE','INACTIVE')"],
            'warehouses' => ["status IN ('ACTIVE','INACTIVE')"],
            'agencies' => ["status IN ('ACTIVE','INACTIVE')"],
            'product_categories' => ["status IN ('ACTIVE','INACTIVE')"],
            'brands' => ["status IN ('ACTIVE','INACTIVE')"],
            'power_sources' => ["status IN ('ACTIVE','INACTIVE')"],
            'product_models' => ["status IN ('ACTIVE','INACTIVE')"],
            'package_variants' => ["status IN ('ACTIVE','INACTIVE')"],
            'defect_catalog' => ["status IN ('ACTIVE','INACTIVE')"],
            'skus' => ["sku_type IN ('PRODUCT','COMPONENT')", "tracking_mode IN ('SERIAL','QUANTITY')", "status IN ('ACTIVE','INACTIVE')"],
            'sku_aliases' => ["status IN ('ACTIVE','INACTIVE')"],
            'sku_compatible_accessories' => ["status IN ('ACTIVE','INACTIVE')", 'sku_id <> accessory_sku_id'],
            'import_batches' => ["status IN ('ACTIVE','CLOSED')"],
            'item_instances' => [
                "status IN ('NEW','IN_STOCK','ISSUED','IN_WARRANTY','RETURNED_TO_CUSTOMER','LOST','ADJUSTED_OUT')",
                "condition IN ('NORMAL','DEFECTIVE','NON_SELLABLE','UNKNOWN')",
            ],
            'physical_codes' => [
                "carrier_type IN ('BARCODE','QR','RFID','NFC','MANUAL')",
                "status IN ('ACTIVE','INACTIVE','LOST','DAMAGED')",
                '((item_id IS NOT NULL)::integer + (container_id IS NOT NULL)::integer) = 1',
                "carrier_type <> 'RFID' OR hardware_uid IS NOT NULL",
            ],
            'containers' => [
                "container_type IN ('CARTON','BOX','TRAY')",
                "status IN ('EMPTY','IN_STOCK','PARTIAL','ISSUED','RECEIVED_PENDING_PUTAWAY','STORED','CLOSED')",
                'parent_container_id IS NULL OR parent_container_id <> id',
            ],
            'container_items' => [
                'quantity > 0',
                '((item_id IS NOT NULL)::integer + (sku_id IS NOT NULL)::integer) = 1',
            ],
            'putaway_events' => ["status IN ('DRAFT','CONFIRMED','CANCELLED')"],
            'scan_events' => [
                "scan_source IN ('CAMERA','MANUAL','HARDWARE')",
                "classification IN ('ITEM','CARTON','BOX','TRAY','UNKNOWN')",
                "result IN ('SUCCESS','DUPLICATE','UNKNOWN','INVALID','WRONG_SKU','WRONG_STATE','ERROR')",
            ],
            'inbound_documents' => ["status IN ('DRAFT','SCANNING','POSTED','CANCELLED')"],
            'inbound_lines' => ['expected_qty > 0', 'scanned_qty >= 0', 'scanned_qty <= expected_qty'],
            'inbound_scan_entries' => ['quantity > 0', "status IN ('ACTIVE','REMOVED')"],
            'inventory_balances' => ['quantity >= 0', "stock_status IN ('AVAILABLE','NON_SELLABLE','DEFECTIVE','QUARANTINE')"],
            'inventory_movements' => ['quantity_delta <> 0', "movement_type IN ('OPENING','RECEIPT','ISSUE','COMPONENT_ISSUE','REVERSAL')"],
            'posting_events' => ["status IN ('STARTED','COMMITTED','FAILED')", 'movement_count >= 0'],
            'reconciliation_incidents' => ["severity IN ('WARNING','ERROR','CRITICAL')", "status IN ('OPEN','INVESTIGATING','RESOLVED','WAIVED')"],
            'reversal_documents' => ["status IN ('DRAFT','POSTED','CANCELLED')"],
            'reversal_lines' => ['quantity_to_reverse > 0', "return_condition IN ('SELLABLE','DEFECTIVE','NON_SELLABLE')"],
            'outbound_request_imports' => ["status IN ('UPLOADED','VALIDATED','REJECTED','CONSUMED')", 'total_rows >= 0 AND valid_rows >= 0 AND error_rows >= 0'],
            'outbound_request_import_rows' => ['required_qty > 0', "validation_status IN ('VALID','ERROR','WARNING')"],
            'outbound_requests' => ["status IN ('OPEN','CONSUMED','CANCELLED')"],
            'outbound_request_lines' => ['required_qty > 0'],
            'outbound_documents' => ["status IN ('DRAFT','SCANNING','POSTED','CANCELLED')"],
            'outbound_lines' => ['required_qty > 0', 'scanned_qty >= 0', 'scanned_qty <= required_qty'],
            'outbound_item_matches' => ['quantity > 0', "status IN ('ACTIVE','REMOVED','POSTED')"],
            'label_snapshots' => ["status IN ('PENDING_PRINT','PRINTED','PRINT_FAILED')", 'reprint_count >= 0'],
            'printer_configs' => ["status IN ('ACTIVE','INACTIVE','UNHEALTHY')", 'label_width_mm > 0', 'label_height_mm > 0', 'dpi > 0'],
            'label_print_events' => ['attempt_no > 0', "event_type IN ('PRINT','REPRINT')", "status IN ('PENDING','SENT','PRINTED','FAILED')"],
            'warranty_cases' => [
                "identification_status IN ('IDENTIFIED','TEMP_ONLY')",
                "sales_trace_status IN ('TRACEABLE','NOT_TRACEABLE')",
                "status IN ('RECEIVED','CHECKING','REPAIRING','COMPLETED','RETURNED','CANCELLED')",
            ],
            'warranty_attachments' => ['file_size_bytes > 0', "attachment_type IN ('INTAKE','DIAGNOSTIC','RETURN','OTHER')", "status IN ('ACTIVE','DELETED','QUARANTINED')"],
            'component_issue_documents' => ["purpose = 'WARRANTY'", "status IN ('DRAFT','SCANNING','POSTED','CANCELLED')"],
            'component_issue_lines' => ['required_qty > 0', 'scanned_qty >= 0', 'scanned_qty <= required_qty'],
            'component_issue_items' => ['quantity > 0', "status IN ('ACTIVE','REMOVED','POSTED')"],
            'pv_product_images' => ["status IN ('ACTIVE','INACTIVE')"],
            'pv_industries' => ["status IN ('ACTIVE','INACTIVE')"],
            'pv_tags' => ["status IN ('ACTIVE','INACTIVE')"],
            'pv_recommendation_rules' => ["status IN ('ACTIVE','INACTIVE')"],
            'migration_runs' => [
                "mode IN ('DRY_RUN','COMMIT','ROLLBACK')",
                "status IN ('CREATED','RUNNING','PASSED','FAILED','COMMITTED','ROLLED_BACK')",
                "reconciliation_status IS NULL OR reconciliation_status IN ('PASS','FAIL','PENDING')",
            ],
            'migration_errors' => ["severity IN ('WARNING','ERROR','CRITICAL')"],
        ];

        foreach ($checks as $table => $expressions) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($expressions as $index => $expression) {
                $name = substr('chk_erd_'.$table.'_'.$index, 0, 63);
                if ($this->constraintExists($table, $name)) {
                    continue;
                }
                DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($table).' ADD CONSTRAINT '.HoaNamErdSchema::quote($name).' CHECK ('.$expression.')');
            }
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_warehouses_one_active ON warehouses ((1)) WHERE status='ACTIVE'");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_inventory_balance_bucket ON inventory_balances(warehouse_id, sku_id, stock_status)');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_physical_primary_item_carrier ON physical_codes(item_id, carrier_type) WHERE item_id IS NOT NULL AND is_primary=TRUE AND status='ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_physical_primary_container_carrier ON physical_codes(container_id, carrier_type) WHERE container_id IS NOT NULL AND is_primary=TRUE AND status='ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_outbound_active_item ON outbound_item_matches(item_id) WHERE item_id IS NOT NULL AND status='ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_component_active_item ON component_issue_items(item_id) WHERE item_id IS NOT NULL AND status='ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_pv_primary_image ON pv_product_images(profile_id) WHERE is_primary=TRUE AND status='ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_warranty_one_active_case_item ON warranty_cases(item_id) WHERE item_id IS NOT NULL AND status IN ('RECEIVED','CHECKING','REPAIRING','COMPLETED')");
    }

    private function installHistoryGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_reject_append_only_change()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only under HN-WMS-ERD-DD-001', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (['audit_logs', 'inventory_movements', 'warranty_events'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $trigger = substr('trg_'.$table.'_append_only', 0, 63);
            DB::statement('DROP TRIGGER IF EXISTS '.HoaNamErdSchema::quote($trigger).' ON '.HoaNamErdSchema::quote($table));
            DB::statement('CREATE TRIGGER '.HoaNamErdSchema::quote($trigger).' BEFORE UPDATE OR DELETE ON '.HoaNamErdSchema::quote($table).' FOR EACH ROW EXECUTE FUNCTION hn_erd_reject_append_only_change()');
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_reject_posted_change()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'POSTED' THEN
                    RAISE EXCEPTION 'POSTED % row is immutable; use reversal/correction', TG_TABLE_NAME;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (['inbound_documents', 'outbound_documents', 'reversal_documents', 'component_issue_documents'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $trigger = substr('trg_erd_'.$table.'_posted_immutable', 0, 63);
            DB::statement('DROP TRIGGER IF EXISTS '.HoaNamErdSchema::quote($trigger).' ON '.HoaNamErdSchema::quote($table));
            DB::statement('CREATE TRIGGER '.HoaNamErdSchema::quote($trigger).' BEFORE UPDATE OR DELETE ON '.HoaNamErdSchema::quote($table).' FOR EACH ROW EXECUTE FUNCTION hn_erd_reject_posted_change()');
        }
    }

    /** @param array<int,string> $fields */
    private function ensureCanonicalKey(string $table, array $fields): void
    {
        if (collect($fields)->contains(fn (string $field): bool => ! Schema::hasColumn($table, $field))) {
            throw new RuntimeException('ERD key field missing on '.$table.': '.implode(',', $fields));
        }

        $hasPrimary = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conrelid=?::regclass AND contype=\'p\' LIMIT 1', [$table]) !== null;
        if (! $hasPrimary) {
            $name = substr('pk_erd_'.$table, 0, 63);
            DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($table).' ADD CONSTRAINT '.HoaNamErdSchema::quote($name).' PRIMARY KEY ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');

            return;
        }

        // Legacy runtime tables may still have a historical PK column referenced by old
        // FKs. Preserve that constraint and enforce the documented canonical key with an
        // equivalent non-null unique key until callers are fully migrated.
        $this->addUnique($table, $fields, 'ux_erd_canonical_key_'.$table);
    }

    /** @param array<int,string> $fields */
    private function addUnique(string $table, array $fields, string $name): void
    {
        if (collect($fields)->contains(fn (string $field): bool => ! Schema::hasColumn($table, $field))) {
            return;
        }
        $name = substr($name, 0, 63);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.HoaNamErdSchema::quote($name).' ON '.HoaNamErdSchema::quote($table).' ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');
    }

    private function addForeignKey(string $table, string $field, string $parent, string $parentField): void
    {
        if (! Schema::hasColumn($table, $field) || ! Schema::hasColumn($parent, $parentField)) {
            throw new RuntimeException("ERD FK target missing: {$table}.{$field} -> {$parent}.{$parentField}");
        }

        $exists = collect(Schema::getForeignKeys($table))->contains(function (array $fk) use ($field, $parent, $parentField): bool {
            $columns = $fk['columns'] ?? [];
            $foreignColumns = $fk['foreign_columns'] ?? $fk['foreignColumns'] ?? [];
            $foreignTable = $fk['foreign_table'] ?? $fk['foreignTable'] ?? null;

            return $columns === [$field] && $foreignTable === $parent && $foreignColumns === [$parentField];
        });
        if ($exists) {
            return;
        }

        if (DB::table("{$table} as c")
            ->leftJoin("{$parent} as p", "p.{$parentField}", '=', "c.{$field}")
            ->whereNotNull("c.{$field}")
            ->whereNull("p.{$parentField}")
            ->exists()) {
            throw new RuntimeException("ERD FK blocked by orphan rows: {$table}.{$field} -> {$parent}.{$parentField}");
        }

        $name = substr('fk_erd_'.$table.'_'.$field, 0, 63);
        try {
            DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($table).' ADD CONSTRAINT '.HoaNamErdSchema::quote($name).' FOREIGN KEY ('.HoaNamErdSchema::quote($field).') REFERENCES '.HoaNamErdSchema::quote($parent).'('.HoaNamErdSchema::quote($parentField).') ON DELETE RESTRICT');
        } catch (Throwable $e) {
            if (! str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
        }
    }

    /** @return array<int,string> */
    private function parseColumns(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $field): string => strtolower(trim(preg_replace('/\s+.*/', '', $field) ?? $field)))
            ->filter(fn (string $field): bool => preg_match('/^[a-z0-9_]+$/', $field) === 1)
            ->values()->all();
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conrelid=?::regclass AND conname=? LIMIT 1', [$table, $name]) !== null;
    }
};
