<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('defect_catalog')) {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_defect_catalog_id ON defect_catalog(id)');
    }

    public function down(): void
    {
        // Required by the runtime -> canonical mirror conflict target.
    }
};
