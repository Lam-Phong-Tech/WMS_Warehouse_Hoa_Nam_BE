<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-289 (DEV289-P1-02R) — sửa gốc lỗi làm mọi CI PostgreSQL đỏ.
 *
 * TRIỆU CHỨNG: `php artisan migrate:fresh` trên PostgreSQL vỡ tại
 * `2026_08_19_000100_add_optimistic_lock_to_pv_master_tables` với
 *   SQLSTATE[42804]: column "created_by" is of type uuid but expression is of type text
 *   CONTEXT: PL/pgSQL function hn_erd_mirror_pv_industries() line 1
 *   STATEMENT: update "pv_industry" set "version" = $1 where "version" is null
 *
 * GỐC: `2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync`
 * (dòng 417) sinh write-bridge dạng
 *   INSERT INTO <canonical> (cols) SELECT cols FROM <canonical>__legacy_projection
 * KHÔNG có cast nào, tức là nó ngầm giả định PostgreSQL luôn tự ép được type cột
 * view sang type cột bảng vật lý. Giả định đó đúng với hầu hết cặp type (INSERT
 * ... SELECT dùng assignment cast) nhưng SAI với nhóm PV: projection dựng ở
 * `2026_08_09_000400_align_latest_erd_baseline` (dòng 427-428) khai
 * `NULL AS created_by/updated_by` — PostgreSQL suy ra `text` — còn ERD bảng
 * 51..54 định nghĩa các cột đó là `uuid`, mà text -> uuid KHÔNG có assignment
 * cast (chỉ ép được bằng cast tường minh). Trigger chỉ nổ khi có ai đó GHI vào
 * bảng nguồn, nên lỗi nằm im đến khi WWHN-252 thêm cột `version` rồi backfill —
 * không phải lỗi của WWHN-252, mà là lỗi type-safety có sẵn của write-bridge.
 *
 * CÁCH SỬA: chèn cast tường minh vào function `hn_erd_mirror_*` sinh tự động,
 * NHƯNG chỉ cho đúng những cột PostgreSQL không tự ép được. Cột nào đã có
 * implicit/assignment cast trong `pg_cast` (varchar->text, timestamp->timestamptz,
 * int4->int8, ...) thì giữ nguyên văn: chúng vốn đã chạy đúng, viết lại chỉ làm
 * rộng bán kính ảnh hưởng mà không sửa gì. Trên baseline hiện tại luật này chạm
 * đúng ba function PV (`pv_industries`, `pv_tags`, `pv_product_industries`) và
 * để nguyên byte-for-byte toàn bộ function còn lại. Phần
 * `FROM ... ON CONFLICT ... DO UPDATE/NOTHING ...` cùng mệnh đề cleanup giữ
 * nguyên văn nên KHÔNG đổi semantics upsert mà các ticket khác đang dựa vào.
 *
 * Không sửa file migration của WWHN-252 (nhánh m1 dùng chung, nhiều phiên đang
 * làm song song), và cũng không sửa được bằng cách đổi kiểu cột view:
 * `CREATE OR REPLACE VIEW` không đổi được type cột của view đang tồn tại.
 * Migration này mang mốc thời gian NHỎ HƠN 000100 để chạy trước và biến điều
 * kiện tiên quyết của 000100 thành đúng.
 *
 * Cột `created_by`/`updated_by` là uuid nullable nên dùng đúng thành ngữ cast có
 * canh đã có trong repo (000765 dòng 325, 444): chỉ cast khi chuỗi đúng dạng
 * uuid, ngược lại NULL — không bao giờ làm ngã một lần ghi hợp lệ. Target NOT
 * NULL thì rơi xuống cast trần để lỗi nổ to thay vì âm thầm ghi sai.
 */
