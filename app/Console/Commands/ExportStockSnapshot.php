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

    protected $description = 'Xuất snapshot balance, signed ledger, posting event và chứng từ CI làm evidence';

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

        $ciPostingEvents = DB::table('posting_event')
            ->select('posting_event_id', 'operation', 'idempotency_key', 'request_hash', 'status', 'document_id', 'movement_id', 'created_at')
            ->where('idempotency_key', 'like', '%-post-%')
            ->orderBy('created_at')
            ->get();

        $payload = [
            'schema_version' => '1.0.0',
            'label' => (string) $this->option('label'),
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'counts' => [
                'inventory_balances' => DB::table('inventory_balances')->count(),
                'stock_movements' => DB::table('stock_movements')->count(),
                'posting_events' => DB::table('posting_event')->count(),
                'ci_documents' => $ciDocuments->count(),
            ],
            'balances' => $balances,
            'signed_ledger_by_dimension' => $ledger,
            'ci_documents' => $ciDocuments,
            'ci_posting_events' => $ciPostingEvents,
        ];

        file_put_contents(
            $output,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        $this->info("Đã xuất stock snapshot: {$output}");

        return self::SUCCESS;
    }
}
