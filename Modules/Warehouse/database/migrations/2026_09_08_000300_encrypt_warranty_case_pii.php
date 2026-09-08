<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Support\WarrantyCasePii;

/**
 * WWHN-SEC-06 · Phase 1 cho `warranty_cases` — thêm cột ciphertext + blind index.
 * KHÔNG xoá gì; migration Phase 6 (2026_09_08_000400) mới xoá plaintext.
 *
 * BA TẦNG PHẢI SỬA CÙNG NHAU, THIẾU MỘT LÀ HỎNG IM LẶNG
 *
 * `warranty_case` không phải bảng. Nó là VIEW trên bảng `warranty_cases`, cộng
 * trigger `trg_hn_legacy_warranty_case_write` (`INSTEAD OF INSERT/UPDATE/DELETE`
 * -> hàm `hn_legacy_warranty_case_write()`). Toàn bộ ứng dụng đọc/ghi qua VIEW.
 *
 * Vì vậy thêm cột phải làm đủ ba việc:
 *   1. ALTER bảng gốc `warranty_cases`
 *   2. dựng lại VIEW để lộ cột mới
 *   3. sửa hàm trigger cho cột mới đi qua
 *
 * Chỉ làm bước 1 thì ứng dụng ghi qua view sẽ không bao giờ chạm tới cột mới, và
 * không có lỗi nào báo — dữ liệu chỉ đơn giản là không được mã hoá.
 *
 * sqlite (bộ test) không có view/trigger legacy: ở đó `warranty_case` là bảng
 * thật, nên chỉ cần bước 1.
 */
