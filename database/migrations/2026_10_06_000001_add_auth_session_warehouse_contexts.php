<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL app_user is a compatibility VIEW; FK must target the
        // canonical physical table, just like the work-shift migration.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        Schema::create('auth_session_warehouse_contexts', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->uuid('auth_session_id')->primary();
            $table->uuid('actor_id')->index();
            $table->uuid('warehouse_id');
            $table->timestampTz('confirmed_at');
            $table->foreign('actor_id')->references($userKey)->on($userTable);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });
        // No inferred selection/backfill. Existing same-family shift receipts
        // are read as prior confirmation, subject to current warehouse/scope checks.
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_session_warehouse_contexts');
    }
};
