<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-222 (DEV222-P1-03) — audit_log thiếu field cho "kết quả/lỗi" và
 * "tham chiếu idempotency" trong catalog event bắt buộc (request_id, actor,
 * action, object, result/error, timestamp, scope/idempotency). Cột nullable,
 * additive — không đổi hành vi của các call site AuditLogger::record() hiện có.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_log')) {
            return;
        }

        Schema::table('audit_log', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_log', 'result')) {
                $table->string('result', 20)->nullable()->after('object_id');
            }
            if (! Schema::hasColumn('audit_log', 'error_code')) {
                $table->string('error_code', 64)->nullable()->after('result');
            }
            if (! Schema::hasColumn('audit_log', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('request_id');
                $table->index('idempotency_key', 'idx_audit_log_idempotency_key');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_log')) {
            return;
        }

        Schema::table('audit_log', function (Blueprint $table) {
            if (Schema::hasColumn('audit_log', 'idempotency_key')) {
                $table->dropIndex('idx_audit_log_idempotency_key');
                $table->dropColumn('idempotency_key');
            }
            if (Schema::hasColumn('audit_log', 'error_code')) {
                $table->dropColumn('error_code');
            }
            if (Schema::hasColumn('audit_log', 'result')) {
                $table->dropColumn('result');
            }
        });
    }
};
