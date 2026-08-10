<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('component_issue_documents')) {
            Schema::table('component_issue_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('component_issue_documents', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
                if (! Schema::hasColumn('component_issue_documents', 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable();
                }
                if (! Schema::hasColumn('component_issue_documents', 'note')) {
                    $table->text('note')->nullable();
                }
            });
        }

        if (Schema::hasTable('component_issue_lines') && ! Schema::hasColumn('component_issue_lines', 'note')) {
            Schema::table('component_issue_lines', function (Blueprint $table): void {
                $table->text('note')->nullable();
            });
        }

        if (Schema::hasTable('component_issue_items')) {
            Schema::table('component_issue_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('component_issue_items', 'scan_event_id')) {
                    $table->uuid('scan_event_id')->nullable();
                }
                if (! Schema::hasColumn('component_issue_items', 'status')) {
                    $table->string('status', 20)->default('ACTIVE');
                }
                if (! Schema::hasColumn('component_issue_items', 'selected_at')) {
                    $table->timestamp('selected_at')->nullable();
                }
                if (! Schema::hasColumn('component_issue_items', 'selected_by')) {
                    $table->uuid('selected_by')->nullable();
                }
            });
            DB::table('component_issue_items')->whereNull('selected_at')->update(['selected_at' => DB::raw('created_at')]);
            DB::table('component_issue_items')->whereNull('selected_by')->whereNotNull('created_by')->update(['selected_by' => DB::raw('created_by')]);

            $this->addForeignKey('component_issue_items', 'scan_event_id', 'scan_event', 'scan_event_id', 'fk_component_issue_item_scan_event', 'set null');
            $this->addForeignKey('component_issue_items', 'selected_by', 'app_user', 'user_id', 'fk_component_issue_item_selected_by', 'set null');
            $this->addIndex('component_issue_items', ['component_issue_line_id', 'status'], 'idx_component_issue_item_line_status');
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresGuards();
        }
    }

    public function down(): void
    {
        // Current baseline is fail-forward.
    }

    private function addPostgresGuards(): void
    {
        foreach ([
            "ALTER TABLE component_issue_documents ADD CONSTRAINT chk_component_issue_status_current CHECK (status IN ('DRAFT','SCANNING','POSTED','CANCELLED'))",
            "ALTER TABLE component_issue_documents ADD CONSTRAINT chk_component_issue_purpose_current CHECK (purpose = 'WARRANTY')",
            "ALTER TABLE component_issue_lines ADD CONSTRAINT chk_component_issue_required_qty CHECK (required_qty > 0)",
            "ALTER TABLE component_issue_lines ADD CONSTRAINT chk_component_issue_scanned_qty CHECK (scanned_qty >= 0 AND scanned_qty <= required_qty)",
            "ALTER TABLE component_issue_items ADD CONSTRAINT chk_component_issue_item_status_current CHECK (status IN ('ACTIVE','REMOVED','POSTED'))",
            "ALTER TABLE component_issue_items ADD CONSTRAINT chk_component_issue_item_qty_current CHECK (quantity > 0)",
        ] as $statement) {
            try {
                DB::statement($statement);
            } catch (Throwable $e) {
                $message = strtolower($e->getMessage());
                if (! str_contains($message, 'already exists') && ! str_contains($message, 'duplicate object')) {
                    throw $e;
                }
            }
        }
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uk_component_issue_active_item ON component_issue_items(item_id) WHERE item_id IS NOT NULL AND status = 'ACTIVE'");
    }

    private function addForeignKey(string $table, string $column, string $parent, string $parentColumn, string $name, string $delete): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable($table) || ! Schema::hasTable($parent) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            if (collect(Schema::getForeignKeys($table))->contains(fn (array $fk): bool => ($fk['name'] ?? null) === $name)) {
                return;
            }
        } catch (Throwable) {
        }
        if (DB::table("{$table} as c")->leftJoin("{$parent} as p", "p.{$parentColumn}", '=', "c.{$column}")->whereNotNull("c.{$column}")->whereNull("p.{$parentColumn}")->exists()) {
            throw new RuntimeException("Cannot add {$table}.{$column} relationship: orphan rows exist.");
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $parentColumn, $name, $delete): void {
            $foreign = $blueprint->foreign($column, $name)->references($parentColumn)->on($parent);
            match ($delete) {
                'set null' => $foreign->nullOnDelete(),
                'cascade' => $foreign->cascadeOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        try {
            if (collect(Schema::getIndexes($table))->contains(fn (array $index): bool => ($index['name'] ?? null) === $name)) {
                return;
            }
        } catch (Throwable) {
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }
};
