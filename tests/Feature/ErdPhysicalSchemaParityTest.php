<?php

namespace Tests\Feature;

use App\Support\HoaNamErdSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErdPhysicalSchemaParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_has_exactly_59_documented_canonical_base_tables(): void
    {
        $this->requirePostgres();
        $schema = HoaNamErdSchema::parse();

        $this->assertCount(HoaNamErdSchema::TABLE_COUNT, $schema);
        foreach (array_keys($schema) as $table) {
            $this->assertSame('r', HoaNamErdSchema::relationKind($table), "{$table} must be a physical PostgreSQL base table, not a compatibility view.");
        }
    }

    public function test_every_documented_field_matches_type_nullability_and_default(): void
    {
        $this->requirePostgres();

        foreach (HoaNamErdSchema::parse() as $table => $definition) {
            foreach ($definition['fields'] as $field => $spec) {
                $actual = DB::table('information_schema.columns')
                    ->where('table_schema', DB::raw('current_schema()'))
                    ->where('table_name', $table)
                    ->where('column_name', $field)
                    ->first([
                        'data_type', 'character_maximum_length', 'numeric_precision', 'numeric_scale',
                        'is_nullable', 'column_default',
                    ]);

                $this->assertNotNull($actual, "Missing ERD field {$table}.{$field}.");
                $this->assertTrue(
                    HoaNamErdSchema::typeMatches($actual, $spec['type']),
                    "{$table}.{$field} type does not match documented {$spec['type']}.",
                );
                $this->assertSame(
                    $spec['nullable'] ? 'YES' : 'NO',
                    $actual->is_nullable,
                    "{$table}.{$field} nullability does not match the Data Dictionary.",
                );

                if (preg_match('/\bDEFAULT\s+([^\s,]+)/i', $spec['key'], $match)) {
                    $this->assertDocumentedDefault($table, $field, $match[1], $actual->column_default);
                }
            }
        }
    }

    public function test_documented_primary_unique_and_foreign_keys_are_enforced(): void
    {
        $this->requirePostgres();

        foreach (HoaNamErdSchema::parse() as $table => $definition) {
            $pkFields = [];
            foreach ($definition['fields'] as $field => $spec) {
                if (preg_match('/\bPK(?:\s+part)?\b/i', $spec['key'])) {
                    $pkFields[] = $field;
                }
                if (preg_match('/\bUNIQUE\b/i', $spec['key'])) {
                    $this->assertTrue($this->hasUniqueKey($table, [$field]), "Missing documented UNIQUE {$table}({$field}).");
                }
                if (preg_match('/\bFK\s+([a-z0-9_]+)\.([a-z0-9_]+)/i', $spec['key'], $fk)) {
                    $this->assertTrue(
                        $this->hasForeignKey($table, $field, strtolower($fk[1]), strtolower($fk[2])),
                        "Missing documented FK {$table}.{$field} -> {$fk[1]}.{$fk[2]}.",
                    );
                }
            }

            if ($pkFields !== []) {
                $this->assertTrue(
                    $this->hasPrimaryKey($table, $pkFields),
                    'Physical PK must exactly match '.$table.'('.implode(',', $pkFields).').',
                );
            }

            if (preg_match_all('/\bPK\(([^)]+)\)/i', $definition['section'], $matches)) {
                foreach ($matches[1] as $raw) {
                    $fields = $this->parseColumns($raw);
                    if ($fields !== []) {
                        $this->assertTrue($this->hasPrimaryKey($table, $fields), 'Physical PK must exactly match '.$table.'('.implode(',', $fields).').');
                    }
                }
            }
            if (preg_match_all('/\bUNIQUE\(([^)]+)\)/i', $definition['section'], $matches)) {
                foreach ($matches[1] as $raw) {
                    $fields = $this->parseColumns($raw);
                    if ($fields !== []) {
                        $this->assertTrue($this->hasUniqueKey($table, $fields), 'Missing documented UNIQUE '.$table.'('.implode(',', $fields).').');
                    }
                }
            }
        }
    }

    public function test_documented_recommended_indexes_are_materialized(): void
    {
        $this->requirePostgres();

        foreach (HoaNamErdSchema::parse() as $table => $definition) {
            if (! preg_match_all('/\bIDX\(([^)]+)\)/i', $definition['section'], $matches)) {
                continue;
            }
            foreach ($matches[1] as $raw) {
                $fields = $this->parseColumns($raw);
                if ($fields === []) {
                    continue;
                }
                $this->assertTrue($this->hasIndex($table, $fields), 'Missing documented IDX '.$table.'('.implode(',', $fields).').');
            }
        }
    }

    private function assertDocumentedDefault(string $table, string $field, string $raw, mixed $actual): void
    {
        $this->assertNotNull($actual, "{$table}.{$field} must have documented default {$raw}.");
        $actual = strtolower((string) $actual);
        $expected = strtolower(trim($raw, "'\""));

        $matches = match (strtoupper($raw)) {
            'GEN_RANDOM_UUID()' => str_contains($actual, 'gen_random_uuid'),
            'NOW()', 'CURRENT_TIMESTAMP' => str_contains($actual, 'now') || str_contains($actual, 'current_timestamp'),
            'TRUE', 'FALSE' => str_contains($actual, strtolower($raw)),
            default => str_contains($actual, $expected),
        };

        $this->assertTrue($matches, "{$table}.{$field} default [{$actual}] does not match documented [{$raw}].");
    }

    private function hasForeignKey(string $table, string $field, string $parent, string $parentField): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(function (array $fk) use ($field, $parent, $parentField): bool {
            $columns = $fk['columns'] ?? [];
            $foreignColumns = $fk['foreign_columns'] ?? $fk['foreignColumns'] ?? [];
            $foreignTable = $fk['foreign_table'] ?? $fk['foreignTable'] ?? null;

            return $columns === [$field] && $foreignTable === $parent && $foreignColumns === [$parentField];
        });
    }

    /** @param array<int,string> $fields */
    private function hasPrimaryKey(string $table, array $fields): bool
    {
        return collect(DB::select(<<<'SQL'
            SELECT array_agg(a.attname ORDER BY x.ordinality) AS columns
            FROM pg_constraint c
            JOIN unnest(c.conkey) WITH ORDINALITY AS x(attnum, ordinality) ON true
            JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=x.attnum
            WHERE c.conrelid=?::regclass AND c.contype='p'
            GROUP BY c.oid
        SQL, [$table]))->contains(fn (object $row): bool => $this->pgArray($row->columns) === $fields);
    }

    /** @param array<int,string> $fields */
    private function hasUniqueKey(string $table, array $fields): bool
    {
        return collect(Schema::getIndexes($table))->contains(function (array $index) use ($fields): bool {
            return (bool) ($index['unique'] ?? false) && ($index['columns'] ?? []) === $fields;
        });
    }

    /** @param array<int,string> $fields */
    private function hasIndex(string $table, array $fields): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => ($index['columns'] ?? []) === $fields);
    }

    /** @return array<int,string> */
    private function parseColumns(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $field): string => strtolower(trim(preg_replace('/\s+.*/', '', $field) ?? $field)))
            ->filter(fn (string $field): bool => preg_match('/^[a-z0-9_]+$/', $field) === 1)
            ->values()->all();
    }

    /** @return array<int,string> */
    private function pgArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return collect(explode(',', trim((string) $value, '{}')))
            ->map(fn (string $item): string => trim($item, '"'))
            ->filter()->values()->all();
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('HN-WMS-ERD-DD-001 physical parity is certified on the PostgreSQL 14+ reference DB.');
        }
    }
}
