<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration 2026_08_10_000791 replaced the singular `defect_catalog` table with
 * a compatibility view (backed by its own INSTEAD OF trigger keyed on
 * defect_id) but left the older AFTER-statement mirror trigger from
 * 2026_08_10_000768 attached to `defect_catalogs`. That trigger still runs
 * `INSERT INTO defect_catalog (...) ON CONFLICT (id) DO UPDATE ...` on every
 * write, which now fails with SQLSTATE 42P10 (no unique/exclusion constraint
 * matches ON CONFLICT) because `defect_catalog` is a view, not a table —
 * breaking every POST/PATCH on /defect-catalogs. The view already has its own
 * up-to-date write trigger, so the stale one is redundant and unsafe to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_erd_mirror_defect_catalog_from_runtime ON defect_catalogs');
        DB::statement('DROP FUNCTION IF EXISTS hn_erd_mirror_defect_catalog()');
    }

    public function down(): void
    {
        // Fail-forward: the view-backed compatibility trigger already covers
        // defect_catalog writes, so there is nothing to restore.
    }
};
