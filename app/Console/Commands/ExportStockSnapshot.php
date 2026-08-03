<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExportStockSnapshot extends Command
{
    protected $signature = 'evidence:stock-snapshot
        {--output=build/evidence/api-data/db-snapshot.json : Đường dẫn JSON output}
        {--label=snapshot : Nhãn before/after}';

    protected $description = 'Xuất snapshot balance, signed ledger, posting event và stocktake ba lớp làm evidence';

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
            ->where(function ($query) {
                $query->where('idempotency_key', 'like', '%-post-%')
                    ->orWhere('operation', 'like', 'stocktake_apply_adjustment_%');
            })
            ->orderBy('created_at')
            ->get();

        $stocktakeSessions = DB::table('stocktake_session')
            ->select('session_id', 'session_no', 'warehouse_id', 'status', 'stocktake_started_at', 'count_cutoff_at', 'reviewed_at', 'movement_warning_flag', 'applied_at', 'session_version')
            ->orderBy('created_at')
            ->get();
        $stocktakeVariances = DB::table('stocktake_variance')
            ->select('variance_id', 'session_id', 'grain_type', 'grain_id', 'grain_key', 'product_id', 'stock_status', 'expected_qty', 'expected_at_count', 'actual_qty', 'variance_qty', 'inventory_effect_qty', 'detected_movement_ids', 'acknowledged_movement_ids', 'status', 'applied_movement_id')
            ->orderBy('session_id')
            ->orderBy('grain_key')
            ->get();
        $stocktakeMovements = DB::table('stock_movements')
            ->whereNotNull('stocktake_variance_id')
            ->select('id', 'business_key', 'movement_type', 'warehouse_id', 'product_id', 'stock_status', 'qty_before', 'qty_delta', 'qty_after', 'stocktake_variance_id')
            ->orderBy('created_at')
            ->get();
        $physicalItems = DB::table('inventory_serials')
            ->select('id', 'product_id', 'warehouse_id', 'serial_no', 'stock_condition', 'status', 'updated_at')
            ->orderBy('id')
            ->get();
        $physicalContainers = DB::table('container_item as ci')
            ->join('container as c', 'c.container_id', '=', 'ci.container_id')
            ->select('ci.container_item_id', 'ci.container_id', 'c.warehouse_id', 'ci.product_id', 'ci.quantity', 'ci.status', 'ci.updated_at')
            ->orderBy('ci.container_item_id')
            ->get();

        $payload = [
            'schema_version' => '2.0.0',
            'label' => (string) $this->option('label'),
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'signed_convention' => [
                'formula' => 'SUM(stock_movements.qty_delta)',
                'positive' => ['OPENING', 'RECEIPT_POSTED', 'ADJUST_IN', 'TRANSFER_IN', 'TRANSFER_RETURN', 'ISSUE_REVERSED'],
                'negative' => ['ISSUE_POSTED', 'ADJUST_OUT', 'TRANSFER_OUT', 'RECEIPT_REVERSED'],
            ],
            'counts' => [
                'inventory_balances' => DB::table('inventory_balances')->count(),
                'stock_movements' => DB::table('stock_movements')->count(),
                'posting_events' => DB::table('posting_event')->count(),
                'ci_documents' => $ciDocuments->count(),
                'stocktake_sessions' => $stocktakeSessions->count(),
                'stocktake_variances' => $stocktakeVariances->count(),
                'stocktake_movements' => $stocktakeMovements->count(),
            ],
            'balances' => $balances,
            'signed_ledger_by_dimension' => $ledger,
            'ci_documents' => $ciDocuments,
            'posting_events' => $postingEvents,
            'stocktake_sessions' => $stocktakeSessions,
            'stocktake_variances' => $stocktakeVariances,
            'stocktake_movements' => $stocktakeMovements,
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
}
