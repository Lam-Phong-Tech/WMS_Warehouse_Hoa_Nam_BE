<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->timestamp('locked_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('idempotency_keys')->whereNull('locked_until')->exists()) {
            throw new RuntimeException('Không thể rollback: khóa idempotency đã hoàn tất có locked_until NULL.');
        }

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->timestamp('locked_until')->nullable(false)->change();
        });
    }
};
