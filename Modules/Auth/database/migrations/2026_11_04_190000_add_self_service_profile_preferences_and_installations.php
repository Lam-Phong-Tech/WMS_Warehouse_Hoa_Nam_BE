<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL exposes app_user through a writable compatibility view.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        Schema::create('user_profile_preferences', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->foreignUuid('user_id')->primary()->constrained($userTable, $userKey)->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->json('preferences');
            $table->timestampTz('updated_at');
        });
        // Private, bounded image bytes live in the same transaction as the user version.
        // No public file URL, upload staging object or cross-storage partial commit exists.
        Schema::create('user_profile_avatars', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->foreignUuid('user_id')->primary()->constrained($userTable, $userKey)->cascadeOnDelete();
            $table->string('mime_type', 32);
            $table->unsignedInteger('size');
            $table->longText('image_base64');
            $table->timestampTz('updated_at');
        });
        Schema::create('auth_client_installations', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained($userTable, $userKey)->cascadeOnDelete();
            $table->uuid('client_device_id');
            $table->string('device_label', 120)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('client_type', 8)->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unique(['user_id', 'client_device_id'], 'auth_installation_user_client_unique');
        });
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->foreignUuid('auth_device_id')->nullable()->constrained('auth_client_installations')->nullOnDelete();
            $table->timestampTz('auth_session_started_at')->nullable();
            $table->index(['user_id', 'auth_device_id'], 'refresh_tokens_user_device_index');
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->dropForeign(['auth_device_id']);
            $table->dropIndex('refresh_tokens_user_device_index');
            $table->dropColumn('auth_device_id');
            $table->dropColumn('auth_session_started_at');
        });
        Schema::dropIfExists('auth_client_installations');
        Schema::dropIfExists('user_profile_avatars');
        Schema::dropIfExists('user_profile_preferences');
    }
};
