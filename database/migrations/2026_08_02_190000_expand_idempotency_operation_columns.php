<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasTable('idempotency_keys')) {
            $this->alterOperationColumn('idempotency_keys', 160);
        }

        if (Schema::hasTable('posting_event')) {
            $this->alterOperationColumn('posting_event', 160);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasTable('idempotency_keys') && $this->canShrinkOperation('idempotency_keys')) {
            $this->alterOperationColumn('idempotency_keys', 80);
        }

        if (Schema::hasTable('posting_event') && $this->canShrinkOperation('posting_event')) {
            $this->alterOperationColumn('posting_event', 80);
        }
    }

    private function alterOperationColumn(string $table, int $length): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement("ALTER TABLE {$table} ALTER COLUMN operation TYPE varchar({$length})"),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE {$table} MODIFY operation varchar({$length}) NOT NULL"),
            default => null,
        };
    }

    private function canShrinkOperation(string $table): bool
    {
        return (int) DB::table($table)->whereRaw('length(operation) > 80')->count() === 0;
    }
};
