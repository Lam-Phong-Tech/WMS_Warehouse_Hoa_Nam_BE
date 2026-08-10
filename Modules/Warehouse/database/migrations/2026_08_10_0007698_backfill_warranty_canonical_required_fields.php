<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('warranty_case')
            || ! Schema::hasTable('warranty_cases')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE warranty_cases c
               SET case_no = COALESCE(c.case_no, s.warranty_case_code),
                   identification_status = COALESCE(c.identification_status, s.identification_status),
                   item_id = COALESCE(c.item_id, s.item_id),
                   temporary_item_code = COALESCE(c.temporary_item_code, s.temporary_item_code),
                   sales_trace_status = COALESCE(
                       c.sales_trace_status,
                       s.sales_trace_status,
                       CASE WHEN COALESCE(c.item_id, s.item_id) IS NULL THEN 'NOT_TRACEABLE' ELSE 'TRACEABLE' END
                   ),
                   customer_name = COALESCE(c.customer_name, s.customer_name),
                   customer_phone = COALESCE(c.customer_phone, s.customer_phone),
                   customer_address = COALESCE(c.customer_address, s.customer_address),
                   purchase_date = COALESCE(c.purchase_date, s.purchase_date),
                   defect_id = COALESCE(c.defect_id, s.defect_id),
                   reported_defect = COALESCE(c.reported_defect, s.description, s.manual_product_description, s.missing_code_reason),
                   confirmed_defect = COALESCE(c.confirmed_defect, s.condition_status),
                   missing_code_reason = COALESCE(c.missing_code_reason, s.missing_code_reason),
                   manual_product_description = COALESCE(c.manual_product_description, s.manual_product_description),
                   received_condition = COALESCE(c.received_condition, s.condition_status),
                   status = COALESCE(c.status, s.status),
                   received_at = COALESCE(c.received_at, s.created_at),
                   completed_at = COALESCE(c.completed_at, CASE WHEN s.status='COMPLETED' THEN s.closed_at END),
                   returned_at = COALESCE(c.returned_at, CASE WHEN s.status='RETURNED' THEN s.closed_at END),
                   created_at = COALESCE(c.created_at, s.created_at),
                   created_by = COALESCE(c.created_by, s.created_by),
                   updated_at = COALESCE(c.updated_at, s.updated_at, s.created_at),
                   version = COALESCE(c.version, s.version, 0)
              FROM warranty_case s
             WHERE c.id = s.warranty_case_id
        SQL);
    }

    public function down(): void
    {
        // Fail-forward canonical warranty data repair.
    }
};
