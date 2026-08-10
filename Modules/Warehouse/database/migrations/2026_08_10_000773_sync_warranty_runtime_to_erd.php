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
            CREATE OR REPLACE FUNCTION hn_erd_mirror_warranty_cases()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO warranty_cases (
                    id, case_no, identification_status, item_id, temporary_item_code,
                    sales_trace_status, customer_name, customer_phone, customer_address,
                    purchase_date, defect_id, reported_defect, confirmed_defect,
                    missing_code_reason, manual_product_description, accessories_received,
                    received_condition, status, received_at, completed_at, returned_at,
                    cancel_reason, created_at, created_by, updated_at, updated_by, version
                )
                SELECT s.warranty_case_id,
                       s.warranty_case_code,
                       s.identification_status,
                       s.item_id,
                       s.temporary_item_code,
                       COALESCE(
                           s.sales_trace_status,
                           CASE WHEN s.item_id IS NULL THEN 'NOT_TRACEABLE' ELSE 'TRACEABLE' END
                       ),
                       s.customer_name,
                       s.customer_phone,
                       s.customer_address,
                       s.purchase_date,
                       s.defect_id,
                       COALESCE(s.description, s.manual_product_description, s.missing_code_reason),
                       s.condition_status,
                       s.missing_code_reason,
                       s.manual_product_description,
                       NULL,
                       s.condition_status,
                       s.status,
                       s.created_at,
                       CASE WHEN s.status='COMPLETED' THEN s.closed_at END,
                       CASE WHEN s.status='RETURNED' THEN s.closed_at END,
                       NULL,
                       s.created_at,
                       s.created_by,
                       COALESCE(s.updated_at, s.created_at),
                       NULL,
                       COALESCE(s.version, 0)
                  FROM warranty_case s
                ON CONFLICT (id) DO UPDATE SET
                    case_no = EXCLUDED.case_no,
                    identification_status = EXCLUDED.identification_status,
                    item_id = EXCLUDED.item_id,
                    temporary_item_code = EXCLUDED.temporary_item_code,
                    sales_trace_status = EXCLUDED.sales_trace_status,
                    customer_name = EXCLUDED.customer_name,
                    customer_phone = EXCLUDED.customer_phone,
                    customer_address = EXCLUDED.customer_address,
                    purchase_date = EXCLUDED.purchase_date,
                    defect_id = EXCLUDED.defect_id,
                    reported_defect = EXCLUDED.reported_defect,
                    confirmed_defect = EXCLUDED.confirmed_defect,
                    missing_code_reason = EXCLUDED.missing_code_reason,
                    manual_product_description = EXCLUDED.manual_product_description,
                    received_condition = EXCLUDED.received_condition,
                    status = EXCLUDED.status,
                    received_at = EXCLUDED.received_at,
                    completed_at = EXCLUDED.completed_at,
                    returned_at = EXCLUDED.returned_at,
                    updated_at = EXCLUDED.updated_at,
                    version = EXCLUDED.version;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // The statement-level trigger was created by 000765 and resolves this function by
        // name at execution time. Recreating it keeps upgrades robust if 000765 was skipped
        // in a partially applied environment.
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_mirror_warranty_cases_from_warranty_case ON warranty_case');
        DB::statement('CREATE TRIGGER trg_erd_mirror_warranty_cases_from_warranty_case AFTER INSERT OR UPDATE OR DELETE ON warranty_case FOR EACH STATEMENT EXECUTE FUNCTION hn_erd_mirror_warranty_cases()');
    }

    public function down(): void
    {
        // Fail-forward runtime/canonical data bridge.
    }
};
