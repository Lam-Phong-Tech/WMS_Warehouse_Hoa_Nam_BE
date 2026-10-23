<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mini_app_web_post_handoffs', function (Blueprint $table): void {
            $table->uuid('stock_document_id')->primary();
            $table->foreign('stock_document_id')->references('id')->on('stock_documents')->restrictOnDelete();
            $table->string('source', 16);
            $table->uuid('warehouse_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('document_version_at_record');
            $table->timestampTz('submitted_at', 6);
            $table->index(['warehouse_id', 'source', 'submitted_at'], 'mini_app_web_post_queue');
        });
        // No inference/backfill from readiness, notifications or historical receipts.
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_app_web_post_handoffs');
    }
};
