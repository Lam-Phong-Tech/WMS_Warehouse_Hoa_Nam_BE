<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'cache_locks',
            'cache',
            'failed_jobs',
            'job_batches',
            'jobs',
            'agency',
            'import_batch',
            'product_master',
            'pv_view_event',
            'migration_batch',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // These tables were legacy/framework leftovers outside HoaNam_ERD_Moi_Nhat.md.
        // Recreate by rolling back to the migrations that originally owned them.
    }
};
