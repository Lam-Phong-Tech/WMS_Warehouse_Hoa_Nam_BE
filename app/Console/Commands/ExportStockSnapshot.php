<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ExportStockSnapshot extends Command
{
    protected $signature = 'evidence:stock-snapshot
        {--output=build/evidence/api-data/db-snapshot.json : Đường dẫn JSON output}
        {--label=snapshot : Nhãn before/after}';

    protected $description = 'Xuất snapshot balance, signed ledger, posting/idempotency/scan event và reversal chain làm evidence';

    public function handle(): int
    {
        $output = (string) $this->option('output');
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không thể tạo thư mục evidence: {$directory}");
        }

        $ledger = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('CAST(SUM(qty_delta) AS TEXT) AS signed_ledger_qty')
            ->selectRaw('COUNT(*) AS movement_count')
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('stock_status')
            ->get();

        $balances = DB::table('inventory_balances')
            ->select('id', 'warehouse_id', 'product_id', 'stock_status', 'on_hand_qty', 'available_qty', 'reserved_qty', 'version', 'updated_at')
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('stock_status')
            ->get();

        $ciDocuments = DB::table('stock_documents')
            ->select('id', 'doc_no', 'doc_type', 'status', 'src_warehouse_id', 'dst_warehouse_id', 'posted_at', 'updated_at')
            ->where(function ($query) {
                $query->where('doc_no', 'like', 'PN-CI-%')
                    ->orWhere('doc_no', 'like', 'PX-CI-%');
            })
            ->orderBy('doc_no')
            ->get();

        $postingEvents = DB::table('posting_event')
            ->select('posting_event_id', 'operation', 'idempotency_key', 'request_hash', 'status', 'document_id', 'movement_id', 'response_snapshot', 'created_at')
            ->orderBy('operation')
            ->orderBy('idempotency_key')
            ->get();

        $idempotencyKeys = $this->tableRows(
            'idempotency_keys',
            [
                'business_key', 'idem_key', 'user_id', 'operation', 'request_hash', 'status',
                'response_code', 'response_body', 'locked_until', 'completed_at', 'created_at', 'updated_at',
            ],
            ['operation', 'user_id', 'idem_key']
        );
        $scanEvents = $this->tableRows(
            'scan_event',
            [
                'scan_event_id', 'scan_batch_id', 'raw_code', 'physical_code_id', 'resolved_object_type',
                'resolved_object_id', 'result_status', 'message', 'created_at',
            ],
            ['scan_event_id']
        );

        // DEC-REV-001/WWHN-26: sai giao dịch chỉ sửa bằng Reversal append-only, nên
        // evidence của lớp "correction" là reversal chain — không có snapshot/variance.
        $reversalDocuments = $this->tableRows(
            'reversal_documents',
            [
                'id', 'reversal_no', 'warehouse_id', 'original_document_type', 'original_document_id',
                'correction_chain_id', 'reason_code', 'status', 'posted_at', 'version',
            ],
            ['reversal_no']
        );
        $reversalLines = $this->tableRows(
            'reversal_lines',
            [
                'id', 'reversal_document_id', 'line_no', 'original_movement_id', 'sku_id', 'item_id',
                'quantity_to_reverse', 'return_condition', 'posted_movement_id',
            ],
            ['reversal_document_id', 'line_no']
        );
        $reversalMovements = DB::table('stock_movements')
            ->whereNotNull('original_movement_id')
            ->select('id', 'business_key', 'movement_type', 'warehouse_id', 'product_id', 'stock_status', 'qty_before', 'qty_delta', 'qty_after', 'original_movement_id', 'correction_chain_id')
            ->orderBy('created_at')
            ->get();
        $physicalItems = $this->tableRows(
            'item_instances',
            ['item_id', 'sku_id', 'item_code', 'serial_number', 'item_status', 'item_condition', 'container_id', 'updated_at'],
            ['item_id']
        );
        $physicalContainers = $this->tableRows(
            'container_items',
            ['container_item_id', 'container_id', 'sku_id', 'item_id', 'quantity', 'status', 'is_active', 'updated_at'],
            ['container_item_id']
        );

        $payload = [
            'schema_version' => '2.1.0',
            'label' => (string) $this->option('label'),
            'generated_at_utc' => now()->utc()->toIso8601String(),
            // ERD bảng 30: movement_type CHECK OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL.
            'signed_convention' => [
                'formula' => 'SUM(stock_movements.qty_delta)',
                'positive' => ['OPENING', 'RECEIPT', 'ISSUE_REVERSED'],
                'negative' => ['ISSUE', 'COMPONENT_ISSUE', 'RECEIPT_REVERSED'],
            ],
            'counts' => [
                'inventory_balances' => DB::table('inventory_balances')->count(),
                'stock_movements' => DB::table('stock_movements')->count(),
                'posting_events' => DB::table('posting_event')->count(),
                'idempotency_keys' => $idempotencyKeys->count(),
                'scan_events' => $scanEvents->count(),
                'ci_documents' => $ciDocuments->count(),
                'reversal_documents' => $reversalDocuments->count(),
                'reversal_lines' => $reversalLines->count(),
                'reversal_movements' => $reversalMovements->count(),
            ],
            'balances' => $balances,
            'signed_ledger_by_dimension' => $ledger,
            'ci_documents' => $ciDocuments,
            'posting_events' => $postingEvents,
            'idempotency_keys' => $idempotencyKeys,
            'scan_events' => $scanEvents,
            'reversal_documents' => $reversalDocuments,
            'reversal_lines' => $reversalLines,
            'reversal_movements' => $reversalMovements,
            'physical_items' => $physicalItems,
            'physical_container_items' => $physicalContainers,
        ];

        file_put_contents(
            $output,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        $this->info("Đã xuất stock snapshot: {$output}");

        return self::SUCCESS;
    }

    private function tableRows(string $table, array $columns, array $orderBy)
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $availableColumns = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));
        $query = DB::table($table);
        if ($availableColumns !== []) {
            $query->select($availableColumns);
        }
        foreach ($orderBy as $column) {
            if (Schema::hasColumn($table, $column)) {
                $query->orderBy($column);
            }
        }

        return $query->get();
    }
}
