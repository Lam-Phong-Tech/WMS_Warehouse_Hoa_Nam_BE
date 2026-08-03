<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockMovement;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Báo cáo XNT, dashboard và đối soát authoritative ledger. */
class ReportController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/reports/inventory-xnt', operationId: 'report_inventory_xnt', summary: 'Báo cáo Xuất-Nhập-Tồn', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Báo cáo XNT'), new OA\Response(response: 422, description: 'Khoảng ngày không hợp lệ')])]
    public function inventoryXnt(Request $request)
    {
        return $this->respondSuccess($this->buildXntReport($request));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt/export-excel', operationId: 'report_inventory_xnt_export_excel', summary: 'Xuất báo cáo XNT Excel', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'File XLSX')])]
    public function exportExcel(Request $request)
    {
        $report = $this->buildXntReport($request);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('XNT');
        $sheet->fromArray(['Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Tổng nhập', 'Tổng xuất'], null, 'A1');
        $rowIndex = 2;
        foreach ($report['lines'] as $line) {
            $sheet->fromArray([$line->product_code, $line->product_name, $line->stock_status, $line->total_in, $line->total_out], null, "A{$rowIndex}");
            $rowIndex++;
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = "bao-cao-xnt-{$report['from_date']}-den-{$report['to_date']}.xlsx";

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    #[OA\Get(path: '/api/v1/reports/dashboard-quick', operationId: 'report_dashboard_quick', summary: 'Dashboard nhanh kho tổng', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Số liệu dashboard')])]
    public function dashboardQuick(Request $request)
    {
        $from = $request->input('from_date', now()->startOfMonth()->toDateString());
        $to = $request->input('to_date', now()->toDateString());
        $movementSums = DB::table('stock_movements')
            ->selectRaw('COALESCE(SUM(CASE WHEN qty_delta > 0 THEN qty_delta ELSE 0 END), 0) AS receipt_qty')
            ->selectRaw('COALESCE(SUM(CASE WHEN qty_delta < 0 THEN -qty_delta ELSE 0 END), 0) AS issue_qty')
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->first();
        $partialCounts = StockDocument::query()
            ->where('status', StockDocument::STATUS_PARTIALLY_POSTED)
            ->selectRaw('doc_type, count(*) as total')
            ->groupBy('doc_type')
            ->pluck('total', 'doc_type');

        return $this->respondSuccess([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'available_qty' => (float) InventoryBalance::where('stock_status', 'AVAILABLE')->sum('available_qty'),
            'receipt_qty' => (float) ($movementSums->receipt_qty ?? 0),
            'issue_qty' => (float) ($movementSums->issue_qty ?? 0),
            'documents_partial' => [
                'inbound' => (int) ($partialCounts[StockDocument::TYPE_RECEIPT] ?? 0),
                'outbound' => (int) ($partialCounts[StockDocument::TYPE_ISSUE] ?? 0),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/reports/inventory-reconciliation',
        operationId: 'report_inventory_reconciliation',
        summary: 'Đối soát balance với signed SUM(qty_delta) theo stock_status',
        description: 'So sánh on_hand_qty với tổng qty_delta có dấu, theo đúng dimension warehouse_id/product_id/stock_status. Không đảo dấu theo movement_type lần hai.',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'only_mismatch', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [new OA\Response(response: 200, description: 'Kết quả đối soát')]
    )]
    public function inventoryReconciliation(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'string', 'max:30'],
            'only_mismatch' => ['nullable', 'boolean'],
        ]);

        $rows = $this->buildReconciliationRows($request);
        $mismatches = $rows->where('status', 'MISMATCH')->values();

        return $this->respondSuccess([
            'formula' => 'variance = balance.on_hand_qty - SUM(stock_movements.qty_delta)',
            'dimension' => ['warehouse_id', 'product_id', 'stock_status'],
            'summary' => [
                'rows_checked' => $rows->count(),
                'mismatch_count' => $mismatches->count(),
                'status' => $mismatches->isEmpty() ? 'PASS' : 'FAIL',
            ],
            'mismatches' => $mismatches,
            'rows' => $rows,
        ]);
    }

    private function buildXntReport(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);
        $lines = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->selectRaw('p.code as product_code, p.name as product_name, m.stock_status')
            ->selectRaw('SUM(CASE WHEN m.qty_delta > 0 THEN m.qty_delta ELSE 0 END) as total_in')
            ->selectRaw('SUM(CASE WHEN m.qty_delta < 0 THEN -m.qty_delta ELSE 0 END) as total_out')
            ->whereBetween('m.occurred_at', ["{$validated['from_date']} 00:00:00", "{$validated['to_date']} 23:59:59"])
            ->groupBy('p.code', 'p.name', 'm.stock_status')
            ->orderBy('p.code')
            ->orderBy('m.stock_status')
            ->get();

        return ['from_date' => $validated['from_date'], 'to_date' => $validated['to_date'], 'lines' => $lines];
    }

    private function buildReconciliationRows(Request $request)
    {
        $query = DB::table('inventory_balances as b')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin(DB::raw('(
                SELECT warehouse_id, product_id, stock_status,
                       SUM(qty_delta) AS ledger_qty, COUNT(*) AS movement_count
                FROM stock_movements
                GROUP BY warehouse_id, product_id, stock_status
            ) AS ledger'), function ($join) {
                $join->on('ledger.warehouse_id', '=', 'b.warehouse_id')
                    ->on('ledger.product_id', '=', 'b.product_id')
                    ->on('ledger.stock_status', '=', 'b.stock_status');
            })
            ->selectRaw('b.warehouse_id, w.code AS warehouse_code, b.product_id, p.code AS product_code,
                b.stock_status, b.on_hand_qty AS current_on_hand_qty,
                b.available_qty AS current_available_qty,
                COALESCE(ledger.ledger_qty, 0) AS expected_qty,
                COALESCE(ledger.movement_count, 0) AS movement_count');

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where("b.{$filter}", $request->input($filter));
            }
        }

        $rows = $query->orderBy('w.code')->orderBy('p.code')->orderBy('b.stock_status')->get()
            ->map(function ($row) {
                $delta = bcsub((string) $row->current_on_hand_qty, (string) $row->expected_qty, 3);
                $row->current_on_hand_qty = (float) $row->current_on_hand_qty;
                $row->current_available_qty = (float) $row->current_available_qty;
                $row->expected_qty = (float) $row->expected_qty;
                $row->movement_count = (int) $row->movement_count;
                $row->delta_qty = (float) $delta;
                $row->status = bccomp($delta, '0', 3) === 0 ? 'MATCH' : 'MISMATCH';
                $row->mismatch_reason = $row->status === 'MATCH' ? null : 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM';

                return $row;
            });

        return $request->boolean('only_mismatch')
            ? $rows->where('status', 'MISMATCH')->values()
            : $rows->values();
    }
}
