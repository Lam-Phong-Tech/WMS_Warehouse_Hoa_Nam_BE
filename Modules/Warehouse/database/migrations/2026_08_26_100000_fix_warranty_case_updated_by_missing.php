<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sự cố: không biết ai là người sửa case bảo hành lần cuối (đổi trạng thái,
 * cập nhật ghi chú/kết quả xử lý...) — chỉ có `created_by` (người tạo case).
 *
 * Root cause: CÙNG CLASS BUG với 2026_08_26_090000
 * (estimated_completion_date) — cột `updated_by` tồn tại thật trên bảng
 * canonical Postgres `warranty_cases` (FK -> app_users, xem
 * 2026_08_10_000773_sync_warranty_runtime_to_erd.php), nhưng:
 *  - view tương thích ngược `warranty_case` (2026_08_13_150000) không liệt kê
 *    cột này trong SELECT, và hàm write-through
 *    hn_legacy_warranty_case_write() không ghi nó qua view → mọi UPDATE qua
 *    `warranty_case` đều không thể set updated_by dù controller có gửi.
 *  - Trên sqlite, `warranty_case` (bảng vật lý, không phải view) CHƯA TỪNG có
 *    cột `updated_by` (2026_01_01_000044 chỉ tạo `created_by`); view
 *    `warranty_cases` phía sqlite (2026_08_09_000400) hard-code
 *    `NULL AS updated_by`.
 *
 * Fix: thêm cột `updated_by` (driver-aware), nối vào CUỐI view Postgres đúng
 * quy ước 2026_08_13_150000/2026_08_26_090000, cập nhật trigger write-through.
 * Additive, không đổi dữ liệu hiện có.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->isPostgresView('warranty_case')) {
            $this->fixPostgresCanonicalTable();
            $this->fixPostgresWarrantyCaseView();

            return;
        }

        if (Schema::hasTable('warranty_case') && ! Schema::hasColumn('warranty_case', 'updated_by')) {
            // Không dùng ->after()/->constrained() ở đây: cả hai đều buộc grammar
            // SQLite dựng lại bảng qua __temp__ (rename), mà bảng warranty_case có
            // view warranty_cases phụ thuộc (2026_08_09_000400) nên rebuild sẽ vỡ
            // với lỗi "no such table: main.warranty_case" giữa chừng. Add-column
            // đơn giản dùng ALTER TABLE ADD COLUMN trực tiếp, an toàn với view phụ
            // thuộc — cùng cách 2026_08_26_090000 đã làm cho estimated_completion_date.
            Schema::table('warranty_case', function (Blueprint $table): void {
                $table->uuid('updated_by')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Fail-forward: cột/view mở rộng là additive và giữ nguyên dữ liệu,
        // theo đúng quy ước của 2026_08_13_150000 / 2026_08_26_090000.
    }

    private function fixPostgresCanonicalTable(): void
    {
        // Cột `updated_by` đã tồn tại thật trên warranty_cases (xem
        // 2026_08_10_000773) — không cần Schema::table() ở đây, chỉ nối vào
        // view/trigger bên dưới.
    }

    private function fixPostgresWarrantyCaseView(): void
    {
        // Giữ nguyên đúng thứ tự cột hiện có của view (2026_08_26_090000) rồi
        // nối updated_by vào cuối.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW warranty_case AS
            SELECT id AS warranty_case_id, warranty_case_code, item_id AS inventory_serial_id, sku_id AS product_id,
                   temporary_item_code, identification_status, missing_code_reason, manual_product_description,
                   defect_id, customer_name, customer_phone, status, condition_status, description, created_by,
                   version, closed_at, retention_until, legal_hold_flag, created_at, updated_at,
                   NULL::timestamptz AS deleted_at, item_id, customer_address, purchase_date, sales_trace_status,
                   reported_defect, confirmed_defect, accessories_received, received_condition,
                   received_at, completed_at, returned_at, cancel_reason, estimated_completion_date, updated_by
              FROM warranty_cases
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_warranty_case_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM warranty_cases WHERE id = OLD.warranty_case_id;
                    RETURN OLD;
                END IF;
                INSERT INTO warranty_cases (
                    id, warranty_case_code, item_id, sku_id, temporary_item_code, identification_status,
                    missing_code_reason, manual_product_description, defect_id, customer_name, customer_phone,
                    customer_address, purchase_date, sales_trace_status, status, condition_status, description,
                    created_by, version, closed_at, retention_until, legal_hold_flag, created_at, updated_at,
                    reported_defect, confirmed_defect, accessories_received, received_condition,
                    received_at, completed_at, returned_at, cancel_reason, estimated_completion_date, updated_by
                ) VALUES (
                    COALESCE(NEW.warranty_case_id, gen_random_uuid()),
                    NEW.warranty_case_code,
                    COALESCE(NEW.item_id, NEW.inventory_serial_id),
                    NEW.product_id,
                    NEW.temporary_item_code,
                    COALESCE(NEW.identification_status, 'IDENTIFIED'),
                    NEW.missing_code_reason,
                    NEW.manual_product_description,
                    NEW.defect_id,
                    NEW.customer_name,
                    NEW.customer_phone,
                    NEW.customer_address,
                    NEW.purchase_date,
                    COALESCE(NEW.sales_trace_status, 'NOT_TRACEABLE'),
                    COALESCE(NEW.status, 'RECEIVED'),
                    NEW.condition_status,
                    NEW.description,
                    NEW.created_by,
                    COALESCE(NEW.version, 1),
                    NEW.closed_at,
                    NEW.retention_until,
                    COALESCE(NEW.legal_hold_flag, false),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.reported_defect, NEW.description),
                    NEW.confirmed_defect,
                    NEW.accessories_received,
                    COALESCE(NEW.received_condition, NEW.condition_status),
                    COALESCE(NEW.received_at, NEW.created_at, CURRENT_TIMESTAMP),
                    NEW.completed_at,
                    NEW.returned_at,
                    NEW.cancel_reason,
                    NEW.estimated_completion_date,
                    NEW.updated_by
                )
                ON CONFLICT ("id") DO UPDATE SET
                    "warranty_case_code"=EXCLUDED."warranty_case_code",
                    "item_id"=EXCLUDED."item_id",
                    "sku_id"=EXCLUDED."sku_id",
                    "temporary_item_code"=EXCLUDED."temporary_item_code",
                    "identification_status"=EXCLUDED."identification_status",
                    "missing_code_reason"=EXCLUDED."missing_code_reason",
                    "manual_product_description"=EXCLUDED."manual_product_description",
                    "defect_id"=EXCLUDED."defect_id",
                    "customer_name"=EXCLUDED."customer_name",
                    "customer_phone"=EXCLUDED."customer_phone",
                    "customer_address"=EXCLUDED."customer_address",
                    "purchase_date"=EXCLUDED."purchase_date",
                    "sales_trace_status"=EXCLUDED."sales_trace_status",
                    "status"=EXCLUDED."status",
                    "condition_status"=EXCLUDED."condition_status",
                    "description"=EXCLUDED."description",
                    "created_by"=EXCLUDED."created_by",
                    "version"=EXCLUDED."version",
                    "closed_at"=EXCLUDED."closed_at",
                    "retention_until"=EXCLUDED."retention_until",
                    "legal_hold_flag"=EXCLUDED."legal_hold_flag",
                    "created_at"=EXCLUDED."created_at",
                    "updated_at"=EXCLUDED."updated_at",
                    "reported_defect"=EXCLUDED."reported_defect",
                    "confirmed_defect"=EXCLUDED."confirmed_defect",
                    "accessories_received"=EXCLUDED."accessories_received",
                    "received_condition"=EXCLUDED."received_condition",
                    "received_at"=EXCLUDED."received_at",
                    "completed_at"=EXCLUDED."completed_at",
                    "returned_at"=EXCLUDED."returned_at",
                    "cancel_reason"=EXCLUDED."cancel_reason",
                    "estimated_completion_date"=EXCLUDED."estimated_completion_date",
                    "updated_by"=EXCLUDED."updated_by";
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
        SQL);
    }

    private function isPostgresView(string $relation): bool
    {
        return DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', DB::raw('current_schema()'))
            ->where('c.relname', $relation)
            ->where('c.relkind', 'v')
            ->exists();
    }
};
