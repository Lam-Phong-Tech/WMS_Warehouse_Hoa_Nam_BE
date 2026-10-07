<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same physical IAM targets as the existing work-shift migration.
        // PostgreSQL app_user is a compatibility view, not a foreign-key target.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        Schema::create('mini_app_drafts', function (Blueprint $table) use ($userTable, $userKey): void {
            $table->uuid('document_id')->primary();
            $table->uuid('actor_id');
            // Stable login family, not a rotating refresh-token row and not a work shift.
            $table->uuid('auth_session_id');
            $table->uuid('warehouse_id');
            $table->uuid('scan_session_id');
            $table->string('operation', 32);
            $table->enum('status', ['DRAFT', 'CANCELLED', 'RECORDED'])->default('DRAFT');
            $table->unsignedInteger('version')->default(1);
            $table->string('title', 200)->nullable();
            $table->longText('codes');
            $table->longText('form');
            $table->uuid('business_document_id')->nullable();
            $table->uuid('last_request_id');
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->foreign('actor_id')->references($userKey)->on($userTable)->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['actor_id', 'auth_session_id', 'scan_session_id'], 'p03_draft_scan_identity');
            $table->index(['actor_id', 'auth_session_id', 'warehouse_id', 'operation', 'status'], 'p03_draft_scope');
        });
        Schema::create('mini_app_draft_receipts', function (Blueprint $table): void {
            $table->uuid('actor_id');
            $table->uuid('auth_session_id');
            $table->uuid('request_id');
            $table->uuid('document_id');
            $table->uuid('scan_session_id');
            $table->uuid('warehouse_id');
            $table->string('operation', 32);
            $table->string('command', 16);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->unsignedInteger('version');
            $table->timestampTz('completed_at', 6);
            $table->primary(['actor_id', 'auth_session_id', 'request_id'], 'p03_receipt_identity');
            $table->foreign('document_id')->references('document_id')->on('mini_app_drafts')->restrictOnDelete();
            $table->index(['document_id', 'version'], 'p03_receipt_document_version');
        });
    }

    public function down(): void
    {
        // Administrative rollback only. No HTTP endpoint deletes a draft or receipt.
        Schema::dropIfExists('mini_app_draft_receipts');
        Schema::dropIfExists('mini_app_drafts');
    }
};
