<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $key = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        Schema::create('p11_security_commands', function (Blueprint $table) use ($users, $key): void {
            $table->uuid('actor_id');
            $table->uuid('operation_key');
            $table->uuid('auth_session_id');
            $table->uuid('request_id');
            $table->string('operation', 24);
            $table->uuid('target_id');
            $table->string('request_hash', 64);
            $table->string('mfa_binding', 64);
            $table->enum('state', ['PENDING', 'SUCCEEDED', 'REJECTED']);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('result_json')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->primary(['actor_id', 'operation_key'], 'p11_command_identity');
            $table->index(['actor_id', 'auth_session_id', 'state'], 'p11_command_family');
            $table->foreign('actor_id')->references($key)->on($users)->restrictOnDelete();
        });
        Schema::create('p11_security_reconciliations', function (Blueprint $table): void {
            $table->uuid('actor_id');
            $table->uuid('operation_key');
            $table->uuid('auth_session_id');
            $table->string('mfa_binding', 64);
            $table->bigInteger('credential_epoch')->nullable();
            $table->string('credential_binding', 64);
            $table->timestampTz('verified_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->primary(['actor_id', 'operation_key', 'auth_session_id'], 'p11_reconciliation_identity');
            $table->foreign(['actor_id', 'operation_key'])->references(['actor_id', 'operation_key'])
                ->on('p11_security_commands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p11_security_reconciliations');
        Schema::dropIfExists('p11_security_commands');
    }
};
