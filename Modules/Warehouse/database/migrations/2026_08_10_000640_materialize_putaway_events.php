<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS putaway_events');
        if (Schema::hasTable('putaway_events')) {
            return;
        }

        Schema::create('putaway_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('container_id')->constrained('containers', 'container_id')->restrictOnDelete();
            $table->foreignUuid('from_parent_container_id')->nullable()->constrained('containers', 'container_id')->nullOnDelete();
            $table->foreignUuid('to_parent_container_id')->nullable()->constrained('containers', 'container_id')->nullOnDelete();
            $table->string('from_location_hint', 255)->nullable();
            $table->string('to_location_hint', 255)->nullable();
            $table->string('status', 20)->default('CONFIRMED');
            $table->timestamp('confirmed_at')->useCurrent();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['container_id', 'confirmed_at'], 'idx_putaway_events_container_time');
            $table->index(['warehouse_id', 'confirmed_at'], 'idx_putaway_events_warehouse_time');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE putaway_events ADD CONSTRAINT chk_putaway_event_status CHECK (status = 'CONFIRMED')");
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION hn_reject_putaway_event_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'putaway_events is append-only; UPDATE/DELETE is forbidden';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER trg_putaway_events_append_only BEFORE UPDATE OR DELETE ON putaway_events FOR EACH ROW EXECUTE FUNCTION hn_reject_putaway_event_change()');
        }
    }

    public function down(): void
    {
        // Putaway evidence is append-only and intentionally retained.
    }
};
