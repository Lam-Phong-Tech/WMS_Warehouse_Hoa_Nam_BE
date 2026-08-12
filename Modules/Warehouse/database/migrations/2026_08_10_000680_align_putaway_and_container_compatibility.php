<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('containers') && Schema::hasColumn('containers', 'physical_code_id')) {
            // physical_codes.container_id is the canonical relationship. The old
            // one-code FK on containers is compatibility-only and must not block
            // creating a container before assigning one or more codes.
            DB::statement('ALTER TABLE containers ALTER COLUMN physical_code_id DROP NOT NULL');
        }

        $this->alignPutawayEvents();
        if (DB::getDriverName() === 'pgsql') {
            $this->alignPutawayGuards();
        }
    }

    public function down(): void
    {
        // Current baseline is fail-forward.
    }

    private function alignPutawayEvents(): void
    {
        if (! Schema::hasTable('putaway_events')) {
            return;
        }
        Schema::table('putaway_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('putaway_events', 'box_container_id')) {
                $table->uuid('box_container_id')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'from_tray_id')) {
                $table->uuid('from_tray_id')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'to_tray_id')) {
                $table->uuid('to_tray_id')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'location_hint')) {
                $table->string('location_hint', 200)->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'confirmed_by')) {
                $table->uuid('confirmed_by')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'note')) {
                $table->text('note')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'updated_by')) {
                $table->uuid('updated_by')->nullable();
            }
            if (! Schema::hasColumn('putaway_events', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
        });
        DB::table('putaway_events')->whereNull('box_container_id')->update(['box_container_id' => DB::raw('container_id')]);
        DB::table('putaway_events')->whereNull('from_tray_id')->whereNotNull('from_parent_container_id')->update(['from_tray_id' => DB::raw('from_parent_container_id')]);
        DB::table('putaway_events')->whereNull('to_tray_id')->whereNotNull('to_parent_container_id')->update(['to_tray_id' => DB::raw('to_parent_container_id')]);
        DB::table('putaway_events')->whereNull('location_hint')->whereNotNull('to_location_hint')->update(['location_hint' => DB::raw('to_location_hint')]);
        DB::table('putaway_events')->whereNull('confirmed_by')->whereNotNull('created_by')->update(['confirmed_by' => DB::raw('created_by')]);

        $this->addForeignKey('putaway_events', 'box_container_id', 'containers', 'container_id', 'fk_putaway_box', 'restrict');
        $this->addForeignKey('putaway_events', 'from_tray_id', 'containers', 'container_id', 'fk_putaway_from_tray', 'set null');
        $this->addForeignKey('putaway_events', 'to_tray_id', 'containers', 'container_id', 'fk_putaway_to_tray', 'restrict');
        $this->addForeignKey('putaway_events', 'confirmed_by', 'app_user', 'user_id', 'fk_putaway_confirmed_by', 'set null');
        $this->addIndex('putaway_events', ['box_container_id', 'created_at'], 'idx_putaway_box_created');
        $this->addIndex('putaway_events', ['to_tray_id', 'status'], 'idx_putaway_tray_status');
    }

    private function alignPutawayGuards(): void
    {
        DB::statement('ALTER TABLE putaway_events DROP CONSTRAINT IF EXISTS chk_putaway_event_status');
        try {
            DB::statement("ALTER TABLE putaway_events ADD CONSTRAINT chk_putaway_event_status_current CHECK (status IN ('DRAFT','CONFIRMED','CANCELLED'))");
        } catch (Throwable $e) {
            if (! str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_reject_putaway_event_change()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'putaway_events history cannot be deleted';
                END IF;
                IF OLD.status IN ('CONFIRMED','CANCELLED') THEN
                    RAISE EXCEPTION 'confirmed/cancelled putaway event is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
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
