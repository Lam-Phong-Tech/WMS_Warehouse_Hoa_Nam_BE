<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockDocument;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Báo cáo Xuất-Nhập-Tồn (XNT) + dashboard nhanh cho kho tổng Hoà Nam. */
class ReportController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/reports/inventory-xnt',
        operationId: 'report_inventory_xnt',
        summary: 'Báo cáo Xuất-Nhập-Tồn (XNT) theo khoảng thời gian',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tổng nhập (total_in)/xuất (total_out) theo sản phẩm trong khoảng thời gian'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền report.view'),
            new OA\Response(response: 422, description: 'Thiếu from_date/to_date hoặc to_date < from_date'),
        ]
    )]
    public function inventoryXnt(Request $request)
    {
        return $this->respondSuccess($this->buildXntReport($request));
    }

    #[OA\Get(
        path: '/api/v1/reports/inventory-xnt/export-excel',
        operationId: 'report_inventory_xnt_export_excel',
        summary: 'Xuất báo cáo XNT ra file Excel (.xlsx)',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File .xlsx tải về'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền report.view'),
            new OA\Response(response: 422, description: 'Thiếu from_date/to_date hoặc to_date < from_date'),
        ]
    )]
    public function exportExcel(Request $request)
    {
        $report = $this->buildXntReport($request);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('XNT');
        $sheet->fromArray(['Mã SKU', 'Tên SKU', 'Tổng nhập', 'Tổng xuất'], null, 'A1');

        $rowIndex = 2;
        foreach ($report['lines'] as $line) {
            $sheet->fromArray([
                $line->product_code, $line->product_name, $line->total_in, $line->total_out,
            ], null, "A{$rowIndex}");
            $rowIndex++;
        }

        $fileName = "bao-cao-xnt-{$report['from_date']}-den-{$report['to_date']}.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/reports/dashboard-quick',
        operationId: 'report_dashboard_quick',
        summary: 'Dashboard nhanh cho kho tổng: tồn hiện tại, nhập/xuất trong kỳ, phiếu đang dở dang, cảnh báo đối soát',
        description: 'Mặc định kỳ = tháng hiện tại nếu không truyền from_date/to_date. Các trường liên quan module chưa xây (stocktake, warranty, reconciliation) trả về 0/rỗng.',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Số liệu dashboard nhanh'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền report.view'),
        ]
    )]
    public function dashboardQuick(Request $request)
    {
        $from = $request->input('from_date', now()->startOfMonth()->toDateString());
        $to = $request->input('to_date', now()->toDateString());

        $movementSums = DB::table('stock_movements')
            ->selectRaw("SUM(CASE WHEN movement_type = 'RECEIPT_IN' THEN qty_delta ELSE 0 END) as receipt_qty")
            ->selectRaw("SUM(CASE WHEN movement_type = 'ISSUE_OUT' THEN -qty_delta ELSE 0 END) as issue_qty")
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->first();

        $partialCounts = StockDocument::query()
            ->where('status', StockDocument::STATUS_PARTIALLY_POSTED)
            ->selectRaw('doc_type, count(*) as total')
            ->groupBy('doc_type')
            ->pluck('total', 'doc_type');

        return $this->respondSuccess([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'available_qty' => (float) InventoryBalance::sum('available_qty'),
            'receipt_qty' => (float) ($movementSums->receipt_qty ?? 0),
            'issue_qty' => (float) ($movementSums->issue_qty ?? 0),
            // Chưa có module điều chỉnh tồn (adjustment) độc lập trong base — luôn 0.
            'adjustment_in' => 0,
            'adjustment_out' => 0,
            // Chưa có module stocktake trong base.
            'stocktake_variance' => 0,
            'documents_partial' => [
                'inbound' => (int) ($partialCounts[StockDocument::TYPE_RECEIPT] ?? 0),
                'outbound' => (int) ($partialCounts[StockDocument::TYPE_ISSUE] ?? 0),
            ],
            // Chưa có module bảo hành (warranty_case) trong base.
            'warranty_cases_by_status' => [],
            // Chưa có module đối soát (reconciliation) trong base.
            'reconciliation_warnings' => [],
        ]);
    }

    private function buildXntReport(Request $request): array
    {
        $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        $from = $request->input('from_date');
        $to = $request->input('to_date');

        $lines = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->selectRaw('p.code as product_code, p.name as product_name,'
                ."SUM(CASE WHEN m.movement_type = 'RECEIPT_IN' THEN m.qty_delta ELSE 0 END) as total_in,"
                ."SUM(CASE WHEN m.movement_type = 'ISSUE_OUT' THEN -m.qty_delta ELSE 0 END) as total_out")
            ->whereBetween('m.occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy('p.code', 'p.name')
            ->orderBy('p.code')
            ->get();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'lines' => $lines,
        ];
    }
}
