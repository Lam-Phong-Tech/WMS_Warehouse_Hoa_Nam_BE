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
            if (! preg_match_all('/\bUX\(([^)]+)\)/i', $definition['section'], $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $index => [$rawColumns]) {
                $offset = $matches[0][$index][1];
                $prefix = strtolower(substr($definition['section'], max(0, $offset - 24), min(24, $offset)));
                if (str_contains($prefix, 'partial')) {
                    continue;
                }

                $fields = $this->parseColumns($rawColumns);
                if ($fields === [] || collect($fields)->contains(fn (string $field): bool => ! Schema::hasColumn($table, $field))) {
                    continue;
                }

                $name = substr('ux_erd_doc_'.$table.'_'.substr(md5(implode('_', $fields)), 0, 10), 0, 63);
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.HoaNamErdSchema::quote($name).' ON '.HoaNamErdSchema::quote($table).' ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $fields)).')');
            }
        }
    }

    public function down(): void
    {
        // Fail-forward documented unique indexes.
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
