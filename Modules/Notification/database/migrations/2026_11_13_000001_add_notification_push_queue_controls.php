<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->timestampTz('expires_at', 6)->nullable()->change();
            $table->timestampTz('available_at', 6)->change();
        });
        $pg = DB::getDriverName() === 'pgsql';
        Schema::create('notification_push_rate_limits', function (Blueprint $table) use ($pg): void {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references($pg ? 'id' : 'user_id')->on($pg ? 'app_users' : 'app_user')->cascadeOnDelete();
            $table->timestampTz('next_send_at', 6);
            $table->timestampTz('updated_at', 6);
        });
        // The legacy ERD cleanup removed Laravel's generic cache tables.
        // The scheduler needs a shared store independent of Redis, including pause polling.
        Schema::create('notification_scheduler_cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('notification_scheduler_cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        // Keep nullable deadlines: an unsent delivery must never be discarded to roll back DDL.
        Schema::dropIfExists('notification_push_rate_limits');
        Schema::dropIfExists('notification_scheduler_cache_locks');
        Schema::dropIfExists('notification_scheduler_cache');
    }
};
