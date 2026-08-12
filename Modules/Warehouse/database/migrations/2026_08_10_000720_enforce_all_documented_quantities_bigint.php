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

        foreach (['inventory_movements', 'inbound_lines', 'outbound_lines', 'reversal_lines'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
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

        $this->rebuildCanonicalViews();
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

    private function rebuildCanonicalViews(): void
    {
        if (Schema::hasTable('stock_movements')) {
            DB::statement(<<<'SQL'
                CREATE VIEW inventory_movements AS
                SELECT id,
                       movement_no,
                       business_key AS movement_key,
                       movement_type,
                       warehouse_id,
                       COALESCE(sku_id, product_id) AS sku_id,
                       stock_status,
                       quantity,
                       item_id,
                       container_id,
                       COALESCE(source_type, 'STOCK_DOCUMENT') AS source_type,
                       COALESCE(source_id, stock_document_id) AS source_id,
                       COALESCE(source_line_id, stock_document_line_id) AS source_line_id,
                       original_movement_id,
                       correction_chain_id,
                       idempotency_record_id,
                       created_by,
                       occurred_at,
                       created_at
                FROM stock_movements
            SQL);
        }

        if (Schema::hasTable('stock_document_lines') && Schema::hasTable('stock_documents')) {
            DB::statement(<<<'SQL'
                CREATE VIEW inbound_lines AS
                SELECT l.id,
                       l.stock_document_id AS inbound_document_id,
                       l.line_no,
                       l.product_id AS sku_id,
                       l.import_batch_id,
                       l.qty_planned AS expected_qty,
                       l.scanned_qty,
                       l.note,
                       l.created_at,
                       d.created_by,
                       l.updated_at,
                       d.updated_by,
                       l.version
                FROM stock_document_lines l
                JOIN stock_documents d ON d.id = l.stock_document_id
                WHERE d.doc_type = 'receipt'
            SQL);

            DB::statement(<<<'SQL'
                CREATE VIEW outbound_lines AS
                SELECT l.id,
                       l.stock_document_id AS outbound_document_id,
                       l.outbound_request_line_id,
                       l.line_no,
                       l.product_id AS sku_id,
                       l.qty_planned AS required_qty,
                       l.scanned_qty,
                       l.note,
                       l.created_at,
                       d.created_by,
                       l.updated_at,
                       d.updated_by,
                       l.version
                FROM stock_document_lines l
                JOIN stock_documents d ON d.id = l.stock_document_id
                WHERE d.doc_type = 'issue'
            SQL);
        }

        if (Schema::hasTable('reversal_line')) {
            DB::statement(<<<'SQL'
                CREATE VIEW reversal_lines AS
                SELECT reversal_line_id AS id,
                       reversal_document_id,
                       line_no,
                       original_movement_id,
                       COALESCE(quantity_to_reverse, reversal_qty) AS quantity,
                       sku_id,
                       item_id,
                       stock_status,
                       return_condition,
                       downstream_check_result,
                       posted_movement_id,
                       reason,
                       created_at,
                       created_by,
                       updated_at,
                       updated_by,
                       version
                FROM reversal_line
            SQL);
        }
    }
};
