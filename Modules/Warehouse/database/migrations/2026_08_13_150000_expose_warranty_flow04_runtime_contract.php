<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Database\Migrations\Concerns\DropsBaselineViewsAroundAlter;

/**
 * BP-WMS-08/09: mở các field ERD §45/§47 (reported_defect, confirmed_defect,
 * accessories_received, received_condition, received_at, completed_at,
 * returned_at, cancel_reason và bộ cột warranty_attachments) cho runtime
 * `warranty_case` để API bảo hành đọc/ghi được đúng theo tài liệu.
 */
return new class extends Migration
{
    use DropsBaselineViewsAroundAlter;

    public function up(): void
    {
        $this->extendWarrantyAttachments();

        if (DB::getDriverName() === 'pgsql' && $this->isPostgresView('warranty_case')) {
            $this->extendPostgresWarrantyCaseView();

            return;
        }

        $this->extendLegacyWarrantyCaseTable();
    }

    public function down(): void
    {
        // Fail-forward: các cột/việc mở rộng view là additive và giữ nguyên dữ liệu.
    }

    private function extendWarrantyAttachments(): void
    {
        if (! Schema::hasTable('warranty_attachments')) {
            return;
        }

        Schema::table('warranty_attachments', function (Blueprint $table): void {
            foreach ([
                'content_type' => fn () => $table->string('content_type', 120)->nullable(),
                'file_size_bytes' => fn () => $table->bigInteger('file_size_bytes')->nullable(),
                'storage_key' => fn () => $table->string('storage_key', 500)->nullable(),
                'checksum' => fn () => $table->string('checksum', 64)->nullable(),
                'attachment_type' => fn () => $table->string('attachment_type', 30)->default('OTHER'),
                'status' => fn () => $table->string('status', 20)->default('ACTIVE'),
                'uploaded_at' => fn () => $table->timestamp('uploaded_at')->nullable(),
                'uploaded_by' => fn () => $table->uuid('uploaded_by')->nullable(),
                'deleted_at' => fn () => $table->timestamp('deleted_at')->nullable(),
                'deleted_by' => fn () => $table->uuid('deleted_by')->nullable(),
            ] as $column => $definition) {
                if (! Schema::hasColumn('warranty_attachments', $column)) {
                    $definition();
                }
            }
        });
    }

    private function extendLegacyWarrantyCaseTable(): void
    {
        if (! Schema::hasTable('warranty_case')) {
            return;
        }

        $this->alterTableAroundBaselineViews('warranty_case', function () {
            Schema::table('warranty_case', function (Blueprint $table): void {
                foreach ([
                    'reported_defect' => fn () => $table->text('reported_defect')->nullable(),
                    'confirmed_defect' => fn () => $table->text('confirmed_defect')->nullable(),
                    'accessories_received' => fn () => $table->text('accessories_received')->nullable(),
                    'received_condition' => fn () => $table->text('received_condition')->nullable(),
                    'received_at' => fn () => $table->timestamp('received_at')->nullable(),
                    'completed_at' => fn () => $table->timestamp('completed_at')->nullable(),
                    'returned_at' => fn () => $table->timestamp('returned_at')->nullable(),
                    'cancel_reason' => fn () => $table->text('cancel_reason')->nullable(),
                ] as $column => $definition) {
                    if (! Schema::hasColumn('warranty_case', $column)) {
                        $definition();
                    }
                }
            });
        });
    }

    private function extendPostgresWarrantyCaseView(): void
    {
        // CREATE OR REPLACE VIEW chỉ cho phép nối cột vào cuối nên giữ nguyên
        // thứ tự cột của 2026_08_10_000791 rồi append các cột ERD.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW warranty_case AS
            SELECT id AS warranty_case_id, warranty_case_code, item_id AS inventory_serial_id, sku_id AS product_id,
                   temporary_item_code, identification_status, missing_code_reason, manual_product_description,
                   defect_id, customer_name, customer_phone, status, condition_status, description, created_by,
                   version, closed_at, retention_until, legal_hold_flag, created_at, updated_at,
                   NULL::timestamptz AS deleted_at, item_id, customer_address, purchase_date, sales_trace_status,
                   reported_defect, confirmed_defect, accessories_received, received_condition,
                   received_at, completed_at, returned_at, cancel_reason
              FROM warranty_cases
        SQL);

        // Cập nhật hàm write-through của INSTEAD OF trigger (tên hàm giữ nguyên
        // theo 000791 nên trigger hiện có tiếp tục trỏ đúng hàm).
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
                    received_at, completed_at, returned_at, cancel_reason
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
                    NEW.cancel_reason
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
                    "cancel_reason"=EXCLUDED."cancel_reason";
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
