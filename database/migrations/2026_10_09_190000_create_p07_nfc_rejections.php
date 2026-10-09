<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p07_nfc_rejections', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->uuid('receipt_id')->unique();
            // Retained operation fences, not cascaded away by account/item deletion.
            $table->uuid('actor_id');
            $table->uuid('auth_session_id');
            $table->uuid('warehouse_id');
            $table->uuid('item_id')->index();
            $table->uuid('sku_id');
            $table->char('key_hash', 64);
            $table->string('request_hash', 67);
            $table->string('hardware_uid', 150)->index();
            $table->char('reservation_hash', 64)->index();
            $table->char('payload_sha256', 64);
            $table->uuid('original_request_id')->nullable()->index();
            $table->string('reason_code', 50);
            $table->string('evidence_type', 50);
            $table->timestampTz('rejected_at');
            $table->timestampTz('recovered_at')->nullable();
            $table->uuid('recovered_by_code_id')->nullable();
            $table->timestampTz('recovery_prepared_at')->nullable();
            $table->char('recovery_reservation_hash', 64)->nullable();
            $table->char('recovery_key_hash', 64)->nullable()->index();
            $table->string('recovery_request_hash', 67)->nullable();
            $table->string('recovery_state', 20)->nullable();
            $table->unique(['actor_id', 'key_hash'], 'p07_rejection_actor_key');
        });
    }

    public function down(): void
    {
        // Administrative rollback removes replay protection. Never use as recovery.
        Schema::dropIfExists('p07_nfc_rejections');
    }
};
