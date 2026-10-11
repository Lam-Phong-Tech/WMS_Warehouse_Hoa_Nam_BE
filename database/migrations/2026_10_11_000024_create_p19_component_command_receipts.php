<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p19_component_command_receipts', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->uuid('actor_id');
            $table->uuid('auth_session_id');
            $table->uuid('warehouse_id');
            $table->string('scope_hash', 64);
            $table->string('operation', 10);
            $table->uuid('target_document_id')->nullable();
            $table->uuid('warranty_case_id');
            $table->unsignedInteger('expected_version')->nullable();
            $table->string('fingerprint', 64);
            $table->string('outcome', 12)->default('UNKNOWN');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('result_json')->nullable();
            $table->timestampTz('recorded_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index(['actor_id', 'auth_session_id', 'warehouse_id'], 'p19_command_owner');
        });
    }

    public function down(): void
    {
        // A deployment rollback must not erase deduplication or UNKNOWN history.
        if (Schema::hasTable('p19_component_command_receipts') && DB::table('p19_component_command_receipts')->exists()) {
            throw new RuntimeException('Retain P19 command receipts when rolling back application code.');
        }
        Schema::dropIfExists('p19_component_command_receipts');
    }
};
