<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_daily_numbers', function (Blueprint $table): void {
            $table->date('number_date')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });
        Schema::create('outbound_draft_numbers', function (Blueprint $table): void {
            $table->uuid('draft_id')->primary();
            $table->string('doc_no', 64)->unique();
            $table->timestampTz('assigned_at', 6);
            $table->foreign('draft_id')->references('document_id')->on('mini_app_drafts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('P05 reservations are externally visible; retain them during application rollback.');
    }
};
