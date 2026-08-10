<?php

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

        foreach ([
            ['inventory_balances', 'quantity'],
            ['inventory_balances', 'on_hand_qty'],
            ['inventory_balances', 'available_qty'],
            ['inventory_balances', 'reserved_qty'],
            ['stock_movements', 'quantity'],
            ['stock_movements', 'qty_before'],
            ['stock_movements', 'qty_delta'],
            ['stock_movements', 'qty_after'],
            ['stock_document_lines', 'qty_planned'],
            ['stock_document_lines', 'qty_actual'],
            ['stock_document_lines', 'scanned_qty'],
            ['container_items', 'quantity'],
            ['inbound_scan_entries', 'quantity'],
            ['outbound_item_matches', 'quantity'],
            ['component_issue_lines', 'required_qty'],
            ['component_issue_lines', 'scanned_qty'],
            ['component_issue_items', 'quantity'],
            ['reversal_line', 'reversal_qty'],
            ['reversal_line', 'quantity_to_reverse'],
        ] as [$table, $column]) {
            $this->castIntegerQuantity($table, $column);
        }
    }

    public function down(): void
    {
        // Integer quantity is a business invariant and is intentionally fail-forward.
    }

    private function castIntegerQuantity(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $type = DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('data_type');
        if ($type === 'bigint') {
            return;
        }

        if (DB::table($table)->whereNotNull($column)->whereRaw("{$column} <> trunc({$column})")->exists()) {
            throw new RuntimeException("Current ERD requires BIGINT {$table}.{$column}; fractional legacy values must be reconciled first.");
        }

        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE BIGINT USING {$column}::bigint");
    }
};
