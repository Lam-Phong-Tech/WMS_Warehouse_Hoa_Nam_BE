<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Legacy column compatibility now lives on the singular views. The old
        // runtime normalizer on containers references columns that are no longer
        // part of the canonical physical table.
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_normalize_runtime_containers ON containers');
        DB::statement('DROP FUNCTION IF EXISTS hn_erd_normalize_runtime_containers()');
    }

    public function down(): void
    {
        // Fail-forward cleanup.
    }
};
