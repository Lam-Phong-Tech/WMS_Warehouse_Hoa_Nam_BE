<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL exposes compatibility views; foreign keys must reference physical tables.
        $pgsql = DB::getDriverName() === 'pgsql';
        Schema::create('warranty_case_warehouse_owners', function (Blueprint $table) use ($pgsql): void {
            $table->uuid('warranty_case_id')->primary();
            $table->uuid('warehouse_id')->index();
            $table->string('source', 32);
            $table->uuid('auth_session_id')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('assigned_at');
            $table->text('evidence')->nullable();
            $table->foreign('warranty_case_id')->references($pgsql ? 'id' : 'warranty_case_id')->on($pgsql ? 'warranty_cases' : 'warranty_case')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('actor_id')->references($pgsql ? 'id' : 'user_id')->on($pgsql ? 'app_users' : 'app_user')->restrictOnDelete();
        });
        // Deliberately NO inference/backfill from item location, creator roles or the currently active warehouse.
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_case_warehouse_owners');
    }
};
