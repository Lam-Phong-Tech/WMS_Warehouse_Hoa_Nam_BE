<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropSupersededScope();
        $this->alignPhysicalCodeTargets();
        $this->alignWarrantyContract();
        $this->alignMovementContract();
        $this->alignReversalContract();
        $this->alignComponentIssueContract();
        $this->alignPrinterContract();
        $this->enforceOperationalRelationships();
        $this->dropQuantityDependentViewsOnPostgres();
        $this->enforceIntegerQuantitiesOnPostgres();
        $this->refreshCanonicalViews();
        $this->addPostgresChecks();
    }

    public function down(): void
    {
        // Current-baseline hardening is intentionally fail-forward. Reverting these
        // constraints would re-enable states/relationships explicitly superseded by
        // HN-WMS-ERD-DD-001 and HN-BA-MASTER-DEVTEST-001.
    }

    private function dropSupersededScope(): void
    {
        foreach ([
            'safe_stock_alerts_history',
            'safe_stock_configs',
            'stock_request_lines',
            'stock_requests',
            'in_transit_ledger',
            'inventory_report_snapshots',
            'inventory_reservations',
            'stocktake_variance',
            'stocktake_count',
            'stocktake_snapshot',
            'stocktake_session',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function alignPhysicalCodeTargets(): void
    {
        if (Schema::hasTable('physical_codes')) {
            Schema::table('physical_codes', function (Blueprint $table): void {
                if (! Schema::hasColumn('physical_codes', 'assigned_object_type')) {
                    $table->string('assigned_object_type', 30)->nullable()->after('carrier_type');
                }
                if (! Schema::hasColumn('physical_codes', 'assigned_object_id')) {
                    $table->uuid('assigned_object_id')->nullable()->after('assigned_object_type');
                }
            });

            if (Schema::hasTable('item_instances')) {
                DB::table('item_instances')
                    ->whereNotNull('physical_code_id')
                    ->orderBy('item_id')
                    ->each(function (object $item): void {
                        DB::table('physical_codes')
                            ->where('physical_code_id', $item->physical_code_id)
                            ->whereNull('assigned_object_id')
                            ->update([
                                'assigned_object_type' => 'ITEM',
                                'assigned_object_id' => $item->item_id,
                            ]);
                    });
            }

            $this->addIndex('physical_codes', ['assigned_object_type', 'assigned_object_id'], 'idx_physical_codes_assigned_target');
        }

        if (Schema::hasTable('physical_code')) {
            Schema::table('physical_code', function (Blueprint $table): void {
                if (! Schema::hasColumn('physical_code', 'hardware_uid')) {
                    $table->string('hardware_uid', 150)->nullable();
                }
            });
            $this->addUnique('physical_code', ['hardware_uid'], 'uk_physical_code_hardware_uid');
        }
    }

    private function alignWarrantyContract(): void
    {
        if (! Schema::hasTable('warranty_case')) {
            return;
        }

        Schema::table('warranty_case', function (Blueprint $table): void {
            if (! Schema::hasColumn('warranty_case', 'item_id')) {
                $table->uuid('item_id')->nullable();
            }
            if (! Schema::hasColumn('warranty_case', 'customer_address')) {
                $table->text('customer_address')->nullable();
            }
            if (! Schema::hasColumn('warranty_case', 'purchase_date')) {
                $table->date('purchase_date')->nullable();
            }
            if (! Schema::hasColumn('warranty_case', 'sales_trace_status')) {
                $table->string('sales_trace_status', 30)->nullable();
            }
        });

        $this->addIndex('warranty_case', ['item_id', 'status'], 'idx_warranty_case_item_status');
        $this->addForeignKey('warranty_case', 'item_id', 'item_instances', 'item_id', 'fk_warranty_case_item', 'set null');
    }

    private function alignMovementContract(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'source_type')) {
                $table->string('source_type', 40)->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'source_id')) {
                $table->uuid('source_id')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'source_line_id')) {
                $table->uuid('source_line_id')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'item_id')) {
                $table->uuid('item_id')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'container_id')) {
                $table->uuid('container_id')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'correction_chain_id')) {
                $table->uuid('correction_chain_id')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'idempotency_record_id')) {
                $table->uuid('idempotency_record_id')->nullable();
            }
        });

        DB::table('stock_movements')->whereNull('source_type')->whereNotNull('stock_document_id')->update([
            'source_type' => 'STOCK_DOCUMENT',
            'source_id' => DB::raw('stock_document_id'),
            'source_line_id' => DB::raw('stock_document_line_id'),
        ]);

        $this->addIndex('stock_movements', ['source_type', 'source_id'], 'idx_stock_movements_source');
        $this->addIndex('stock_movements', ['original_movement_id'], 'idx_stock_movements_original');
        $this->addIndex('stock_movements', ['correction_chain_id'], 'idx_stock_movements_correction_chain');
        $this->addForeignKey('stock_movements', 'original_movement_id', 'stock_movements', 'id', 'fk_stock_movements_original', 'restrict');
        $this->addForeignKey('stock_movements', 'item_id', 'item_instances', 'item_id', 'fk_stock_movements_item', 'set null');
        $this->addForeignKey('stock_movements', 'container_id', 'containers', 'container_id', 'fk_stock_movements_container', 'set null');
    }

    private function alignReversalContract(): void
    {
        if (Schema::hasTable('reversal_document')) {
            Schema::table('reversal_document', function (Blueprint $table): void {
                if (! Schema::hasColumn('reversal_document', 'correction_chain_id')) {
                    $table->uuid('correction_chain_id')->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'condition_status')) {
                    $table->string('condition_status', 30)->nullable();
                }
                if (! Schema::hasColumn('reversal_document', 'idempotency_key')) {
                    $table->string('idempotency_key', 100)->nullable();
                }
            });
            $this->addIndex('reversal_document', ['correction_chain_id'], 'idx_reversal_document_chain');
            $this->addUnique('reversal_document', ['idempotency_key'], 'uk_reversal_document_idempotency');
        }

        if (Schema::hasTable('reversal_line')) {
            Schema::table('reversal_line', function (Blueprint $table): void {
                if (! Schema::hasColumn('reversal_line', 'sku_id')) {
                    $table->uuid('sku_id')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'item_id')) {
                    $table->uuid('item_id')->nullable();
                }
                if (! Schema::hasColumn('reversal_line', 'stock_status')) {
                    $table->string('stock_status', 30)->nullable();
                }
            });
            $this->addForeignKey('reversal_line', 'sku_id', 'sku', 'sku_id', 'fk_reversal_line_sku', 'restrict');
            $this->addForeignKey('reversal_line', 'item_id', 'item_instances', 'item_id', 'fk_reversal_line_item', 'set null');
        }
    }

    private function alignComponentIssueContract(): void
    {
        if (! Schema::hasTable('component_issue_documents')) {
            return;
        }

        Schema::table('component_issue_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('component_issue_documents', 'purpose')) {
                $table->string('purpose', 20)->default('WARRANTY');
            }
            if (! Schema::hasColumn('component_issue_documents', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable();
            }
        });
        $this->addUnique('component_issue_documents', ['idempotency_key'], 'uk_component_issue_idempotency');
    }

    private function alignPrinterContract(): void
    {
        if (! Schema::hasTable('printer_configs')) {
            return;
        }

        Schema::table('printer_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('printer_configs', 'agent_endpoint')) {
                $table->string('agent_endpoint', 500)->nullable();
            }
            if (! Schema::hasColumn('printer_configs', 'status')) {
                $table->string('status', 20)->default('ACTIVE');
            }
        });
    }

    private function enforceOperationalRelationships(): void
    {
        $relations = [
            ['sku_compatible_accessories', 'sku_id', 'sku', 'sku_id', 'fk_sku_accessories_sku', 'cascade'],
            ['sku_compatible_accessories', 'accessory_sku_id', 'sku', 'sku_id', 'fk_sku_accessories_accessory', 'cascade'],
            ['product_models', 'brand_id', 'brands', 'id', 'fk_product_models_brand', 'set null'],
            ['inbound_scan_entries', 'inbound_line_id', 'stock_document_lines', 'id', 'fk_inbound_scan_line', 'cascade'],
            ['inbound_scan_entries', 'item_id', 'item_instances', 'item_id', 'fk_inbound_scan_item', 'set null'],
            ['inbound_scan_entries', 'container_id', 'containers', 'container_id', 'fk_inbound_scan_container', 'set null'],
            ['outbound_request_import_rows', 'import_id', 'outbound_request_imports', 'id', 'fk_outbound_import_row_header', 'cascade'],
            ['outbound_requests', 'import_id', 'outbound_request_imports', 'id', 'fk_outbound_request_import', 'set null'],
            ['outbound_requests', 'agency_id', 'agencies', 'agency_id', 'fk_outbound_request_agency', 'set null'],
            ['outbound_request_lines', 'outbound_request_id', 'outbound_requests', 'id', 'fk_outbound_request_line_header', 'cascade'],
            ['outbound_request_lines', 'sku_id', 'sku', 'sku_id', 'fk_outbound_request_line_sku', 'restrict'],
            ['outbound_item_matches', 'outbound_line_id', 'stock_document_lines', 'id', 'fk_outbound_match_line', 'cascade'],
            ['outbound_item_matches', 'item_id', 'item_instances', 'item_id', 'fk_outbound_match_item', 'set null'],
            ['outbound_item_matches', 'container_id', 'containers', 'container_id', 'fk_outbound_match_container', 'set null'],
            ['label_snapshots', 'outbound_item_match_id', 'outbound_item_matches', 'id', 'fk_label_snapshot_match', 'restrict'],
            ['label_snapshots', 'sku_id', 'sku', 'sku_id', 'fk_label_snapshot_sku', 'restrict'],
            ['label_snapshots', 'item_id', 'item_instances', 'item_id', 'fk_label_snapshot_item', 'set null'],
            ['label_print_events', 'label_snapshot_id', 'label_snapshots', 'id', 'fk_label_print_snapshot', 'cascade'],
            ['label_print_events', 'printer_config_id', 'printer_configs', 'id', 'fk_label_print_printer', 'set null'],
            ['warranty_attachments', 'warranty_case_id', 'warranty_case', 'warranty_case_id', 'fk_warranty_attachment_case', 'cascade'],
            ['component_issue_documents', 'warehouse_id', 'warehouses', 'id', 'fk_component_issue_warehouse', 'restrict'],
            ['component_issue_documents', 'warranty_case_id', 'warranty_case', 'warranty_case_id', 'fk_component_issue_warranty', 'restrict'],
            ['component_issue_lines', 'component_issue_document_id', 'component_issue_documents', 'id', 'fk_component_issue_line_header', 'cascade'],
            ['component_issue_lines', 'sku_id', 'sku', 'sku_id', 'fk_component_issue_line_sku', 'restrict'],
            ['component_issue_items', 'component_issue_line_id', 'component_issue_lines', 'id', 'fk_component_issue_item_line', 'cascade'],
            ['component_issue_items', 'item_id', 'item_instances', 'item_id', 'fk_component_issue_item_item', 'set null'],
            ['component_issue_items', 'container_id', 'containers', 'container_id', 'fk_component_issue_item_container', 'set null'],
            ['pv_product_images', 'profile_id', 'pv_product_profile', 'profile_id', 'fk_pv_product_image_profile', 'cascade'],
            ['migration_errors', 'migration_run_id', 'migration_runs', 'id', 'fk_migration_error_run', 'cascade'],
        ];

        foreach ($relations as [$table, $column, $parent, $parentColumn, $name, $delete]) {
            $this->addForeignKey($table, $column, $parent, $parentColumn, $name, $delete);
        }

        $this->addUnique('inventory_balances', ['warehouse_id', 'sku_id', 'stock_status'], 'uk_inventory_balances_wh_sku_status');
        $this->addUnique('outbound_request_imports', ['source_checksum'], 'uk_outbound_import_checksum');
        $this->addIndex('component_issue_lines', ['sku_id'], 'idx_component_issue_line_sku');
        $this->addIndex('outbound_request_lines', ['sku_id'], 'idx_outbound_request_line_sku');
    }

    private function enforceIntegerQuantitiesOnPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            ['inventory_balances', 'on_hand_qty'],
            ['inventory_balances', 'available_qty'],
            ['inventory_balances', 'reserved_qty'],
            ['inventory_balances', 'quantity'],
            ['stock_movements', 'qty_before'],
            ['stock_movements', 'qty_delta'],
            ['stock_movements', 'qty_after'],
            ['stock_movements', 'quantity'],
            ['stock_document_lines', 'qty_planned'],
            ['stock_document_lines', 'qty_actual'],
            ['stock_document_lines', 'scanned_qty'],
            ['container_item', 'quantity'],
            ['sku_component', 'quantity'],
            ['reversal_line', 'reversal_qty'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->whereNotNull($column)->whereRaw("{$column} <> trunc({$column})")->exists()) {
                throw new RuntimeException("Current ERD requires integer quantity; fractional values exist in {$table}.{$column}.");
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE BIGINT USING {$column}::bigint");
        }
    }

    private function dropQuantityDependentViewsOnPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['inventory_movements', 'inbound_lines', 'outbound_lines', 'reversal_lines'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }

    private function refreshCanonicalViews(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS inventory_movements');
        DB::statement(<<<'SQL'
            CREATE VIEW inventory_movements AS
            SELECT id,
                   movement_no,
                   business_key AS movement_key,
                   movement_type,
                   warehouse_id,
                   COALESCE(sku_id, product_id) AS sku_id,
                   stock_status,
                   quantity AS quantity,
                   item_id,
                   container_id,
                   COALESCE(source_type, 'STOCK_DOCUMENT') AS source_type,
                   COALESCE(source_id, stock_document_id) AS source_id,
                   COALESCE(source_line_id, stock_document_line_id) AS source_line_id,
                   original_movement_id,
                   correction_chain_id,
                   idempotency_record_id,
                   created_by,
                   occurred_at,
                   created_at
            FROM stock_movements
        SQL);

        if (Schema::hasTable('warranty_case')) {
            DB::statement('DROP VIEW IF EXISTS warranty_cases');
            DB::statement(<<<'SQL'
                CREATE VIEW warranty_cases AS
                SELECT warranty_case_id AS id,
                       warranty_case_code,
                       COALESCE(item_id, inventory_serial_id) AS item_id,
                       product_id AS sku_id,
                       temporary_item_code,
                       identification_status,
                       missing_code_reason,
                       manual_product_description,
                       defect_id,
                       customer_name,
                       customer_phone,
                       customer_address,
                       purchase_date,
                       sales_trace_status,
                       status,
                       condition_status,
                       description,
                       created_at,
                       created_by,
                       updated_at,
                       NULL AS updated_by,
                       version,
                       closed_at,
                       retention_until,
                       legal_hold_flag
                FROM warranty_case
            SQL);
        }
    }

    private function addPostgresChecks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'ALTER TABLE inventory_balances ADD CONSTRAINT chk_inventory_balance_non_negative CHECK (quantity >= 0 AND on_hand_qty >= 0 AND available_qty >= 0 AND reserved_qty >= 0)',
            "ALTER TABLE component_issue_documents ADD CONSTRAINT chk_component_issue_purpose CHECK (purpose = 'WARRANTY')",
            'ALTER TABLE component_issue_lines ADD CONSTRAINT chk_component_issue_exact_range CHECK (required_qty > 0 AND scanned_qty >= 0 AND scanned_qty <= required_qty)',
            'ALTER TABLE inbound_scan_entries ADD CONSTRAINT chk_inbound_scan_quantity CHECK (quantity > 0)',
            'ALTER TABLE outbound_item_matches ADD CONSTRAINT chk_outbound_match_quantity CHECK (quantity > 0)',
            'ALTER TABLE component_issue_items ADD CONSTRAINT chk_component_issue_item_quantity CHECK (quantity > 0)',
        ] as $statement) {
            try {
                DB::statement($statement);
            } catch (Throwable) {
                // Constraint already exists on repeatable deployments.
            }
        }
    }

    private function addForeignKey(
        string $table,
        string $column,
        string $parent,
        string $parentColumn,
        string $name,
        string $onDelete
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasTable($parent) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        if (DB::getDriverName() === 'sqlite' || $this->foreignKeyExists($table, $name)) {
            return;
        }

        $this->assertNoOrphans($table, $column, $parent, $parentColumn);
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $parentColumn, $name, $onDelete): void {
            $foreign = $blueprint->foreign($column, $name)->references($parentColumn)->on($parent);
            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'set null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    private function assertNoOrphans(string $table, string $column, string $parent, string $parentColumn): void
    {
        $exists = DB::table("{$table} as c")
            ->leftJoin("{$parent} as p", "p.{$parentColumn}", '=', "c.{$column}")
            ->whereNotNull("c.{$column}")
            ->whereNull("p.{$parentColumn}")
            ->exists();

        if ($exists) {
            throw new RuntimeException("Cannot add {$table}.{$column} relationship: orphan rows exist. Reconcile data before deployment.");
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

        $duplicates = DB::table($table)
            ->select($columns)
            ->whereNotNull($columns[0])
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
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
};
