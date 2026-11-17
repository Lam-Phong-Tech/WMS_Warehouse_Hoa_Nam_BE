<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FUNCTIONS = [
        'hn_erd_mirror_inbound_documents', 'hn_erd_mirror_inbound_lines',
        'hn_erd_mirror_outbound_documents', 'hn_erd_mirror_outbound_lines',
    ];

    private const GUARD = <<<'SQL'
        -- inbound-supplier-sync: no ERD projection consumes this field.
        IF TG_TABLE_NAME = 'stock_documents' AND TG_OP = 'UPDATE'
            AND current_setting('hoanam.inbound_supplier_sync', true) = 'on' THEN
            IF (to_jsonb(NEW) - 'supplier_id') = (to_jsonb(OLD) - 'supplier_id')
                AND (to_jsonb(NEW)->'supplier_id') IS DISTINCT FROM (to_jsonb(OLD)->'supplier_id') THEN
                RETURN NULL;
            END IF;
        END IF;
    SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::FUNCTIONS as $function) {
            $definition = $this->definition($function);
            if (str_contains($definition, self::GUARD)) {
                continue;
            }
            $patched = preg_replace('/^[\t ]*BEGIN[\t ]*$/mi', '$0'.PHP_EOL.self::GUARD.PHP_EOL, $definition, 1, $count);
            if ($count !== 1 || $patched === null) {
                throw new RuntimeException('Cannot locate mirror function body: '.$function);
            }
            // Preserve the installed mirror implementation, adding only an
            // early exit for this transaction-scoped, unmapped header field.
            DB::unprepared($patched);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::FUNCTIONS as $function) {
            $definition = str_replace(self::GUARD, '', $this->definition($function), $count);
            if ($count === 1) {
                DB::unprepared($definition);
            }
        }
    }

    private function definition(string $function): string
    {
        $definition = DB::selectOne('SELECT pg_get_functiondef(to_regprocedure(?)) AS definition', [$function.'()'])->definition;
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('Missing canonical mirror function: '.$function);
        }

        return $definition;
    }
};
