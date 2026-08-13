<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD baseline: item_instances.issued_at ghi thời điểm Post Issue
 * (đối xứng với received_at ghi tại Post Receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_instances')) {
            return;
        }

        Schema::table('item_instances', function (Blueprint $table): void {
            if (! Schema::hasColumn('item_instances', 'issued_at')) {
                $table->timestamp('issued_at')->nullable();
            }
        });

        // Backfill best-effort cho item đã ISSUED trước khi có cột.
        DB::table('item_instances')
            ->where('item_status', 'ISSUED')
            ->whereNull('issued_at')
            ->update(['issued_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('item_instances') && Schema::hasColumn('item_instances', 'issued_at')) {
            Schema::table('item_instances', function (Blueprint $table): void {
                $table->dropColumn('issued_at');
            });
        }
    }
};
