<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (HoaNamErdSchema::parse() as $table => $definition) {
            $documented = [];
            foreach ($definition['fields'] as $field => $spec) {
                if (preg_match('/\bPK(?:\s+part)?\b/i', $spec['key'])) {
                    $documented[] = $field;
                }
            }
            if ($documented === [] && preg_match('/\bPK\(([^)]+)\)/i', $definition['section'], $match)) {
                $documented = $this->parseColumns($match[1]);
            }
            if ($documented === []) {
                continue;
            }

            $primary = $this->primaryKey($table);
            if (($primary['columns'] ?? []) === $documented) {
                continue;
            }

            foreach ($documented as $field) {
                if (! Schema::hasColumn($table, $field) || DB::table($table)->whereNull($field)->exists()) {
                    throw new \RuntimeException("Cannot promote ERD primary key {$table}.{$field}: field missing or contains NULL.");
                }
            }

            if ($primary !== null) {
                $legacyColumns = $primary['columns'];
                $this->preserveLegacyKey($table, $legacyColumns);
                $foreignKeys = $this->referencingForeignKeys($table);
                foreach ($foreignKeys as $foreignKey) {
                    DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($foreignKey->child_table).' DROP CONSTRAINT '.HoaNamErdSchema::quote($foreignKey->constraint_name));
                }

                DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($table).' DROP CONSTRAINT '.HoaNamErdSchema::quote($primary['name']));
                $this->addPrimaryKey($table, $documented);

                foreach ($foreignKeys as $foreignKey) {
                    DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($foreignKey->child_table).' ADD CONSTRAINT '.HoaNamErdSchema::quote($foreignKey->constraint_name).' '.$foreignKey->definition);
                }
            } else {
                $this->addPrimaryKey($table, $documented);
            }
        }
    }

    public function down(): void
    {
        // Fail-forward. Reverting canonical primary keys would reintroduce the legacy
        // physical contract that HN-WMS-ERD-DD-001 supersedes.
    }

    /** @return array{name:string,columns:array<int,string>}|null */
    private function primaryKey(string $table): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT c.conname,
                   array_agg(a.attname ORDER BY x.ordinality) AS columns
            FROM pg_constraint c
            JOIN unnest(c.conkey) WITH ORDINALITY AS x(attnum, ordinality) ON true
            JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=x.attnum
            WHERE c.conrelid=?::regclass AND c.contype='p'
            GROUP BY c.conname
            LIMIT 1
        SQL, [$table]);

        if ($row === null) {
            return null;
        }

        return [
            'name' => (string) $row->conname,
            'columns' => $this->pgArray($row->columns),
        ];
    }

    /** @param array<int,string> $fields */
    private function preserveLegacyKey(string $table, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $name = substr('ux_legacy_runtime_key_'.$table, 0, 63);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.HoaNamErdSchema::quote($name).' ON '.HoaNamErdSchema::quote($table).' ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');
    }

    /** @return array<int,object> */
    private function referencingForeignKeys(string $table): array
    {
        return DB::select(<<<'SQL'
            SELECT child.relname AS child_table,
                   c.conname AS constraint_name,
                   pg_get_constraintdef(c.oid, true) AS definition
            FROM pg_constraint c
            JOIN pg_class child ON child.oid=c.conrelid
            JOIN pg_namespace n ON n.oid=child.relnamespace
            WHERE c.contype='f'
              AND c.confrelid=?::regclass
              AND n.nspname=current_schema()
            ORDER BY child.relname, c.conname
        SQL, [$table]);
    }

    /** @param array<int,string> $fields */
    private function addPrimaryKey(string $table, array $fields): void
    {
        $name = substr('pk_erd_'.$table, 0, 63);
        DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($table).' ADD CONSTRAINT '.HoaNamErdSchema::quote($name).' PRIMARY KEY ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');
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
};
