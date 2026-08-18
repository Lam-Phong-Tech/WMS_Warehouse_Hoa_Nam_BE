<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-310 (bàn giao dữ liệu) - đưa `defect_catalogs` về đúng ERD entity 15.
 *
 * Bối cảnh: `defect_catalogs` là bảng canonical duy nhất chưa từng đi qua bước
 * căn chỉnh ERD 1.0 (2026_08_10_000750). Vì HN-WMS-ERD-DD-001 khi đó đặt tên
 * entity là `defect_catalog` (số ít - ngoại lệ duy nhất giữa 59 entity) nên cổng
 * parity giải về compatibility view chứ không phải kho lưu trữ thật, và mọi lệch
 * dưới đây đi lọt:
 *
 *  1. Khoá chính vẫn là `defect_id`; không có cột định danh `id` như 58 entity còn
 *     lại, nên không thể tạo FK theo đúng quan hệ tài liệu.
 *  2. `warranty_cases.defect_id` KHÔNG có FK nào - danh mục lỗi mồ côi vẫn ghi
 *     được vào ca bảo hành và chảy thẳng vào RPT-WAR-01 / RPT-WAR-COMP-01.
 *  3. `created_at`/`updated_at` là `timestamp without time zone` trong khi mọi báo
 *     cáo §13.10 chốt theo Asia/Ho_Chi_Minh và §0 ERD quy định timestamptz.
 *  4. `version` DEFAULT 1 thay vì 0 - lệch baseline optimistic locking/If-Match.
 *  5. `defect_code` varchar(50) và `defect_name` varchar(255) thay vì varchar(80)
 *     và varchar(200) như Data Dictionary.
 *  6. `created_by`/`updated_by` không có FK về `app_users`, và view compatibility
 *     `defect_catalog` chiếu hai cột này thành NULL literal dù bảng thật đã có dữ
 *     liệu - cùng nhóm lỗi view-layer đã xử lý ở reversal_documents.
 *
 * ERD-AMD-005 đổi tên entity thành `defect_catalogs`; migration này đưa hình dạng
 * vật lý về đúng tài liệu và giữ `defect_catalog` làm view đọc tương thích.
 */
