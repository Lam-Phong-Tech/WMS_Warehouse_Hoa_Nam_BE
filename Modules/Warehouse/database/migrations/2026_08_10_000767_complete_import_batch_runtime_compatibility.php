<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('import_batches')) {
            return;
        }

        DB::statement("UPDATE import_batches SET received_date = COALESCE(received_date, created_at::date, CURRENT_DATE) WHERE received_date IS NULL");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_import_batches()
            RETURNS trigger AS $$
            BEGIN
                NEW.id := COALESCE(NEW.id, NEW.import_batch_id);
                NEW.import_batch_id := COALESCE(NEW.import_batch_id, NEW.id);
                NEW.batch_code := COALESCE(NEW.batch_code, NEW.import_batch_code);
                NEW.import_batch_code := COALESCE(NEW.import_batch_code, NEW.batch_code);
                NEW.source_name := COALESCE(NEW.source_name, NEW.supplier_name);
                NEW.reference_no := COALESCE(NEW.reference_no, NEW.supplier_batch_no);
                NEW.received_date := COALESCE(NEW.received_date, NEW.created_at::date, CURRENT_DATE);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward compatibility normalization.
    }
};
