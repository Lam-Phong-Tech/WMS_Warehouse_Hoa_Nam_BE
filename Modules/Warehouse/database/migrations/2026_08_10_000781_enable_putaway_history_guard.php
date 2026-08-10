<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('putaway_events')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_reject_putaway_event_change()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'putaway_events history cannot be deleted';
                END IF;
                IF OLD.status IN ('CONFIRMED','CANCELLED') THEN
                    RAISE EXCEPTION 'confirmed/cancelled putaway event is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_putaway_events_history_guard ON putaway_events');
        DB::statement('CREATE TRIGGER trg_putaway_events_history_guard BEFORE UPDATE OR DELETE ON putaway_events FOR EACH ROW EXECUTE FUNCTION hn_reject_putaway_event_change()');
    }

    public function down(): void
    {
        // Fail-forward history protection.
    }
};
