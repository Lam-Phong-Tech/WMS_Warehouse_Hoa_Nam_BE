<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignPhysicalCodes();
        $this->alignContainers();
        $this->alignContainerItems();
        $this->alignScanEntries();
        $this->alignOutboundMatches();
        $this->alignComponentItems();
        $this->alignLabels();

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresConstraints();
        }
    }

    public function down(): void
    {
        // Fail-forward baseline alignment. History/audit fields are intentionally retained.
    }

    private function alignPhysicalCodes(): void
    {
        if (! Schema::hasTable('physical_codes')) {
            return;
        }
        Schema::table('physical_codes', function (Blueprint $table): void {
            if (! Schema::hasColumn('physical_codes', 'item_id')) {
                $table->uuid('item_id')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'container_id')) {
                $table->uuid('container_id')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'is_primary')) {
                $table->boolean('is_primary')->default(false);
            }
            if (! Schema::hasColumn('physical_codes', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'assigned_by')) {
                $table->uuid('assigned_by')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'deactivation_reason')) {
                $table->text('deactivation_reason')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'created_by')) {
                $table->uuid('created_by')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'updated_by')) {
                $table->uuid('updated_by')->nullable();
            }
            if (! Schema::hasColumn('physical_codes', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
        });

        DB::table('physical_codes')->where('assigned_object_type', 'ITEM')->whereNotNull('assigned_object_id')->update(['item_id' => DB::raw('assigned_object_id')]);
        DB::table('physical_codes')->where('assigned_object_type', 'CONTAINER')->whereNotNull('assigned_object_id')->update(['container_id' => DB::raw('assigned_object_id')]);
        DB::table('physical_codes')->whereNull('assigned_at')->update(['assigned_at' => DB::raw('created_at')]);

        $this->addForeignKey('physical_codes', 'item_id', 'item_instances', 'item_id', 'fk_physical_codes_item', 'cascade');
        $this->addForeignKey('physical_codes', 'container_id', 'containers', 'container_id', 'fk_physical_codes_container', 'cascade');
        $this->addForeignKey('physical_codes', 'assigned_by', 'app_user', 'user_id', 'fk_physical_codes_assigned_by', 'set null');
        $this->addForeignKey('physical_codes', 'created_by', 'app_user', 'user_id', 'fk_physical_codes_created_by', 'set null');
        $this->addForeignKey('physical_codes', 'updated_by', 'app_user', 'user_id', 'fk_physical_codes_updated_by', 'set null');
        $this->addIndex('physical_codes', ['item_id', 'status'], 'idx_physical_codes_item_status');
        $this->addIndex('physical_codes', ['container_id', 'status'], 'idx_physical_codes_container_status');
    }

    private function alignContainers(): void
    {
        if (! Schema::hasTable('containers')) {
            return;
        }
        Schema::table('containers', function (Blueprint $table): void {
            if (! Schema::hasColumn('containers', 'migration_run_id')) {
                $table->uuid('migration_run_id')->nullable();
            }
            if (! Schema::hasColumn('containers', 'created_by')) {
                $table->uuid('created_by')->nullable();
            }
            if (! Schema::hasColumn('containers', 'updated_by')) {
                $table->uuid('updated_by')->nullable();
            }
        });
        $this->addForeignKey('containers', 'migration_run_id', 'migration_runs', 'id', 'fk_containers_migration_run', 'set null');
        $this->addForeignKey('containers', 'created_by', 'app_user', 'user_id', 'fk_containers_created_by', 'set null');
        $this->addForeignKey('containers', 'updated_by', 'app_user', 'user_id', 'fk_containers_updated_by', 'set null');
        $this->addIndex('containers', ['container_type', 'status'], 'idx_containers_type_status');
        $this->addIndex('containers', ['parent_container_id'], 'idx_containers_parent');
        $this->addIndex('containers', ['primary_sku_id'], 'idx_containers_primary_sku');
    }

    private function alignContainerItems(): void
    {
        if (! Schema::hasTable('container_items')) {
            return;
        }
        Schema::table('container_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('container_items', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('container_items', 'placed_at')) {
                $table->timestamp('placed_at')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'placed_by')) {
                $table->uuid('placed_by')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'removed_at')) {
                $table->timestamp('removed_at')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'removed_by')) {
                $table->uuid('removed_by')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'source_type')) {
                $table->string('source_type', 50)->nullable();
            }
            if (! Schema::hasColumn('container_items', 'source_id')) {
                $table->uuid('source_id')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'created_by')) {
                $table->uuid('created_by')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'updated_by')) {
                $table->uuid('updated_by')->nullable();
            }
            if (! Schema::hasColumn('container_items', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
        });
        DB::table('container_items')->update([
            'is_active' => DB::raw("CASE WHEN status = 'ACTIVE' THEN TRUE ELSE FALSE END"),
            'placed_at' => DB::raw('COALESCE(placed_at, created_at)'),
        ]);
        $this->addForeignKey('container_items', 'placed_by', 'app_user', 'user_id', 'fk_container_items_placed_by', 'set null');
        $this->addForeignKey('container_items', 'removed_by', 'app_user', 'user_id', 'fk_container_items_removed_by', 'set null');
        $this->addForeignKey('container_items', 'created_by', 'app_user', 'user_id', 'fk_container_items_created_by', 'set null');
        $this->addForeignKey('container_items', 'updated_by', 'app_user', 'user_id', 'fk_container_items_updated_by', 'set null');
        $this->addIndex('container_items', ['container_id', 'is_active'], 'idx_container_items_active');
        $this->addIndex('container_items', ['sku_id', 'is_active'], 'idx_container_items_sku_active');
    }

    private function alignScanEntries(): void
    {
        if (! Schema::hasTable('inbound_scan_entries')) {
            return;
        }
        Schema::table('inbound_scan_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('inbound_scan_entries', 'status')) {
                $table->string('status', 20)->default('ACTIVE');
            }
            if (! Schema::hasColumn('inbound_scan_entries', 'captured_at')) {
                $table->timestamp('captured_at')->nullable();
            }
            if (! Schema::hasColumn('inbound_scan_entries', 'captured_by')) {
                $table->uuid('captured_by')->nullable();
            }
            if (! Schema::hasColumn('inbound_scan_entries', 'removed_at')) {
                $table->timestamp('removed_at')->nullable();
            }
            if (! Schema::hasColumn('inbound_scan_entries', 'removed_by')) {
                $table->uuid('removed_by')->nullable();
            }
        });
        DB::table('inbound_scan_entries')->whereNull('captured_at')->update(['captured_at' => DB::raw('created_at')]);
        DB::table('inbound_scan_entries')->whereNull('captured_by')->whereNotNull('created_by')->update(['captured_by' => DB::raw('created_by')]);
        $this->addForeignKey('inbound_scan_entries', 'captured_by', 'app_user', 'user_id', 'fk_inbound_scan_captured_by', 'set null');
        $this->addForeignKey('inbound_scan_entries', 'removed_by', 'app_user', 'user_id', 'fk_inbound_scan_removed_by', 'set null');
        $this->addIndex('inbound_scan_entries', ['inbound_line_id', 'status'], 'idx_inbound_scan_line_status');
    }

    private function alignOutboundMatches(): void
    {
        if (! Schema::hasTable('outbound_item_matches')) {
            return;
        }
        Schema::table('outbound_item_matches', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_item_matches', 'status')) {
                $table->string('status', 20)->default('ACTIVE');
            }
            if (! Schema::hasColumn('outbound_item_matches', 'matched_at')) {
                $table->timestamp('matched_at')->nullable();
            }
            if (! Schema::hasColumn('outbound_item_matches', 'matched_by')) {
                $table->uuid('matched_by')->nullable();
            }
            if (! Schema::hasColumn('outbound_item_matches', 'removed_at')) {
                $table->timestamp('removed_at')->nullable();
            }
            if (! Schema::hasColumn('outbound_item_matches', 'removed_by')) {
                $table->uuid('removed_by')->nullable();
            }
        });
        DB::table('outbound_item_matches')->whereNull('matched_at')->update(['matched_at' => DB::raw('created_at')]);
        DB::table('outbound_item_matches')->whereNull('matched_by')->whereNotNull('created_by')->update(['matched_by' => DB::raw('created_by')]);
        $this->addForeignKey('outbound_item_matches', 'matched_by', 'app_user', 'user_id', 'fk_outbound_match_matched_by', 'set null');
        $this->addForeignKey('outbound_item_matches', 'removed_by', 'app_user', 'user_id', 'fk_outbound_match_removed_by', 'set null');
        $this->addIndex('outbound_item_matches', ['outbound_line_id', 'status'], 'idx_outbound_match_line_status');
    }

    private function alignComponentItems(): void
    {
        if (! Schema::hasTable('component_issue_items')) {
            return;
        }
        $this->addIndex('component_issue_items', ['item_id'], 'idx_component_issue_items_item');
        $this->addIndex('component_issue_items', ['container_id'], 'idx_component_issue_items_container');
    }

    private function alignLabels(): void
    {
        if (! Schema::hasTable('label_snapshots')) {
            return;
        }
        $this->addUnique('label_snapshots', ['outbound_item_match_id'], 'uk_label_snapshot_one_per_match');
    }

    private function addPostgresConstraints(): void
    {
        foreach ([
            "ALTER TABLE physical_codes ADD CONSTRAINT chk_physical_codes_target_xor CHECK ((item_id IS NOT NULL)::int + (container_id IS NOT NULL)::int = 1)",
            "ALTER TABLE physical_codes ADD CONSTRAINT chk_physical_codes_status_current CHECK (status IN ('ACTIVE','INACTIVE','LOST','DAMAGED'))",
            "ALTER TABLE physical_codes ADD CONSTRAINT chk_physical_codes_carrier_current CHECK (carrier_type IN ('BARCODE','QR','RFID','NFC','MANUAL'))",
            "ALTER TABLE containers ADD CONSTRAINT chk_containers_status_current CHECK (status IN ('EMPTY','IN_STOCK','PARTIAL','ISSUED','RECEIVED_PENDING_PUTAWAY','STORED','CLOSED'))",
            "ALTER TABLE containers ADD CONSTRAINT chk_containers_type_current CHECK (container_type IN ('CARTON','BOX','TRAY'))",
            "ALTER TABLE container_items ADD CONSTRAINT chk_container_items_target_xor CHECK ((item_id IS NOT NULL AND sku_id IS NULL AND (quantity IS NULL OR quantity IN (0,1))) OR (item_id IS NULL AND sku_id IS NOT NULL AND quantity > 0))",
            "ALTER TABLE inbound_scan_entries ADD CONSTRAINT chk_inbound_scan_status_current CHECK (status IN ('ACTIVE','REMOVED'))",
            "ALTER TABLE inbound_scan_entries ADD CONSTRAINT chk_inbound_scan_quantity_current CHECK (quantity > 0)",
            "ALTER TABLE outbound_item_matches ADD CONSTRAINT chk_outbound_match_status_current CHECK (status IN ('ACTIVE','REMOVED','POSTED'))",
            "ALTER TABLE outbound_item_matches ADD CONSTRAINT chk_outbound_match_quantity_current CHECK (quantity > 0)",
            "ALTER TABLE component_issue_items ADD CONSTRAINT chk_component_issue_item_target_xor CHECK ((item_id IS NOT NULL)::int + (container_id IS NOT NULL)::int = 1 AND quantity > 0)",
        ] as $statement) {
            $this->statementIgnoreDuplicateConstraint($statement);
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_physical_codes_primary_item_carrier ON physical_codes(item_id, carrier_type) WHERE item_id IS NOT NULL AND is_primary = TRUE AND status = 'ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_physical_codes_primary_container_carrier ON physical_codes(container_id, carrier_type) WHERE container_id IS NOT NULL AND is_primary = TRUE AND status = 'ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_container_items_one_active_item ON container_items(item_id) WHERE item_id IS NOT NULL AND is_active = TRUE");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_inbound_scan_active_item ON inbound_scan_entries(item_id) WHERE item_id IS NOT NULL AND status = 'ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_outbound_match_active_item ON outbound_item_matches(item_id) WHERE item_id IS NOT NULL AND status = 'ACTIVE'");
    }

    private function addForeignKey(string $table, string $column, string $parent, string $parentColumn, string $name, string $delete): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable($table) || ! Schema::hasTable($parent) || ! Schema::hasColumn($table, $column) || $this->foreignKeyExists($table, $name)) {
            return;
        }
        if (DB::table("{$table} as c")->leftJoin("{$parent} as p", "p.{$parentColumn}", '=', "c.{$column}")->whereNotNull("c.{$column}")->whereNull("p.{$parentColumn}")->exists()) {
            throw new RuntimeException("Cannot add {$table}.{$column} FK: orphan rows exist.");
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $parentColumn, $name, $delete): void {
            $foreign = $blueprint->foreign($column, $name)->references($parentColumn)->on($parent);
            match ($delete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'set null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
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
        if (DB::table($table)->select($columns)->whereNotNull($columns[0])->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException("Cannot add {$name}: duplicate baseline data exists.");
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
