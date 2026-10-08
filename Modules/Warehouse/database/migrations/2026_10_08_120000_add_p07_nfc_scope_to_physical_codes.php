<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physical_codes', function (Blueprint $table): void {
            // Nullable by design: never backfill historical assignment provenance
            // from current stock, the creator, a SKU or a user's current session.
            $table->uuid('nfc_warehouse_id')->nullable()->index();
            $table->string('nfc_auth_session_id', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('physical_codes', function (Blueprint $table): void {
            $table->dropIndex(['nfc_warehouse_id']);
            $table->dropColumn(['nfc_warehouse_id', 'nfc_auth_session_id']);
        });
    }
};