return new class extends Migration
{
    private const BASE_TABLE = 'warranty_cases';

    private const VIEW = 'warranty_case';

    public function up(): void
    {
        $target = $this->targetRelation();

        if ($target === null) {
            return;
        }

        Schema::table($target, function (Blueprint $table) use ($target): void {
            if (! Schema::hasColumn($target, 'customer_name_ciphertext')) {
                $table->text('customer_name_ciphertext')->nullable();
            }
            if (! Schema::hasColumn($target, 'customer_phone_ciphertext')) {
                $table->text('customer_phone_ciphertext')->nullable();
            }
            if (! Schema::hasColumn($target, 'customer_address_ciphertext')) {
                $table->text('customer_address_ciphertext')->nullable();
            }
            if (! Schema::hasColumn($target, 'customer_phone_lookup')) {
                // "<key_id>:<64 hex>"
                $table->string('customer_phone_lookup', 96)->nullable();
            }
            if (! Schema::hasColumn($target, 'customer_name_lookup')) {
                // Chuỗi vân tay từng TỪ nối bằng dấu cách -> độ dài không cố định.
                $table->text('customer_name_lookup')->nullable();
            }
        });

        if (! $this->isPostgres()) {
            return;
        }

        // `customer_phone_lookup` thay thế vai trò tra cứu của cột
        // `customer_phone` (vốn có index từ migration ERD gốc).
        DB::statement('CREATE INDEX IF NOT EXISTS idx_warranty_cases_customer_phone_lookup ON warranty_cases (customer_phone_lookup)');

        // `customer_name` và `customer_phone` đang NOT NULL. Từ lúc code mới chạy,
        // hồ sơ MỚI chỉ ghi ciphertext và để plaintext trống — không nới ràng buộc
        // ở đây thì mọi lần tiếp nhận bảo hành sẽ ném 23502 ngay khi deploy.
        // (Đã đo: "null value in column customer_name violates not-null constraint".)
        foreach (['customer_name', 'customer_phone', 'customer_address'] as $column) {
            if (Schema::hasColumn(self::BASE_TABLE, $column)) {
                DB::statement('ALTER TABLE warranty_cases ALTER COLUMN '.$column.' DROP NOT NULL');
            }
        }

        $this->rebuildLegacyView();
        $this->rebuildLegacyWriteTrigger();
        $this->attachLegacyWriteTrigger();
    }

    public function down(): void
    {
        $target = $this->targetRelation();

        if ($target === null) {
            return;
        }

        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS idx_warranty_cases_customer_phone_lookup');

            // THỨ TỰ QUAN TRỌNG: view/trigger phải thôi tham chiếu các cột mới
            // TRƯỚC khi xoá chúng. Làm ngược lại thì PostgreSQL từ chối với
            // "cannot drop column ... because other objects depend on it" và
            // migration hỏng giữa chừng (đã đo 08/09/2026).
            $this->rebuildLegacyView(withPiiColumns: false);
            $this->rebuildLegacyWriteTrigger(withPiiColumns: false);
            $this->attachLegacyWriteTrigger();
        }

        Schema::table($target, function (Blueprint $table) use ($target): void {
            foreach ([
                'customer_name_ciphertext',
                'customer_phone_ciphertext',
                'customer_address_ciphertext',
                'customer_phone_lookup',
                'customer_name_lookup',
            ] as $column) {
                if (Schema::hasColumn($target, $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // CỐ Ý KHÔNG khôi phục NOT NULL cho customer_name/customer_phone: nếu đã
        // có hồ sơ ghi bằng code mới (plaintext = NULL) thì SET NOT NULL ném lỗi
        // và làm `migrate:rollback` hỏng giữa chừng. Chạy
        // `pii:warranty-cases --restore-plaintext` trước nếu cần siết lại.
    }

    /**
     * Trên PostgreSQL thì ghi vào bảng gốc; trên sqlite `warranty_case` là bảng
     * thật và không có `warranty_cases`.
     */
    private function targetRelation(): ?string
    {
        $table = WarrantyCasePii::baseTable();

        return Schema::hasTable($table) ? $table : null;
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Danh sách cột của view giữ NGUYÊN thứ tự và tên cũ, chỉ NỐI THÊM cột mới
     * vào cuối. Đổi thứ tự cột của một view mà code khác đang `SELECT *` là cách
     * chắc chắn để phá một thứ không liên quan.
     */
    private function rebuildLegacyView(bool $withPiiColumns = true): void
    {
        $columns = [
            'id AS warranty_case_id',
            'warranty_case_code',
            'item_id AS inventory_serial_id',
            'sku_id AS product_id',
            'temporary_item_code',
            'identification_status',
            'missing_code_reason',
            'manual_product_description',
            'defect_id',
            'customer_name',
            'customer_phone',
            'status',
            'condition_status',
            'description',
            'created_by',
            'version',
            'closed_at',
            'retention_until',
            'legal_hold_flag',
            'created_at',
            'updated_at',
            'NULL::timestamp with time zone AS deleted_at',
            'item_id',
            'customer_address',
            'purchase_date',
            'sales_trace_status',
            'reported_defect',
            'confirmed_defect',
            'accessories_received',
            'received_condition',
            'received_at',
            'completed_at',
            'returned_at',
            'cancel_reason',
            'estimated_completion_date',
            'updated_by',
        ];

        // Sau Phase 6 ba cột plaintext không còn tồn tại trên bảng gốc.
        $columns = array_values(array_filter(
            $columns,
            fn (string $column): bool => ! in_array($column, ['customer_name', 'customer_phone', 'customer_address'], true)
                || Schema::hasColumn(self::BASE_TABLE, $column),
        ));

        if ($withPiiColumns) {
            $columns = array_merge($columns, [
                'customer_name_ciphertext',
                'customer_phone_ciphertext',
                'customer_address_ciphertext',
                'customer_phone_lookup',
                'customer_name_lookup',
            ]);
        }

        // DROP + CREATE chứ KHÔNG `CREATE OR REPLACE`: PostgreSQL từ chối
        // `CREATE OR REPLACE VIEW` khi số cột GIẢM ("cannot drop columns from
        // view"), tức là nhánh rollback sẽ hỏng. Đã kiểm: không có view nào khác
        // phụ thuộc `warranty_case`, nên DROP là an toàn.
        //
        // Trigger `INSTEAD OF` gắn vào chính VIEW nên bị xoá theo — phải tạo lại
        // ngay sau đó, nếu không mọi INSERT/UPDATE vào view sẽ im lặng không ghi
        // được (PostgreSQL báo "cannot insert into view").
        DB::statement('DROP VIEW IF EXISTS '.self::VIEW);
        DB::statement('CREATE VIEW '.self::VIEW.' AS SELECT '.implode(', ', $columns).' FROM '.self::BASE_TABLE);
    }

    private function attachLegacyWriteTrigger(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS trg_hn_legacy_warranty_case_write ON '.self::VIEW.';'
            .' CREATE TRIGGER trg_hn_legacy_warranty_case_write'
            .' INSTEAD OF INSERT OR UPDATE OR DELETE ON '.self::VIEW
            .' FOR EACH ROW EXECUTE FUNCTION hn_legacy_warranty_case_write();'
        );
    }

    /**
     * Hàm trigger được viết lại theo DANH SÁCH CỘT ĐỘNG để cùng một migration
     * chạy đúng ở cả trạng thái trước và sau Phase 6.
     */
    private function rebuildLegacyWriteTrigger(bool $withPiiColumns = true): void
    {
        // [cột bảng gốc, biểu thức lấy từ NEW]
        $mapping = [
            ['id', 'COALESCE(NEW.warranty_case_id, gen_random_uuid())'],
            ['warranty_case_code', 'NEW.warranty_case_code'],
            ['item_id', 'COALESCE(NEW.item_id, NEW.inventory_serial_id)'],
            ['sku_id', 'NEW.product_id'],
            ['temporary_item_code', 'NEW.temporary_item_code'],
            ['identification_status', "COALESCE(NEW.identification_status, 'IDENTIFIED')"],
            ['missing_code_reason', 'NEW.missing_code_reason'],
            ['manual_product_description', 'NEW.manual_product_description'],
            ['defect_id', 'NEW.defect_id'],
            ['customer_name', 'NEW.customer_name'],
            ['customer_phone', 'NEW.customer_phone'],
            ['customer_address', 'NEW.customer_address'],
            ['purchase_date', 'NEW.purchase_date'],
            ['sales_trace_status', "COALESCE(NEW.sales_trace_status, 'NOT_TRACEABLE')"],
            ['status', "COALESCE(NEW.status, 'RECEIVED')"],
            ['condition_status', 'NEW.condition_status'],
            ['description', 'NEW.description'],
            ['created_by', 'NEW.created_by'],
            ['version', 'COALESCE(NEW.version, 1)'],
            ['closed_at', 'NEW.closed_at'],
            ['retention_until', 'NEW.retention_until'],
            ['legal_hold_flag', 'COALESCE(NEW.legal_hold_flag, false)'],
            ['created_at', 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)'],
            ['updated_at', 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)'],
            ['reported_defect', 'COALESCE(NEW.reported_defect, NEW.description)'],
            ['confirmed_defect', 'NEW.confirmed_defect'],
            ['accessories_received', 'NEW.accessories_received'],
            ['received_condition', 'COALESCE(NEW.received_condition, NEW.condition_status)'],
            ['received_at', 'COALESCE(NEW.received_at, NEW.created_at, CURRENT_TIMESTAMP)'],
            ['completed_at', 'NEW.completed_at'],
            ['returned_at', 'NEW.returned_at'],
            ['cancel_reason', 'NEW.cancel_reason'],
            ['estimated_completion_date', 'NEW.estimated_completion_date'],
            ['updated_by', 'NEW.updated_by'],
        ];

        $mapping = array_values(array_filter(
            $mapping,
            fn (array $pair): bool => ! in_array($pair[0], ['customer_name', 'customer_phone', 'customer_address'], true)
                || Schema::hasColumn(self::BASE_TABLE, $pair[0]),
        ));

        if ($withPiiColumns) {
            $mapping = array_merge($mapping, [
                ['customer_name_ciphertext', 'NEW.customer_name_ciphertext'],
                ['customer_phone_ciphertext', 'NEW.customer_phone_ciphertext'],
                ['customer_address_ciphertext', 'NEW.customer_address_ciphertext'],
                ['customer_phone_lookup', 'NEW.customer_phone_lookup'],
                ['customer_name_lookup', 'NEW.customer_name_lookup'],
            ]);
        }

        $insertColumns = implode(', ', array_map(static fn (array $p): string => '"'.$p[0].'"', $mapping));
        $insertValues = implode(', ', array_map(static fn (array $p): string => $p[1], $mapping));
        $updateSet = implode(', ', array_map(
            static fn (array $p): string => '"'.$p[0].'"=EXCLUDED."'.$p[0].'"',
            array_filter($mapping, static fn (array $p): bool => $p[0] !== 'id'),
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.hn_legacy_warranty_case_write()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM warranty_cases WHERE id = OLD.warranty_case_id;
                    RETURN OLD;
                END IF;
                INSERT INTO warranty_cases ({$insertColumns})
                VALUES ({$insertValues})
                ON CONFLICT ("id") DO UPDATE SET {$updateSet};
                RETURN NEW;
            END;
            \$function\$
        SQL);
    }
};
