<?php

namespace Modules\Warehouse\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BP-WMS-12: builders cho các báo cáo vận hành.
 * Mỗi method trả array sẵn sàng respondSuccess() hoặc export Excel.
 */
class ReportService
{
    /**
     * 1. Tồn kho hiện tại — Balance projection + reconciliation warning.
     * Filter: warehouse_id, product_id, stock_status, keyword.
     */
    public function buildInventoryBalanceReport(Request $request): array
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'string', 'max:30'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);

        $query = DB::table('inventory_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->select([
                'b.id', 'b.warehouse_id', 'w.code as warehouse_code',
                'b.product_id', 'p.code as product_code', 'p.name as product_name',
                'b.stock_status', 'b.on_hand_qty', 'b.available_qty', 'b.reserved_qty',
                'b.updated_at',
            ]);

        if (! empty($validated['warehouse_id'])) {
            $query->where('b.warehouse_id', $validated['warehouse_id']);
        }
        if (! empty($validated['product_id'])) {
            $query->where('b.product_id', $validated['product_id']);
        }
        if (! empty($validated['stock_status'])) {
            $query->where('b.stock_status', $validated['stock_status']);
        }
        if (! empty($validated['keyword'])) {
            $kw = '%' . mb_strtolower(trim($validated['keyword'])) . '%';
            $query->where(function ($q) use ($kw) {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$kw])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$kw]);
            });
        }

        $rows = $query->orderBy('w.code')->orderBy('p.code')->orderBy('b.stock_status')->get();

        $ledgerSums = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_qty')
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get()
            ->keyBy(fn ($r) => $r->warehouse_id . '|' . $r->product_id . '|' . $r->stock_status);

        $result = $rows->map(function ($row) use ($ledgerSums) {
            $key = $row->warehouse_id . '|' . $row->product_id . '|' . $row->stock_status;
            $ledger = $ledgerSums->get($key);
            $ledgerQty = $ledger ? (float) $ledger->ledger_qty : 0.0;
            $onHand = (float) $row->on_hand_qty;
            $variance = round($onHand - $ledgerQty, 3);

            return [
                'id' => $row->id,
                'warehouse_id' => $row->warehouse_id,
                'warehouse_code' => $row->warehouse_code,
                'product_id' => $row->product_id,
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'stock_status' => $row->stock_status,
                'on_hand_qty' => $onHand,
                'available_qty' => (float) $row->available_qty,
                'reserved_qty' => (float) ($row->reserved_qty ?? 0),
                'ledger_qty' => $ledgerQty,
                'variance' => $variance,
                'reconciliation_status' => $variance == 0 ? 'MATCH' : 'MISMATCH',
                'updated_at' => $row->updated_at,
            ];
        })->values();

        $mismatches = $result->where('reconciliation_status', 'MISMATCH')->count();

        return [
            'data_as_of' => now()->toIso8601String(),
            'summary' => [
                'total_rows' => $result->count(),
                'total_on_hand' => $result->sum('on_hand_qty'),
                'total_available' => $result->sum('available_qty'),
                'mismatch_count' => $mismatches,
            ],
            'rows' => $result->all(),
        ];
    }

    /**
     * 2. Nhập-Xuất-Tồn nâng cấp — Opening + Receipt - Issue - Component Issue + Reversal = Closing.
     * Filter: from_date, to_date, warehouse_id, product_id.
     */
    public function buildEnhancedXntReport(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
        ]);

        $from = $validated['from_date'];
        $to = $validated['to_date'];
        $fromTs = "{$from} 00:00:00";
        $toTs = "{$to} 23:59:59";

        $dimensionQuery = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->groupBy('warehouse_id', 'product_id', 'stock_status');

        if (! empty($validated['warehouse_id'])) {
            $dimensionQuery->where('warehouse_id', $validated['warehouse_id']);
        }
        if (! empty($validated['product_id'])) {
            $dimensionQuery->where('product_id', $validated['product_id']);
        }

        $dimensions = $dimensionQuery->get();

        $openingData = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS opening_qty')
            ->where('occurred_at', '<', $fromTs)
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get()
            ->keyBy(fn ($r) => $r->warehouse_id . '|' . $r->product_id . '|' . $r->stock_status);

        $periodData = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw("SUM(CASE WHEN movement_type = 'RECEIPT' THEN qty_delta ELSE 0 END) AS receipt_qty")
            ->selectRaw("SUM(CASE WHEN movement_type = 'ISSUE' THEN -qty_delta ELSE 0 END) AS issue_qty")
            ->selectRaw("SUM(CASE WHEN movement_type = 'COMPONENT_ISSUE' THEN -qty_delta ELSE 0 END) AS component_issue_qty")
            ->selectRaw("SUM(CASE WHEN movement_type = 'REVERSAL' THEN qty_delta ELSE 0 END) AS reversal_net")
            ->whereBetween('occurred_at', [$fromTs, $toTs])
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get()
            ->keyBy(fn ($r) => $r->warehouse_id . '|' . $r->product_id . '|' . $r->stock_status);

        $productNames = DB::table('products')->pluck('name', 'id');
        $productCodes = DB::table('products')->pluck('code', 'id');
        $warehouseCodes = DB::table('warehouses')->pluck('code', 'id');

        $lines = $dimensions->map(function ($dim) use ($openingData, $periodData, $productNames, $productCodes, $warehouseCodes) {
            $key = $dim->warehouse_id . '|' . $dim->product_id . '|' . $dim->stock_status;

            $opening = (float) ($openingData->get($key)?->opening_qty ?? 0);
            $period = $periodData->get($key);

            $receipt = (float) ($period?->receipt_qty ?? 0);
            $issue = (float) ($period?->issue_qty ?? 0);
            $componentIssue = (float) ($period?->component_issue_qty ?? 0);
            $reversalNet = (float) ($period?->reversal_net ?? 0);
            $closing = $opening + $receipt - $issue - $componentIssue + $reversalNet;

            return [
                'warehouse_id' => $dim->warehouse_id,
                'warehouse_code' => $warehouseCodes->get($dim->warehouse_id, ''),
                'product_id' => $dim->product_id,
                'product_code' => $productCodes->get($dim->product_id, ''),
                'product_name' => $productNames->get($dim->product_id, ''),
                'stock_status' => $dim->stock_status,
                'opening' => $opening,
                'receipt' => $receipt,
                'issue' => $issue,
                'component_issue' => $componentIssue,
                'reversal_net' => $reversalNet,
                'closing' => $closing,
            ];
        })->sortBy('product_code')->values();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'formula' => 'Opening + Receipt - Issue - Component Issue + Reversal = Closing',
            'lines' => $lines->all(),
        ];
    }

    /**
     * 3. Lịch sử phiếu nhập/xuất — DRAFT/SCANNING xem vận hành; chỉ POSTED ảnh hưởng tồn.
     * Filter: from_date, to_date, doc_type (inbound/outbound/all), status, keyword.
     */
    public function buildDocumentHistoryReport(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'doc_type' => ['nullable', 'in:inbound,outbound,all'],
            'status' => ['nullable', 'string', 'max:20'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);

        $from = $validated['from_date'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to_date'] ?? now()->toDateString();
        $docType = $validated['doc_type'] ?? 'all';

        $rows = collect();

        if ($docType === 'all' || $docType === 'inbound') {
            $inboundQuery = DB::table('inbound_documents as d')
                ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
                ->leftJoin('app_users as cu', 'cu.id', '=', 'd.created_by')
                ->leftJoin('app_users as pu', 'pu.id', '=', 'd.posted_by')
                ->select([
                    'd.id', 'd.inbound_no as document_no',
                    DB::raw("'INBOUND' as doc_type"),
                    'd.warehouse_id', 'w.code as warehouse_code',
                    'd.source_name as counterpart',
                    'd.document_date', 'd.status',
                    'd.posted_at', 'd.cancelled_at',
                    'cu.display_name as created_by_name',
                    'pu.display_name as posted_by_name',
                    'd.note', 'd.created_at', 'd.updated_at',
                ])
                ->whereBetween('d.document_date', [$from, $to]);

            if (! empty($validated['status'])) {
                $inboundQuery->where('d.status', $validated['status']);
            }
            if (! empty($validated['keyword'])) {
                $kw = '%' . mb_strtolower(trim($validated['keyword'])) . '%';
                $inboundQuery->where(function ($q) use ($kw) {
                    $q->whereRaw('LOWER(d.inbound_no) LIKE ?', [$kw])
                        ->orWhereRaw('LOWER(d.source_name) LIKE ?', [$kw]);
                });
            }

            $rows = $rows->merge($inboundQuery->get());
        }

        if ($docType === 'all' || $docType === 'outbound') {
            $outboundQuery = DB::table('outbound_documents as d')
                ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
                ->leftJoin('agencies as a', 'a.id', '=', 'd.agency_id')
                ->leftJoin('app_users as cu', 'cu.id', '=', 'd.created_by')
                ->leftJoin('app_users as pu', 'pu.id', '=', 'd.posted_by')
                ->select([
                    'd.id', 'd.outbound_no as document_no',
                    DB::raw("'OUTBOUND' as doc_type"),
                    'd.warehouse_id', 'w.code as warehouse_code',
                    DB::raw("COALESCE(a.agency_name, d.recipient_name) as counterpart"),
                    'd.document_date', 'd.status',
                    'd.posted_at', 'd.cancelled_at',
                    'cu.display_name as created_by_name',
                    'pu.display_name as posted_by_name',
                    'd.note', 'd.created_at', 'd.updated_at',
                ])
                ->whereBetween('d.document_date', [$from, $to]);

            if (! empty($validated['status'])) {
                $outboundQuery->where('d.status', $validated['status']);
            }
            if (! empty($validated['keyword'])) {
                $kw = '%' . mb_strtolower(trim($validated['keyword'])) . '%';
                $outboundQuery->where(function ($q) use ($kw) {
                    $q->whereRaw('LOWER(d.outbound_no) LIKE ?', [$kw])
                        ->orWhereRaw('LOWER(d.recipient_name) LIKE ?', [$kw])
                        ->orWhereRaw('LOWER(a.agency_name) LIKE ?', [$kw]);
                });
            }

            $rows = $rows->merge($outboundQuery->get());
        }

        $sorted = $rows->sortByDesc('document_date')->values();

        $summary = [
            'total' => $sorted->count(),
            'draft' => $sorted->where('status', 'DRAFT')->count(),
            'scanning' => $sorted->where('status', 'SCANNING')->count(),
            'posted' => $sorted->where('status', 'POSTED')->count(),
            'cancelled' => $sorted->where('status', 'CANCELLED')->count(),
        ];

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'doc_type_filter' => $docType,
            'summary' => $summary,
            'rows' => $sorted->all(),
        ];
    }

    /**
     * 4. Excel Import/Match — Pass rate, error rows, matched qty.
     * Filter: from_date, to_date, status.
     */
    public function buildImportMatchReport(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $from = $validated['from_date'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to_date'] ?? now()->toDateString();

        $query = DB::table('outbound_request_imports as i')
            ->leftJoin('app_users as u', 'u.id', '=', 'i.uploaded_by')
            ->leftJoin('app_users as cu', 'cu.id', '=', 'i.consumed_by')
            ->select([
                'i.id', 'i.import_no', 'i.source_file_name',
                'i.status', 'i.total_rows', 'i.valid_rows', 'i.error_rows',
                'i.uploaded_at', 'u.display_name as uploaded_by_name',
                'i.validated_at', 'i.consumed_at',
                'cu.display_name as consumed_by_name',
                'i.created_at',
            ])
            ->whereBetween('i.uploaded_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

        if (! empty($validated['status'])) {
            $query->where('i.status', $validated['status']);
        }

        $imports = $query->orderByDesc('i.uploaded_at')->get();

        $importIds = $imports->pluck('id')->all();
        $matchedQtys = [];
        if (! empty($importIds)) {
            $matchedQtys = DB::table('outbound_request_import_rows')
                ->whereIn('import_id', $importIds)
                ->where('validation_status', 'VALID')
                ->groupBy('import_id')
                ->selectRaw('import_id, SUM(required_qty) AS matched_qty')
                ->pluck('matched_qty', 'import_id')
                ->all();
        }

        $rows = $imports->map(function ($imp) use ($matchedQtys) {
            $total = (int) $imp->total_rows;
            $valid = (int) $imp->valid_rows;

            return [
                'id' => $imp->id,
                'import_no' => $imp->import_no,
                'source_file_name' => $imp->source_file_name,
                'status' => $imp->status,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'error_rows' => (int) $imp->error_rows,
                'pass_rate' => $total > 0 ? round($valid / $total * 100, 1) : 0,
                'matched_qty' => (int) ($matchedQtys[$imp->id] ?? 0),
                'uploaded_at' => $imp->uploaded_at,
                'uploaded_by_name' => $imp->uploaded_by_name,
                'validated_at' => $imp->validated_at,
                'consumed_at' => $imp->consumed_at,
                'consumed_by_name' => $imp->consumed_by_name,
            ];
        })->values();

        $summary = [
            'total_batches' => $rows->count(),
            'consumed' => $rows->where('status', 'CONSUMED')->count(),
            'rejected' => $rows->where('status', 'REJECTED')->count(),
            'avg_pass_rate' => $rows->count() > 0 ? round($rows->avg('pass_rate'), 1) : 0,
            'total_matched_qty' => $rows->sum('matched_qty'),
        ];

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => $summary,
            'rows' => $rows->all(),
        ];
    }

    /**
     * 5. Label/Print log — Snapshot, attempts, failure/reprint rate.
     * Filter: from_date, to_date, status, printer_id.
     */
    public function buildLabelLogReport(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:20'],
            'printer_id' => ['nullable', 'uuid'],
        ]);

        $from = $validated['from_date'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to_date'] ?? now()->toDateString();

        $query = DB::table('label_snapshots as ls')
            ->leftJoin('outbound_documents as od', 'od.id', '=', 'ls.outbound_document_id')
            ->leftJoin('app_users as cu', 'cu.id', '=', 'ls.created_by')
            ->select([
                'ls.id', 'ls.label_code', 'ls.outbound_document_id',
                'od.outbound_no', 'ls.sku_code', 'ls.product_name',
                'ls.item_code_value', 'ls.status', 'ls.reprint_count',
                'ls.template_version', 'ls.created_at',
                'cu.display_name as created_by_name',
            ])
            ->whereBetween('ls.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

        if (! empty($validated['status'])) {
            $query->where('ls.status', $validated['status']);
        }

        $snapshots = $query->orderByDesc('ls.created_at')->get();

        $snapshotIds = $snapshots->pluck('id')->all();
        $eventStats = [];
        if (! empty($snapshotIds)) {
            $eventStats = DB::table('label_print_events')
                ->whereIn('label_snapshot_id', $snapshotIds)
                ->groupBy('label_snapshot_id')
                ->selectRaw("label_snapshot_id, COUNT(*) AS total_attempts, SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count")
                ->get()
                ->keyBy('label_snapshot_id')
                ->all();

            if (! empty($validated['printer_id'])) {
                $filteredIds = DB::table('label_print_events')
                    ->whereIn('label_snapshot_id', $snapshotIds)
                    ->where('printer_id', $validated['printer_id'])
                    ->pluck('label_snapshot_id')
                    ->unique()
                    ->all();
                $snapshots = $snapshots->whereIn('id', $filteredIds)->values();
            }
        }

        $rows = $snapshots->map(function ($snap) use ($eventStats) {
            $stats = $eventStats[$snap->id] ?? null;

            return [
                'id' => $snap->id,
                'label_code' => $snap->label_code,
                'outbound_no' => $snap->outbound_no,
                'sku_code' => $snap->sku_code,
                'product_name' => $snap->product_name,
                'item_code_value' => $snap->item_code_value,
                'status' => $snap->status,
                'reprint_count' => (int) $snap->reprint_count,
                'total_attempts' => (int) ($stats?->total_attempts ?? 0),
                'failed_count' => (int) ($stats?->failed_count ?? 0),
                'template_version' => $snap->template_version,
                'created_at' => $snap->created_at,
                'created_by_name' => $snap->created_by_name,
            ];
        })->values();

        $totalSnaps = $rows->count();
        $failedSnaps = $rows->where('status', 'PRINT_FAILED')->count();
        $reprintedSnaps = $rows->where('reprint_count', '>', 0)->count();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_snapshots' => $totalSnaps,
                'printed' => $rows->where('status', 'PRINTED')->count(),
                'pending' => $rows->where('status', 'PENDING_PRINT')->count(),
                'failed' => $failedSnaps,
                'reprinted' => $reprintedSnaps,
                'failure_rate' => $totalSnaps > 0 ? round($failedSnaps / $totalSnaps * 100, 1) : 0,
                'reprint_rate' => $totalSnaps > 0 ? round($reprintedSnaps / $totalSnaps * 100, 1) : 0,
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * 6. Xuất theo nơi nhận — Agency là chiều báo cáo, KHÔNG phải stock.
     * Filter: from_date, to_date, agency_id, product_id.
     */
    public function buildOutboundByAgencyReport(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'agency_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
        ]);

        $from = $validated['from_date'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to_date'] ?? now()->toDateString();

        $query = DB::table('outbound_documents as od')
            ->join('outbound_lines as ol', 'ol.outbound_document_id', '=', 'od.id')
            ->join('products as p', 'p.id', '=', 'ol.sku_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'od.agency_id')
            ->where('od.status', 'POSTED')
            ->whereBetween('od.document_date', [$from, $to]);

        if (! empty($validated['agency_id'])) {
            $query->where('od.agency_id', $validated['agency_id']);
        }
        if (! empty($validated['product_id'])) {
            $query->where('ol.sku_id', $validated['product_id']);
        }

        $detailRows = $query
            ->select([
                'od.agency_id',
                DB::raw("COALESCE(a.agency_code, 'NO_AGENCY') as agency_code"),
                DB::raw("COALESCE(a.agency_name, od.recipient_name) as agency_name"),
                'ol.sku_id as product_id', 'p.code as product_code', 'p.name as product_name',
                'od.id as outbound_document_id', 'od.outbound_no',
                'od.document_date', 'od.posted_at',
                'ol.required_qty', 'ol.scanned_qty',
            ])
            ->orderBy('a.agency_code')
            ->orderBy('p.code')
            ->orderByDesc('od.document_date')
            ->get();

        $byAgency = $detailRows->groupBy('agency_code')->map(function ($group, $code) {
            $first = $group->first();

            return [
                'agency_code' => $code,
                'agency_name' => $first->agency_name,
                'document_count' => $group->pluck('outbound_document_id')->unique()->count(),
                'total_issued_qty' => $group->sum('scanned_qty'),
                'sku_count' => $group->pluck('product_id')->unique()->count(),
            ];
        })->values();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_agencies' => $byAgency->count(),
                'total_documents' => $detailRows->pluck('outbound_document_id')->unique()->count(),
                'total_issued_qty' => $detailRows->sum('scanned_qty'),
            ],
            'by_agency' => $byAgency->all(),
            'rows' => $detailRows->map(fn ($r) => [
                'agency_code' => $r->agency_code,
                'agency_name' => $r->agency_name,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'outbound_no' => $r->outbound_no,
                'document_date' => $r->document_date,
                'posted_at' => $r->posted_at,
                'required_qty' => (int) $r->required_qty,
                'scanned_qty' => (int) $r->scanned_qty,
            ])->all(),
        ];
    }
}