return new class extends Migration
{
    private const TABLE = 'defect_catalogs';

    private const LEGACY_VIEW = 'defect_catalog';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        // View phụ thuộc mọi cột bên dưới; ALTER TYPE trên cột đang được view dùng
        // sẽ dừng ở SQLSTATE 0A000. Bỏ view trước rồi dựng lại ở cuối.
        HoaNamErdSchema::dropRelation(self::LEGACY_VIEW);

        $this->materializeCanonicalId();
        $this->alignColumnTypes();
        $this->alignDocumentedConstraints();
        $this->recreateCompatibilityView();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Fail-safe rollback (bài học WWHN-300): mọi lệnh phải chịu được việc đối
        // tượng đã không còn, nếu không migrate:rollback dừng giữa chừng.
        HoaNamErdSchema::dropRelation(self::LEGACY_VIEW);

        DB::statement('ALTER TABLE warranty_cases DROP CONSTRAINT IF EXISTS fk_erd_warranty_cases_defect_id');
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS fk_erd_defect_catalogs_created_by');
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS fk_erd_defect_catalogs_updated_by');
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS chk_defect_catalogs_status');
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_normalize_runtime_defect_catalogs ON '.self::TABLE);
        DB::statement('DROP FUNCTION IF EXISTS hn_erd_normalize_runtime_defect_catalogs()');

        if ($this->primaryKeyColumns() === ['id']) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS defect_catalogs_pkey');
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT defect_catalogs_pkey PRIMARY KEY (defect_id)');
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN version SET DEFAULT 1');
        DB::statement('DROP INDEX IF EXISTS ux_defect_catalogs_defect_id');

        $this->recreateCompatibilityView();
    }

    /**
     * Cột `id` là định danh canonical của ERD; `defect_id` giữ nguyên làm bí danh
     * legacy vì Eloquent model và mọi truy vấn hiện hành đang dùng nó làm khoá.
     * Đây đúng mẫu đã áp dụng cho agencies/containers/item_instances.
     */
    private function materializeCanonicalId(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'id')) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN id uuid');
        }

        DB::statement('UPDATE '.self::TABLE.' SET id = defect_id WHERE id IS DISTINCT FROM defect_id');

        // KHÔNG đặt DEFAULT trên `id`. PostgreSQL áp column default TRƯỚC khi chạy
        // BEFORE ROW trigger, nên có default thì NEW.id không bao giờ NULL và
        // trigger đồng bộ sẽ giữ một UUID ngẫu nhiên thay vì lấy theo defect_id —
        // cặp định danh lệch nhau ngay từ bản ghi đầu tiên. Bốn bảng bí danh còn
        // lại (agencies/containers/item_instances/physical_codes) cũng để trống
        // default vì đúng lý do này; trigger bên dưới mới là nơi sinh UUID.
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN id DROP DEFAULT');
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN id SET NOT NULL');

        // Trigger giữ id và defect_id luôn bằng nhau bất kể tầng nào ghi vào.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_defect_catalogs()
            RETURNS trigger AS $$
            BEGIN
                NEW.defect_id := COALESCE(NEW.defect_id, NEW.id, gen_random_uuid());
                NEW.id := COALESCE(NEW.id, NEW.defect_id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_normalize_runtime_defect_catalogs ON '.self::TABLE);
        DB::statement(
            'CREATE TRIGGER trg_erd_normalize_runtime_defect_catalogs BEFORE INSERT OR UPDATE ON '.self::TABLE.
            ' FOR EACH ROW EXECUTE FUNCTION hn_erd_normalize_runtime_defect_catalogs()'
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_defect_catalogs_defect_id ON '.self::TABLE.'(defect_id)');

        if ($this->primaryKeyColumns() !== ['id']) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS defect_catalogs_pkey');
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT defect_catalogs_pkey PRIMARY KEY (id)');
        }
    }

    private function alignColumnTypes(): void
    {
        // Nới rộng luôn an toàn.
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN defect_code TYPE varchar(80)');

        // Thu hẹp thì fail-closed: thà dừng migration với thông điệp hành động được
        // còn hơn cắt cụt dữ liệu master đang phục vụ báo cáo bảo hành.
        $overflow = (int) DB::scalar(
            'SELECT COUNT(*) FROM '.self::TABLE.' WHERE defect_name IS NOT NULL AND LENGTH(defect_name) > 200'
        );
        if ($overflow > 0) {
            throw new RuntimeException(
                "WWHN-310: {$overflow} dòng defect_catalogs.defect_name dài hơn 200 ký tự do Data Dictionary quy định. ".
                'Rút gọn các dòng này trước khi chạy lại migration; migration không tự cắt dữ liệu.'
            );
        }
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN defect_name TYPE varchar(200)');

        foreach (['created_at', 'updated_at'] as $column) {
            DB::statement(
                'ALTER TABLE '.self::TABLE.' ALTER COLUMN '.$column.
                " TYPE timestamptz USING {$column} AT TIME ZONE 'UTC'"
            );
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN '.$column.' SET DEFAULT now()');
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN version SET DEFAULT 0');
    }

    private function alignDocumentedConstraints(): void
    {
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS chk_defect_catalogs_status');
        DB::statement(
            'ALTER TABLE '.self::TABLE." ADD CONSTRAINT chk_defect_catalogs_status CHECK (status IN ('ACTIVE', 'INACTIVE'))"
        );

        foreach (['created_by', 'updated_by'] as $column) {
            $this->addValidatedForeignKey(self::TABLE, $column, 'fk_erd_defect_catalogs_'.$column, 'app_users', 'id');
        }

        // Quan hệ tài liệu warranty_cases.defect_id -> defect_catalogs.id (ERD bảng
        // 45). Giá trị lưu là defect_id, và trigger ở trên bảo đảm id = defect_id.
        $this->addValidatedForeignKey('warranty_cases', 'defect_id', 'fk_erd_warranty_cases_defect_id', self::TABLE, 'id');
    }

    /**
     * Thêm FK và validate ngay khi dữ liệu sạch; nếu còn bản ghi mồ côi thì tạo
     * NOT VALID để chặn dữ liệu sai mới mà không làm hỏng lần migrate của cutover.
     */
    private function addValidatedForeignKey(
        string $table,
        string $column,
        string $constraint,
        string $parentTable,
        string $parentColumn,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");

        $orphans = (int) DB::scalar(
            "SELECT COUNT(*) FROM {$table} child LEFT JOIN {$parentTable} parent ".
            "ON parent.{$parentColumn} = child.{$column} ".
            "WHERE child.{$column} IS NOT NULL AND parent.{$parentColumn} IS NULL"
        );

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) ".
            "REFERENCES {$parentTable}({$parentColumn}) ON DELETE RESTRICT".($orphans > 0 ? ' NOT VALID' : '')
        );
    }

    /**
     * View compatibility cũ chiếu created_by/updated_by thành NULL literal dù bảng
     * thật đã có hai cột đó - client đọc qua view mất traceability actor. Dựng lại
     * theo đúng cột thật.
     *
     * Bỏ view cũng xoá luôn INSTEAD OF trigger gắn trên nó, nên cầu ghi
     * trg_hn_legacy_defect_catalog_write (2026_08_10_000791) phải được dựng lại
     * ngay trong cùng bước; thiếu bước này thì mọi INSERT qua tên số ít im lặng
     * không vào bảng canonical.
     */
    private function recreateCompatibilityView(): void
    {
        HoaNamErdSchema::dropRelation(self::LEGACY_VIEW);
        DB::statement(<<<'SQL'
            CREATE VIEW defect_catalog AS
            SELECT
                defect_id,
                defect_code,
                defect_name,
                NULL::varchar(30) AS severity,
                status,
                created_at,
                updated_at,
                version,
                id,
                description,
                created_by,
                updated_by
            FROM defect_catalogs
        SQL);

        $this->recreateLegacyWriteBridge();
    }

    /**
     * Cầu ghi INSTEAD OF của view số ít. So với bản 000791, bản này chuyển tiếp
     * thêm created_by/updated_by (trước bị bỏ rơi cùng lúc view chiếu NULL literal)
     * và lấy version mặc định 0 theo Data Dictionary.
     */
    private function recreateLegacyWriteBridge(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_defect_catalog_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    DELETE FROM defect_catalogs WHERE defect_id = OLD.defect_id;
                    RETURN OLD;
                END IF;

                INSERT INTO defect_catalogs (
                    defect_id, id, defect_code, defect_name, description, status,
                    version, created_by, updated_by, created_at, updated_at
                ) VALUES (
                    COALESCE(NEW.defect_id, NEW.id, gen_random_uuid()),
                    COALESCE(NEW.id, NEW.defect_id, gen_random_uuid()),
                    NEW.defect_code,
                    NEW.defect_name,
                    NEW.description,
                    COALESCE(NEW.status, 'ACTIVE'),
                    COALESCE(NEW.version, 0),
                    NEW.created_by,
                    NEW.updated_by,
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP),
                    COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)
                )
                ON CONFLICT (defect_id) DO UPDATE SET
                    defect_code = EXCLUDED.defect_code,
                    defect_name = EXCLUDED.defect_name,
                    description = EXCLUDED.description,
                    status = EXCLUDED.status,
                    version = EXCLUDED.version,
                    created_by = COALESCE(EXCLUDED.created_by, defect_catalogs.created_by),
                    updated_by = COALESCE(EXCLUDED.updated_by, defect_catalogs.updated_by),
                    created_at = EXCLUDED.created_at,
                    updated_at = EXCLUDED.updated_at;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_hn_legacy_defect_catalog_write ON '.self::LEGACY_VIEW);
        DB::statement(
            'CREATE TRIGGER trg_hn_legacy_defect_catalog_write INSTEAD OF INSERT OR UPDATE OR DELETE ON '.
            self::LEGACY_VIEW.' FOR EACH ROW EXECUTE FUNCTION hn_legacy_defect_catalog_write()'
        );
    }

    /** @return array<int, string> */
    private function primaryKeyColumns(): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT array_agg(a.attname ORDER BY x.ordinality)::text AS columns
            FROM pg_constraint c
            JOIN unnest(c.conkey) WITH ORDINALITY AS x(attnum, ordinality) ON true
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = x.attnum
            WHERE c.conrelid = 'defect_catalogs'::regclass AND c.contype = 'p'
            GROUP BY c.oid
        SQL);

        if ($row === null) {
            return [];
        }

        return collect(explode(',', trim((string) $row->columns, '{}')))
            ->map(fn (string $item): string => trim($item, '" '))
            ->filter()
            ->values()
            ->all();
    }
};
