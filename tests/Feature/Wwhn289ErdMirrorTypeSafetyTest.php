<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WWHN-289 / DEV289-P1-02R — khoá hồi quy cho defect `BUILD289-04`.
 *
 * Vì sao cần bài này: gate closure của WWHN-289 (`.github/workflows/wwhn-289-closure.yml`)
 * đỏ 3/3 run tại bước "Migrate and seed PostgreSQL" với
 *   SQLSTATE[42804]: column "created_by" is of type uuid but expression is of type text
 *   CONTEXT: PL/pgSQL function hn_erd_mirror_pv_industries()
 * Không một test nào trong repo phát hiện được điều đó, vì write-bridge
 * `hn_erd_mirror_*` chỉ nổ khi có ai đó GHI vào bảng nguồn — và trên sqlite (driver mặc
 * định của suite) những function này thậm chí không tồn tại. Hệ quả: một lỗi chặn toàn bộ
 * CI PostgreSQL đi lọt qua mọi cổng cho tới khi nó làm hỏng gate của ticket khác.
 *
 * Bài test đóng đúng khoảng hở đó theo hai chân độc lập:
 *   (1) TĨNH  — mọi cặp cột nguồn/đích của write-bridge sinh tự động phải cùng type,
 *               hoặc PostgreSQL tự ép được, hoặc phải có cast tường minh trong định nghĩa.
 *   (2) ĐỘNG  — ghi thật vào bảng nguồn để trigger chạy; nếu còn cặp type nào chưa cast
 *               thì lệnh ghi ném 42804 ngay tại đây thay vì ở `migrate:fresh` của CI.
 *
 * Chạy read-mostly trên catalog nên không dùng RefreshDatabase; chân (2) tự cuộn lại bằng
 * transaction. Trên driver không phải pgsql bài này không có đối tượng để kiểm và được skip.
 */
class Wwhn289ErdMirrorTypeSafetyTest extends TestCase
{
    /** Mẫu write-bridge sinh tự động bởi migration 2026_08_10_000765. */
    private const GENERIC_BRIDGE = '/INSERT INTO "(?P<canonical>[^"]+)" \([^)]*\) SELECT (?P<select>[^;]*?) FROM "(?P<projection>[^"]*__legacy_projection)"/s';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Write-bridge hn_erd_mirror_* chỉ tồn tại trên PostgreSQL.');
        }
    }

    public function test_every_generated_write_bridge_casts_columns_postgres_cannot_coerce(): void
    {
        $functions = DB::select(<<<'SQL'
            SELECT p.proname AS name, pg_get_functiondef(p.oid) AS definition
              FROM pg_proc p
              JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = current_schema()
               AND p.proname LIKE 'hn\_erd\_mirror\_%'
             ORDER BY p.proname
        SQL);

        $this->assertNotEmpty($functions, 'Schema PostgreSQL phải có write-bridge hn_erd_mirror_*.');

        $offenders = [];

        foreach ($functions as $function) {
            if (preg_match(self::GENERIC_BRIDGE, $function->definition, $matches) !== 1) {
                continue; // Function soạn tay (audit_logs, idempotency_records) đã cast sẵn.
            }

            $canonical = $this->columnTypes($matches['canonical']);
            $projection = $this->columnTypes($matches['projection']);

            foreach (explode(', ', $matches['select']) as $token) {
                if (preg_match('/^"(?P<column>[^"]+)"$/', trim($token), $bare) !== 1) {
                    continue; // Đã có cast tường minh hoặc CASE WHEN — đúng thứ ta muốn thấy.
                }

                $column = $bare['column'];

                if (! isset($canonical[$column], $projection[$column])) {
                    continue;
                }

                if ($canonical[$column] === $projection[$column]) {
                    continue;
                }

                if ($this->postgresCoercesOnAssignment($projection[$column], $canonical[$column])) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s: cột "%s" ghi %s -> %s mà không có cast (PostgreSQL không tự ép được cặp type này)',
                    $function->name,
                    $column,
                    $projection[$column],
                    $canonical[$column]
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Write-bridge còn cột không cast được — đây chính là lỗi 42804 đã làm CI PostgreSQL đỏ:\n".implode("\n", $offenders)
        );
    }

    public function test_writing_to_a_pv_source_table_fires_its_mirror_without_datatype_error(): void
    {
        $sources = ['pv_industry', 'pv_filter_tag', 'pv_product_industry'];
        $exercised = 0;

        DB::beginTransaction();

        try {
            foreach ($sources as $source) {
                if (! $this->relationExists($source)) {
                    continue;
                }

                // Cột để self-assign lấy từ catalog chứ không đoán tên: `pv_product_industry`
                // là bảng nối, không có `updated_at` — đoán tên cột làm bài test đỏ vì lý do
                // không liên quan tới thứ nó đang chứng minh.
                $column = $this->writableColumn($source);

                if ($column === null) {
                    continue;
                }

                // FOR EACH STATEMENT nên trigger chạy kể cả khi không có dòng nào khớp:
                // đủ để bắt lỗi type, không phụ thuộc dữ liệu seed.
                DB::statement('UPDATE '.$source.' SET "'.$column.'" = "'.$column.'"');
                $exercised++;
            }
        } finally {
            DB::rollBack();
        }

        $this->assertGreaterThan(
            0,
            $exercised,
            'Phải ghi được ít nhất một bảng nguồn PV để kích hoạt write-bridge; nếu không, bài test này vô nghĩa.'
        );
    }

    /** @return array<string,string> tên cột -> typname trong catalog. */
    private function columnTypes(string $relation): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.attname AS column_name, t.typname AS typname
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
            $types[$row->column_name] = $row->typname;
        }

        return $types;
    }

    /** Một cột ghi lại được của bảng (bỏ identity/generated) để self-assign kích hoạt trigger. */
    private function writableColumn(string $relation): ?string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT a.attname AS column_name
              FROM pg_attribute a
              JOIN pg_class c ON c.oid = a.attrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema()
               AND c.relname = ?
               AND a.attnum > 0
               AND NOT a.attisdropped
               AND a.attidentity = ''
               AND a.attgenerated = ''
             ORDER BY a.attnum
             LIMIT 1
        SQL, [$relation]);

        return $row->column_name ?? null;
    }

    private function relationExists(string $relation): bool
    {
        return DB::selectOne(<<<'SQL'
            SELECT 1 AS ok
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema()
               AND c.relname = ?
               AND c.relkind IN ('r', 'v')
        SQL, [$relation]) !== null;
    }

    /** Cùng luật với migration 2026_08_19_000050: pg_cast implicit ('i') hoặc assignment ('a'). */
    private function postgresCoercesOnAssignment(string $source, string $target): bool
    {
        return DB::selectOne(<<<'SQL'
            SELECT 1 AS ok
              FROM pg_cast c
              JOIN pg_type s ON s.oid = c.castsource
              JOIN pg_type t ON t.oid = c.casttarget
             WHERE s.typname = ?
               AND t.typname = ?
               AND c.castcontext IN ('i', 'a')
        SQL, [$source, $target]) !== null;
    }
}