return new class extends Migration
{
    private const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    /** @var array<string, bool> cache "source>target" -> PostgreSQL tự ép được. */
    private array $coercible = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->generatedMirrorFunctions() as $function) {
            $repaired = $this->repairDefinition($function->definition);
            if ($repaired === null) {
                continue;
            }

            DB::unprepared($repaired);
        }
    }

    public function down(): void
    {
        // Fail-forward có chủ ý: quay lại bản không cast nghĩa là dựng lại đúng
        // lỗi 42804 đang chặn migrate:fresh. Định nghĩa mới là superset hành vi
        // (cột PostgreSQL tự ép được sinh ra biểu thức y nguyên), nên không có
        // gì để hoàn tác — và down() không đụng schema thì không thể brick DB.
    }

    /**
     * @return list<object{name:string,definition:string}>
     */
    private function generatedMirrorFunctions(): array
    {
        return DB::select(<<<'SQL'
            SELECT p.proname AS name, pg_get_functiondef(p.oid) AS definition
              FROM pg_proc p
              JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = current_schema()
               AND p.proname LIKE 'hn\_erd\_mirror\_%'
             ORDER BY p.proname
        SQL);
    }

    /**
     * Trả về định nghĩa function đã chèn cast, hoặc null nếu không cần/không
     * nhận dạng được hình dạng sinh tự động (fail-safe: thà bỏ qua hơn là viết
     * lại sai một function ai đó soạn tay).
     */
    private function repairDefinition(string $definition): ?string
    {
        $pattern = '/(?P<head>INSERT INTO "(?P<canonical>[^"]+)" \([^)]*\) SELECT )'
            .'(?P<select>[^;]*?)'
            .'(?P<tail> FROM "(?P<projection>[^"]*__legacy_projection)")/s';

        if (preg_match($pattern, $definition, $matches) !== 1) {
            return null;
        }

        // Đã có cast (do lần chạy trước, hoặc function soạn tay) thì không đụng.
        if (str_contains($matches['select'], '::') || str_contains($matches['select'], 'CASE WHEN')) {
            return null;
        }

        $canonicalTypes = $this->columnTypes($matches['canonical']);
        $projectionTypes = $this->columnTypes($matches['projection']);
        if ($canonicalTypes === [] || $projectionTypes === []) {
            return null;
        }

        $changed = false;
        $expressions = [];
        foreach (explode(', ', $matches['select']) as $token) {
            $column = trim($token, '"');
            if (! preg_match('/^"[^"]+"$/', $token) || ! isset($canonicalTypes[$column], $projectionTypes[$column])) {
                // Danh sách SELECT không phải toàn cột trần -> không nhận dạng được.
                return null;
            }

            $expression = $this->castExpression(
                $token,
                $projectionTypes[$column]['udt'],
                $canonicalTypes[$column]['udt'],
                $canonicalTypes[$column]['nullable'],
            );

            $changed = $changed || $expression !== $token;
            $expressions[] = $expression;
        }

        if (! $changed) {
            return null;
        }

        return str_replace(
            $matches['head'].$matches['select'].$matches['tail'],
            $matches['head'].implode(', ', $expressions).$matches['tail'],
            $definition,
        );
    }

    private function castExpression(string $token, string $sourceUdt, string $targetUdt, bool $nullable): string
    {
        // Chỉ can thiệp vào cột INSERT ... SELECT hôm nay chắc chắn ném 42804.
        if ($sourceUdt === $targetUdt || $this->postgresCoercesOnAssignment($sourceUdt, $targetUdt)) {
            return $token;
        }

        if ($targetUdt === 'uuid' && $nullable) {
            return "CASE WHEN {$token} ~* '".self::UUID_PATTERN."' THEN {$token}::uuid ELSE NULL END";
        }

        return $token.'::'.$targetUdt;
    }

    /**
     * PostgreSQL tự ép được source -> target trong INSERT ... SELECT khi
     * `pg_cast` có entry implicit ('i') hoặc assignment ('a') — ví dụ
     * varchar->text, timestamp->timestamptz, int4->int8. text->uuid không có
     * entry nào nên bắt buộc cast tường minh; đó chính là ca đang hỏng.
     */
    private function postgresCoercesOnAssignment(string $sourceUdt, string $targetUdt): bool
    {
        $key = $sourceUdt.'>'.$targetUdt;

        return $this->coercible[$key] ??= DB::selectOne(<<<'SQL'
            SELECT 1 AS ok
              FROM pg_cast c
              JOIN pg_type s ON s.oid = c.castsource
              JOIN pg_type t ON t.oid = c.casttarget
             WHERE s.typname = ? AND t.typname = ? AND c.castcontext IN ('i', 'a')
             LIMIT 1
        SQL, [$sourceUdt, $targetUdt]) !== null;
    }

    /**
     * @return array<string, array{udt:string,nullable:bool}>
     */
    private function columnTypes(string $relation): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.attname AS column_name,
                   t.typname AS udt,
                   NOT a.attnotnull AS nullable
              FROM pg_attribute a
              JOIN pg_class c ON c.oid = a.attrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
              JOIN pg_type t ON t.oid = a.atttypid
             WHERE n.nspname = current_schema()
               AND c.relname = ?
               AND a.attnum > 0
               AND NOT a.attisdropped
        SQL, [$relation]);

        $types = [];
        foreach ($rows as $row) {
            $types[$row->column_name] = ['udt' => $row->udt, 'nullable' => (bool) $row->nullable];
        }

        return $types;
    }
};
