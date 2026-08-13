<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PostgreSQL-only DDL; sqlite (test) không hỗ trợ DROP CONSTRAINT.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Legacy issue documents tồn tại trước baseline có thể chưa gắn toa nên
        // constraint cứng ở DB được gỡ; baseline hiện hành enforce ở tầng app:
        // storeOutbound bắt buộc outbound_request_id (SOURCE: BP-WMS-04/05).
        DB::statement('ALTER TABLE stock_documents DROP CONSTRAINT IF EXISTS chk_issue_requires_outbound_request');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT chk_issue_requires_outbound_request CHECK (doc_type <> 'issue' OR outbound_request_id IS NOT NULL)");
    }
};
