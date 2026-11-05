<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_delivery_installations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->char('proof_hash', 64);
            $t->string('platform', 16);
            $t->unsignedBigInteger('binding_version')->default(0);
            $t->uuid('current_binding_id')->nullable();
            $t->timestampTz('created_at');$t->timestampTz('updated_at');
        });
        $pg = DB::getDriverName() === 'pgsql';
        Schema::create('notification_delivery_bindings', function (Blueprint $t) use ($pg): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('installation_id')->constrained('notification_delivery_installations')->cascadeOnDelete();
            $t->foreignUuid('user_id')->references($pg ? 'id' : 'user_id')->on($pg ? 'app_users' : 'app_user')->cascadeOnDelete();
            $t->uuid('auth_session_id');
            $t->unsignedBigInteger('binding_version');
            $t->string('status', 24);
            $t->char('token_fingerprint', 64)->nullable()->unique();
            $t->text('token_ciphertext')->nullable();
            $t->timestampTz('expires_at');$t->timestampTz('created_at');$t->timestampTz('updated_at');
            $t->index(['user_id','auth_session_id','status'],'notification_binding_owner');
            $t->unique(['installation_id','binding_version'],'notification_binding_version');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_bindings');Schema::dropIfExists('notification_delivery_installations');
    }
};
