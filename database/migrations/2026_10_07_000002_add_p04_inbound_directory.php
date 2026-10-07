<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Authoritative directory, intentionally empty. No demo supplier, inferred
        // supplier from a document name, or automatic backfill is permitted.
        Schema::create('inbound_suppliers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('warehouse_id');
            $table->string('name', 200);
            $table->enum('status', ['ACTIVE', 'INACTIVE']);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->index(['warehouse_id', 'status', 'name'], 'p04_supplier_scope');
        });
        Schema::create('mini_app_inbound_record_metadata', function (Blueprint $table): void {
            $table->uuid('document_id')->primary();
            $table->uuid('draft_id')->unique();
            $table->string('inbound_type', 32);
            $table->uuid('supplier_id')->nullable();
            $table->string('supplier_name', 200)->nullable();
            $table->unsignedInteger('supplier_version')->nullable();
            $table->timestampTz('recorded_at');
            $table->foreign('document_id')->references('id')->on('stock_documents')->restrictOnDelete();
            $table->foreign('draft_id')->references('document_id')->on('mini_app_drafts')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('inbound_suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_app_inbound_record_metadata');
        Schema::dropIfExists('inbound_suppliers');
    }
};
