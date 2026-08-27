<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class HoaNamErdSchema
{
    public const DOCUMENT_PATH = 'docs/HoaNam_ERD_Moi_Nhat.md';

    public const DOCUMENT_ID = 'HN-WMS-ERD-DD-001';

    public const TABLE_COUNT = 60;

    /**
     * @return array<string, array{section:string,fields:array<string,array{type:string,nullable:bool,key:string}>}>
     */
    public static function parse(): array
    {
        $path = base_path(self::DOCUMENT_PATH);
        $markdown = (string) file_get_contents($path);
        if (! str_contains($markdown, self::DOCUMENT_ID)) {
            throw new RuntimeException(self::DOCUMENT_PATH.' is not '.self::DOCUMENT_ID.'.');
        }

        preg_match_all('/^###\s+(\d+)\.\s+([a-z0-9_]+)\s*$/mi', $markdown, $headers, PREG_OFFSET_CAPTURE);
        $schema = [];
        $count = count($headers[0] ?? []);

        for ($i = 0; $i < $count; $i++) {
            $table = strtolower($headers[2][$i][0]);
            $start = $headers[0][$i][1] + strlen($headers[0][$i][0]);
            $end = $i + 1 < $count ? $headers[0][$i + 1][1] : strlen($markdown);
            $section = substr($markdown, $start, $end - $start);
            $fields = self::parseFields($section);

            if ($fields === []) {
                throw new RuntimeException("No Data Dictionary fields parsed for {$table}.");
            }
            $schema[$table] = ['section' => $section, 'fields' => $fields];
        }

        if (count($schema) !== self::TABLE_COUNT) {
            throw new RuntimeException(sprintf(
                '%s must contain exactly %d numbered physical tables; parsed %d.',
                self::DOCUMENT_ID,
                self::TABLE_COUNT,
                count($schema),
            ));
        }

        return $schema;
    }

    /**
     * Support both the original Pandoc/grid table format and standard Markdown
     * pipe tables. BA documents are source-controlled artifacts, so a harmless
     * presentation-format change must not make schema materialization fail.
     *
     * @return array<string, array{type:string,nullable:bool,key:string}>
     */
    private static function parseFields(string $section): array
    {
        preg_match_all(
            '/^\s*\*\*([a-z0-9_]+)\*\*\s+([a-z]+(?:\(\s*\d+\s*(?:,\s*\d+\s*)?\))?)\s+(Yes|No)\s+(.*)$/mi',
            $section,
            $gridRows,
            PREG_SET_ORDER,
        );

        $fields = self::normalizeRows($gridRows);
        if ($fields !== []) {
            return $fields;
        }

        preg_match_all(
            '/^\s*\|\s*\*\*([a-z0-9_]+)\*\*\s*\|\s*([a-z]+(?:\(\s*\d+\s*(?:,\s*\d+\s*)?\))?)\s*\|\s*(Yes|No)\s*\|\s*([^|]*)\|.*$/mi',
            $section,
            $pipeRows,
            PREG_SET_ORDER,
        );

        return self::normalizeRows($pipeRows);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, array{type:string,nullable:bool,key:string}>
     */
    private static function normalizeRows(array $rows): array
    {
        $fields = [];
        foreach ($rows as $row) {
            $fields[strtolower($row[1])] = [
                'type' => strtolower(preg_replace('/\s+/', '', $row[2]) ?? $row[2]),
                'nullable' => strcasecmp($row[3], 'Yes') === 0,
                'key' => trim($row[4]),
            ];
        }

        return $fields;
    }

    public static function relationKind(string $table): ?string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }

        $row = DB::selectOne(<<<'SQL'
            SELECT c.relkind
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema()
              AND c.relname = ?
            LIMIT 1
        SQL, [$table]);

        return $row?->relkind;
    }

    /**
     * WWHN-300 (GoLive rollback control): drop whatever relation currently
     * occupies $name, regardless of whether it is a view or a physical table.
     *
     * The migration chain contains fail-forward steps — most notably
     * 2026_08_10_000791_retire_remaining_singular_legacy_tables, whose down()
     * is intentionally a no-op — that swap a name from table to view (or the
     * reverse) on PostgreSQL. A later down() that hardcodes DROP VIEW then
     * aborts with SQLSTATE 42809 ("... is not a view"), which stops
     * migrate:rollback half-way and leaves a schema that can neither serve
     * traffic nor be re-migrated forward. Dropping by the relation's ACTUAL
     * kind keeps the rollback path deterministic.
     *
     * Call sites must be destructive by intent: down(), or an up() that has
     * already established the name is not a data-bearing canonical table.
     */
    public static function dropRelation(string $name, bool $cascade = true): void
    {
        $driver = DB::getDriverName();
        $quoted = self::quote($name);
        $suffix = $cascade && $driver === 'pgsql' ? ' CASCADE' : '';

        if ($driver === 'pgsql') {
            match (self::relationKind($name)) {
                'v' => DB::statement('DROP VIEW IF EXISTS '.$quoted.$suffix),
                'm' => DB::statement('DROP MATERIALIZED VIEW IF EXISTS '.$quoted.$suffix),
                'r', 'p', 'f' => DB::statement('DROP TABLE IF EXISTS '.$quoted.$suffix),
                default => null,
            };

            return;
        }

        if ($driver === 'sqlite') {
            $type = DB::selectOne(
                "SELECT type FROM sqlite_master WHERE name = ? AND type IN ('view', 'table') LIMIT 1",
                [$name]
            )?->type;

            match ($type) {
                'view' => DB::statement('DROP VIEW IF EXISTS '.$quoted),
                'table' => DB::statement('DROP TABLE IF EXISTS '.$quoted),
                default => null,
            };

            return;
        }

        DB::statement('DROP VIEW IF EXISTS '.$quoted);
        Schema::dropIfExists($name);
    }

    /**
     * WWHN-300 (GoLive rollback control): drop an index only when it is still
     * present. Blueprint::dropIndex() emits an unconditional DROP INDEX, so a
     * down() that removes an index a later migration already renamed or
     * replaced aborts rollback with SQLSTATE 42704.
     */
    public static function dropIndexIfExists(string $table, string $index): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::quote($index));

            return;
        }

        if (Schema::hasTable($table)) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
            });
        }
    }

    /**
     * WWHN-300 (GoLive rollback control): drop columns together with the
     * compatibility views derived from them.
     *
     * Later migrations project these columns through read views whose own
     * down() is a fail-forward no-op, so an earlier down() that drops the
     * column plainly aborts with SQLSTATE 2BP01. During a rollback the
     * dependent views are being unwound anyway, so CASCADE is the correct
     * semantics rather than a workaround.
     *
     * @param  array<int, string>  $columns
     */
    public static function dropColumnsCascade(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            $present = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

            if ($present !== []) {
                Schema::table($table, function (Blueprint $blueprint) use ($present): void {
                    $blueprint->dropColumn($present);
                });
            }

            return;
        }

        foreach ($columns as $column) {
            DB::statement('ALTER TABLE '.self::quote($table).' DROP COLUMN IF EXISTS '.self::quote($column).' CASCADE');
        }
    }

    /**
     * Is $name reachable as a relation, whether it is a physical table or a
     * compatibility view?
     *
     * Schema::hasTable() answers "table" only, and the legacy/canonical naming
     * is INVERTED between drivers (plural is physical on PostgreSQL, singular
     * is physical on SQLite), so a table-only probe reports half the aggregates
     * as missing on one driver or the other.
     */
    public static function relationExists(string $name): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            return self::relationKind($name) !== null;
        }

        if ($driver === 'sqlite') {
            return DB::selectOne(
                "SELECT 1 AS present FROM sqlite_master WHERE name = ? AND type IN ('table', 'view') LIMIT 1",
                [$name]
            ) !== null;
        }

        return Schema::hasTable($name);
    }

    public static function postgresType(string $documentedType, bool $forAlter = false): string
    {
        $type = strtolower(str_replace(' ', '', $documentedType));
        if ($type === 'bigserial') {
            return $forAlter ? 'bigint' : 'bigserial';
        }

        if (in_array($type, ['uuid', 'text', 'integer', 'bigint', 'boolean', 'date', 'timestamptz', 'jsonb'], true)) {
            return $type;
        }
        if (preg_match('/^(varchar|char|numeric)\(\d+(?:,\d+)?\)$/', $type) === 1) {
            return $type;
        }

        throw new RuntimeException("Unsupported ERD PostgreSQL type: {$documentedType}");
    }

    public static function typeMatches(object $actual, string $documentedType): bool
    {
        $type = strtolower(str_replace(' ', '', $documentedType));

        return match (true) {
            $type === 'uuid' => $actual->data_type === 'uuid',
            $type === 'text' => $actual->data_type === 'text',
            $type === 'integer' => $actual->data_type === 'integer',
            $type === 'bigint', $type === 'bigserial' => $actual->data_type === 'bigint',
            $type === 'boolean' => $actual->data_type === 'boolean',
            $type === 'date' => $actual->data_type === 'date',
            $type === 'timestamptz' => $actual->data_type === 'timestamp with time zone',
            $type === 'jsonb' => $actual->data_type === 'jsonb',
            preg_match('/^varchar\((\d+)\)$/', $type, $m) === 1 => $actual->data_type === 'character varying'
                && (int) $actual->character_maximum_length === (int) $m[1],
            preg_match('/^char\((\d+)\)$/', $type, $m) === 1 => $actual->data_type === 'character'
                && (int) $actual->character_maximum_length === (int) $m[1],
            preg_match('/^numeric\((\d+),(\d+)\)$/', $type, $m) === 1 => $actual->data_type === 'numeric'
                && (int) $actual->numeric_precision === (int) $m[1]
                && (int) $actual->numeric_scale === (int) $m[2],
            default => false,
        };
    }

    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
