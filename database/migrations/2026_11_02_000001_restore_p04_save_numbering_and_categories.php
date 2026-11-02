<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reuse earlier P04 reservations if that reviewed extension was already
        // installed. Never backfill invented categories or renumber old records.
        if (! Schema::hasTable('inbound_daily_numbers')) {
            Schema::create('inbound_daily_numbers', function (Blueprint $table): void {
                $table->date('number_date')->primary();
                $table->unsignedBigInteger('last_value')->default(0);
            });
        }
        if (! Schema::hasTable('inbound_draft_numbers')) {
            Schema::create('inbound_draft_numbers', function (Blueprint $table): void {
                $table->uuid('draft_id')->primary();
                $table->string('doc_no', 64)->unique();
                $table->timestampTz('assigned_at');
                $table->foreign('draft_id')->references('document_id')->on('mini_app_drafts')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('mini_app_inbound_record_metadata', 'inbound_category')) {
            Schema::table('mini_app_inbound_record_metadata', function (Blueprint $table): void {
                $table->string('inbound_category', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Source rollback is compatible with these additive tables. Dropping
        // them could reissue an already-visible number or lose record metadata.
        throw new RuntimeException('P04 numbering must be preserved; use a reviewed, data-preserving administrative rollback.');
    }
};
