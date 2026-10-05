<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('auth_session_id')->nullable()->index();
        });

        // Existing devices keep their identity at rollout. Token rotation carries
        // this value forward; the JWT sid remains the individual token-row id.
        DB::table('refresh_tokens')->whereNull('auth_session_id')
            ->update(['auth_session_id' => DB::raw('id')]);

        // PostgreSQL app_user is a compatibility VIEW over app_users.id.
        // Match the physical FK target used by the current IAM schema.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';

        Schema::create('mini_app_work_shifts', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->uuid('shift_id')->primary();
            $table->uuid('actor_id');
            $table->uuid('auth_session_id')->unique();
            $table->uuid('warehouse_id');
            // Explicit UTC strings on write/read avoid application/DB timezone drift.
            $table->timestampTz('started_at');
            $table->foreign('actor_id')->references($userKey)->on($userTable);
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->index(['actor_id', 'warehouse_id']);
        });

        Schema::create('mini_app_work_shift_keys', function (Blueprint $table): void {
            $table->uuid('auth_session_id');
            $table->uuid('idempotency_key');
            $table->uuid('warehouse_id');
            $table->uuid('shift_id');
            $table->primary(['auth_session_id', 'idempotency_key'], 'mini_app_shift_keys_pk');
            $table->foreign('shift_id')->references('shift_id')->on('mini_app_work_shifts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_app_work_shift_keys');
        Schema::dropIfExists('mini_app_work_shifts');
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->dropIndex(['auth_session_id']);
            $table->dropColumn('auth_session_id');
        });
    }
};
