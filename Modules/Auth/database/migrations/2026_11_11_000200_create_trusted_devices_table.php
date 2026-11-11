<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('trusted_devices', function (Blueprint $table) use ($isPgsql): void {
            $table->uuid('id')->primary();
            if ($isPgsql) {
                $table->uuid('user_id');
                $table->foreign('user_id')->references('id')->on('app_users')->cascadeOnDelete();
            } else {
                $table->foreignUuid('user_id')->constrained('app_user', 'user_id')->cascadeOnDelete();
            }
            $table->uuid('installation_id');
            $table->foreign('installation_id')->references('id')->on('auth_client_installations')->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->timestamp('previous_valid_until')->nullable();
            $table->timestamp('trusted_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at'], 'trusted_devices_user_expires_idx');
        });

        DB::statement('CREATE UNIQUE INDEX trusted_devices_user_installation_active_uidx ON trusted_devices (user_id, installation_id) WHERE revoked_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX trusted_devices_token_hash_active_uidx ON trusted_devices (token_hash) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('trusted_devices');
    }
};
