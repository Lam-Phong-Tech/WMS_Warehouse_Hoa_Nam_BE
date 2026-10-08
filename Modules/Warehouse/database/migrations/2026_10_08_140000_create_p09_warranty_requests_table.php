<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('p09_warranty_requests')) {
            return;
        }
        Schema::create('p09_warranty_requests', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('scope_hash', 64);
            $table->string('key_hash', 64);
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('operation', 16);
            $table->uuid('warranty_case_id')->nullable();
            $table->string('state', 16);
            $table->unsignedBigInteger('committed_version')->nullable();
            $table->string('committed_status', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['scope_hash', 'request_id'], 'p09_warranty_request_scope_unique');
        });
    }

    public function down(): void
    {
        // Retain reconciliation evidence on rollback; never silently erase receipts.
    }
};
