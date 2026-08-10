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
            $pk = [];
            foreach ($definition['fields'] as $field => $spec) {
                if (preg_match('/\bPK(?:\s+part)?\b/i', $spec['key'])) {
                    $pk[] = $field;
                }
                if (preg_match('/\bUNIQUE\b/i', $spec['key'])) {
                    $this->unique($table, [$field], 'ux_erd_'.$table.'_'.$field);
                }
            }

            if ($pk !== []) {
                $this->canonicalKey($table, $pk);
            }

            if (preg_match_all('/\bPK\(([^)]+)\)/i', $definition['section'], $matches)) {
                foreach ($matches[1] as $raw) {
                    $fields = $this->parseColumns($raw);
                    if ($fields !== []) {
                        $this->canonicalKey($table, $fields);
                    }
                }
            }
            if (preg_match_all('/\bUNIQUE\(([^)]+)\)/i', $definition['section'], $matches)) {
                foreach ($matches[1] as $raw) {
                    $fields = $this->parseColumns($raw);
                    if ($fields !== []) {
                        $this->unique($table, $fields, 'ux_erd_'.$table.'_'.substr(md5(implode('_', $fields)), 0, 8));
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Fail-forward canonical key preparation.
    }

    /** @param array<int,string> $fields */
    private function canonicalKey(string $table, array $fields): void
    {
        if (collect($fields)->contains(fn (string $field): bool => ! Schema::hasColumn($table, $field))) {
            throw new \RuntimeException('ERD key field missing on '.$table.': '.implode(',', $fields));
        }

        $hasPrimary = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conrelid=?::regclass AND contype=\'p\' LIMIT 1', [$table]) !== null;
        if (! $hasPrimary) {
            $name = substr('pk_erd_'.$table, 0, 63);
            DB::statement('ALTER TABLE '.HoaNamErdSchema::quote($table).' ADD CONSTRAINT '.HoaNamErdSchema::quote($name).' PRIMARY KEY ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');

            return;
        }

        $this->unique($table, $fields, 'ux_erd_canonical_key_'.$table);
    }

    /** @param array<int,string> $fields */
    private function unique(string $table, array $fields, string $name): void
    {
        $name = substr($name, 0, 63);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.HoaNamErdSchema::quote($name).' ON '.HoaNamErdSchema::quote($table).' ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');
    }

    /** @return array<int,string> */
    private function parseColumns(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $field): string => strtolower(trim(preg_replace('/\s+.*/', '', $field) ?? $field)))
            ->filter(fn (string $field): bool => preg_match('/^[a-z0-9_]+$/', $field) === 1)
            ->values()->all();
    }
};
