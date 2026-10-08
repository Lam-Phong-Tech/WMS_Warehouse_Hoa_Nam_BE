<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p14_recovery_requests', function (Blueprint $t): void {
            $t->uuid('operation_id')->primary();
            $t->string('proof_hash', 64);
            $t->string('request_hash', 64);
            $t->text('username_ciphertext');
            $t->timestampTz('received_at');
            $t->timestampTz('expires_at')->index();
            $t->timestampTz('delivered_at')->nullable();
        });
        Schema::table('mini_app_work_shifts', function (Blueprint $t): void {
            $t->timestampTz('ended_at')->nullable();
            $t->unsignedBigInteger('version')->default(1);
            $t->string('tracking_contract', 40)->nullable();
            $t->dropUnique(['auth_session_id']);
        });
        // PostgreSQL and SQLite: at most one active shift per login family.
        // Historical shift IDs and old start keys are not rewritten or deleted.
        DB::statement('CREATE UNIQUE INDEX p14_one_active_shift ON mini_app_work_shifts (auth_session_id) WHERE ended_at IS NULL');
        Schema::create('p14_shift_operations', function (Blueprint $t): void {
            $t->uuid('shift_id');
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->string('operation', 40);
            $t->uuid('business_id')->nullable();
            $t->timestampTz('completed_at');
            $t->primary(['shift_id', 'request_id']);
            $t->foreign('shift_id')->references('shift_id')->on('mini_app_work_shifts');
        });
        Schema::create('p14_shift_closures', function (Blueprint $t): void {
            $t->uuid('shift_id')->primary();
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->text('receipt_json');
            $t->longText('snapshot_ciphertext');
            $t->foreign('shift_id')->references('shift_id')->on('mini_app_work_shifts');
        });
    }

    public function down(): void
    {
        // Rolling back the unique-session schema cannot discard historical shifts.
        if (DB::table('mini_app_work_shifts')->select('auth_session_id')->groupBy('auth_session_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('P14 rollback requires an approved history-preserving migration; multiple shifts exist.');
        }
        Schema::dropIfExists('p14_shift_closures');
        Schema::dropIfExists('p14_shift_operations');
        DB::statement('DROP INDEX IF EXISTS p14_one_active_shift');
        Schema::table('mini_app_work_shifts', function (Blueprint $t): void {
            $t->unique('auth_session_id');
            $t->dropColumn(['ended_at', 'version', 'tracking_contract']);
        });
        Schema::dropIfExists('p14_recovery_requests');
    }
};
