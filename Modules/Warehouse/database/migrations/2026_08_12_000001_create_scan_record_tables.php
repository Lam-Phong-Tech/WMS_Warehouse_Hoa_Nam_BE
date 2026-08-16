<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_batches', function (Blueprint $table) {
            $table->uuid('scan_batch_id')->primary();
            $table->string('source_type', 50);
            $table->uuid('source_id')->nullable();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // PostgreSQL canonical ERD materialization retires the physical
            // `app_user` table in 2026_08_10_000790 and recreates `app_user`
            // as a compatibility VIEW backed by `app_users`. PostgreSQL does
            // not allow a FK to target a view, therefore migrations that run
            // after the retirement point must reference the canonical table.
            // SQLite keeps the legacy physical table because the ERD retirement
            // migration is PostgreSQL-only, so retain the legacy target there.
            if (DB::getDriverName() === 'pgsql') {
                $table->foreignUuid('created_by')->nullable()->constrained('app_users', 'id')->nullOnDelete();
            } else {
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            }

            $table->timestamp('created_at')->useCurrent();
            $table->index(['source_type', 'source_id'], 'idx_scan_batches_source');
        });

        Schema::create('scan_record_events', function (Blueprint $table) {
            $table->uuid('scan_event_id')->primary();
            $table->foreignUuid('scan_batch_id')->constrained('scan_batches', 'scan_batch_id')->cascadeOnDelete();
            $table->string('raw_code', 100);
            $table->foreignUuid('physical_code_id')->nullable()->constrained('physical_codes', 'physical_code_id')->nullOnDelete();
            $table->string('resolved_object_type', 50)->nullable();
            $table->uuid('resolved_object_id')->nullable();
            $table->string('result_status', 30)->index();
            $table->string('message', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['resolved_object_type', 'resolved_object_id'], 'idx_scan_record_events_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_record_events');
        Schema::dropIfExists('scan_batches');
    }
};
