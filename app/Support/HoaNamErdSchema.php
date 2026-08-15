<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HoaNamErdSchema
{
    public const DOCUMENT_PATH = 'docs/HoaNam_ERD_Moi_Nhat.md';

    public const DOCUMENT_ID = 'HN-WMS-ERD-DD-001';

    public const TABLE_COUNT = 59;

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
