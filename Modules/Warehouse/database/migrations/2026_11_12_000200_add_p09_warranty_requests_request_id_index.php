<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation probes a request ID without knowing its scope, so the
 * (scope_hash, request_id) unique index alone cannot serve the lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('p09_warranty_requests')) {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_p09_warranty_requests_request_id ON p09_warranty_requests (request_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('p09_warranty_requests')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_p09_warranty_requests_request_id');
    }
};
