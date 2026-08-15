<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('label_snapshots') || Schema::hasColumn('label_snapshots', 'reprint_count')) {
            return;
        }

        // Compatibility bridge for legacy migration 000770. The current Packing Label
        // contract derives reprint count from label_print_events and does not persist it
        // on label_snapshots, but 000770 still validates this historical column before
        // the current Flow 06 alignment migration runs. Add it temporarily so fresh and
        // upgrade migration chains remain deterministic; 2026_08_15_140000 removes it.
        Schema::table('label_snapshots', function (Blueprint $table): void {
            $table->unsignedInteger('reprint_count')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('label_snapshots') && Schema::hasColumn('label_snapshots', 'reprint_count')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->dropColumn('reprint_count');
            });
        }
    }
};
