<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('putaway_events') || ! Schema::hasColumn('putaway_events', 'confirmed_at')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE putaway_events ALTER COLUMN confirmed_at DROP DEFAULT');
            DB::statement('ALTER TABLE putaway_events ALTER COLUMN confirmed_at DROP NOT NULL');
        } else {
            Schema::table('putaway_events', function (Blueprint $table): void {
                $table->timestamp('confirmed_at')->nullable()->default(null)->change();
            });
        }

        DB::table('putaway_events')
            ->where('status', 'DRAFT')
            ->update([
                'confirmed_at' => null,
                'confirmed_by' => null,
            ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE putaway_events DROP CONSTRAINT IF EXISTS chk_putaway_draft_unconfirmed');
            DB::statement(<<<'SQL'
                ALTER TABLE putaway_events
                ADD CONSTRAINT chk_putaway_draft_unconfirmed
                CHECK (status <> 'DRAFT' OR (confirmed_at IS NULL AND confirmed_by IS NULL))
            SQL);
        }
    }

    public function down(): void
    {
        // Fail-forward: DRAFT must remain distinguishable from a confirmed physical move.
    }
};
