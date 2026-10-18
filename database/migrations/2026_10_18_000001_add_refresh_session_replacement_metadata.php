<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('replaced_by_auth_session_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('refresh_tokens')->whereNotNull('replaced_by_auth_session_id')->exists()) {
            throw new RuntimeException('Session replacement metadata is in use; retain it during application rollback.');
        }
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->dropColumn('replaced_by_auth_session_id');
        });
    }
};
