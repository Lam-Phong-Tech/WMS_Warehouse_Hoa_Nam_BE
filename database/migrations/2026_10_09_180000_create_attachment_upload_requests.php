<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        Schema::create('attachment_upload_requests', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuid('auth_session_id');
            $table->uuid('operation_key');
            $table->uuid('warehouse_id');
            $table->string('parent_type', 16);
            $table->uuid('parent_id');
            $table->uuid('attachment_id')->unique();
            $table->string('file_sha256', 64);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('request_hash', 64);
            $table->enum('state', ['PENDING', 'SUCCEEDED', 'REJECTED', 'UNKNOWN']);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->longText('receipt')->nullable();
            $table->string('cleanup_status', 32)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            // A new login cannot reuse a previous operation key to upload again.
            $table->unique(['actor_id', 'operation_key'], 'upload_actor_operation');
            $table->index(['parent_type', 'parent_id', 'state'], 'upload_parent_state');
            $table->foreign('actor_id')->references($userKey)->on($userTable)->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_upload_requests');
    }
};
