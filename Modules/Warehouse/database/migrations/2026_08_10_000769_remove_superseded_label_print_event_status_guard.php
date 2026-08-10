<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_print_events')) {
            return;
        }

        DB::statement('ALTER TABLE label_print_events DROP CONSTRAINT IF EXISTS chk_label_print_current_status');
    }

    public function down(): void
    {
        // The old constraint encoded snapshot states on print-event rows and must not return.
    }
};
