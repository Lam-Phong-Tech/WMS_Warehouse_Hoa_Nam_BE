<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        Schema::create('user_business_profiles', function (Blueprint $t) use ($userTable, $userKey): void {
            $t->uuid('user_id')->primary();
            $t->string('job_title', 160)->nullable();
            $t->uuid('assigned_by')->nullable();
            $t->timestampTz('assigned_at', 6);
            $t->string('source', 60);
            $t->foreign('user_id')->references($userKey)->on($userTable)->restrictOnDelete();
        });
        Schema::create('notification_source_snapshots', function (Blueprint $t): void {
            $t->uuid('notification_id')->primary();
            $t->uuid('actor_id')->nullable();
            $t->string('actor_name', 255)->nullable();
            $t->string('job_title', 160)->nullable();
            $t->uuid('warehouse_id')->nullable();
            $t->string('warehouse_name', 255)->nullable();
            $t->timestampTz('captured_at', 6);
            $t->string('capture_policy', 80);
            $t->foreign('notification_id')->references('id')->on('notifications')->restrictOnDelete();
        });
        Schema::create('stock_document_event_evidence', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('request_key')->nullable()->unique();
            $t->string('request_hash', 64)->nullable();
            $t->uuid('document_id');
            $t->uuid('warehouse_id');
            $t->string('source', 12);
            $t->string('event_key', 40);
            $t->unsignedInteger('document_version');
            $t->uuid('actor_id')->nullable();
            $t->uuid('auth_session_id')->nullable();
            $t->string('actor_name', 255)->nullable();
            $t->string('job_title', 160)->nullable();
            $t->string('description', 255);
            $t->timestampTz('occurred_at', 6);
            $t->index(['document_id', 'warehouse_id', 'event_key']);
            $t->foreign('document_id')->references('id')->on('stock_documents')->restrictOnDelete();
        });
        Schema::create('warranty_product_snapshots', function (Blueprint $t): void {
            $t->uuid('case_id')->primary();
            $t->uuid('item_id')->nullable();
            $t->uuid('sku_id')->nullable();
            $t->string('serial_number', 100)->nullable();
            $t->string('sku_code', 100)->nullable();
            $t->string('sku_name', 255)->nullable();
            $t->string('temporary_item_code', 100)->nullable();
            $t->timestampTz('captured_at', 6);
            $t->foreign('case_id')->references(DB::getDriverName() === 'pgsql' ? 'id' : 'warranty_case_id')
                ->on(DB::getDriverName() === 'pgsql' ? 'warranty_cases' : 'warranty_case')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION hn_guard_future_evidence() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'EVENT_EVIDENCE_APPEND_ONLY'; END; $$ LANGUAGE plpgsql;");
            foreach (['notification_source_snapshots', 'stock_document_event_evidence', 'warranty_product_snapshots'] as $table) {
                DB::statement("CREATE TRIGGER guard_future_evidence BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION hn_guard_future_evidence()");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_product_snapshots');
        Schema::dropIfExists('stock_document_event_evidence');
        Schema::dropIfExists('notification_source_snapshots');
        Schema::dropIfExists('user_business_profiles');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS hn_guard_future_evidence()');
        }
    }
};
