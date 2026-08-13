<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryBalance;
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
        // SCOPE LOCK 0.3: không còn khái niệm PARTIALLY_POSTED/stocktake trong
        // build hiện hành nên dashboard không expose các field đó.
        $reconciliationWarnings = $this->buildReconciliationRows(new Request(['only_mismatch' => true]));

        return $this->respondSuccess([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'available_qty' => (float) InventoryBalance::where('stock_status', 'AVAILABLE')->sum('available_qty'),
            'receipt_qty' => (float) ($movementSums->receipt_qty ?? 0),
            'issue_qty' => (float) ($movementSums->issue_qty ?? 0),
            'warranty_cases_by_status' => [],
            'reconciliation_warnings' => $reconciliationWarnings,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/reports/inventory-reconciliation',
        operationId: 'report_inventory_reconciliation',
        summary: 'Đối soát balance với signed SUM(qty_delta) theo stock_status',
        description: 'So sánh on_hand_qty với tổng qty_delta có dấu trên hợp dimension của cả balance và ledger. Phát hiện cả movement không có balance và balance không có movement; không đảo dấu theo movement_type lần hai.',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'only_mismatch', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Kết quả đối soát'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền report.view'),
            new OA\Response(response: 422, description: 'Filter không hợp lệ'),
        ]
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
            'formula' => 'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)',
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

    #[OA\Get(path: '/api/v1/reports/warranty-defects', operationId: 'report_warranty_defects', summary: 'Báo cáo bảo hành & linh kiện', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'defect_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'identification_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['IDENTIFIED', 'TEMP_ONLY']))], responses: [new OA\Response(response: 200, description: 'Báo cáo bảo hành theo case + tổng hợp theo bệnh/lỗi; không chứa PII')])]
    public function warrantyDefects(Request $request)
    {
        return $this->respondSuccess($this->buildWarrantyReport($request, includePii: false));
    }

    #[OA\Get(path: '/api/v1/reports/warranty-defects/export-excel', operationId: 'report_warranty_defects_export_excel', summary: 'Xuất Excel báo cáo bảo hành & linh kiện', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'File XLSX; cột PII chỉ khi có warranty.pii.export và được audit')])]
    public function warrantyDefectsExportExcel(Request $request)
    {
        // NFR-SRS-015: export mặc định masked; cột PII đầy đủ chỉ khi caller có
        // warranty.pii.export và mọi lần export đều được audit.
        $includePii = (bool) $request->user()?->hasPermission('warranty.pii.export');
        $report = $this->buildWarrantyReport($request, includePii: $includePii);

        \Modules\AuditLog\Services\AuditLogger::record(
            action: 'WARRANTY_REPORT_EXPORTED',
            objectType: 'warranty_report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'pii_included' => $includePii,
                'row_count' => count($report['rows']),
                'period' => $report['period'],
            ],
        );

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bao hanh');
        $header = ['Case', 'Nhận diện', 'SKU/Model', 'Bệnh / lỗi', 'Linh kiện đã xuất', 'Trạng thái', 'Ngày nhận', 'Ngày cập nhật'];
        if ($includePii) {
            $header = array_merge($header, ['Khách hàng', 'SĐT', 'Địa chỉ']);
        }
        $sheet->fromArray($header, null, 'A1');
        $rowIndex = 2;
        foreach ($report['rows'] as $row) {
            $line = [
                $row['case_no'], $row['identification_status'], $row['model'], $row['defect'],
                $row['components_issued'], $row['status'], $row['received_at'], $row['updated_at'],
            ];
            if ($includePii) {
                $line = array_merge($line, [$row['customer_name'] ?? '', $row['customer_phone'] ?? '', $row['customer_address'] ?? '']);
            }
            $sheet->fromArray($line, null, "A{$rowIndex}");
            $rowIndex++;
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = "bao-cao-bao-hanh-{$report['period']['from_date']}-den-{$report['period']['to_date']}.xlsx";

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * BP-WMS-12 bước 05: bảo hành theo SKU/model/defect/status/date;
     * TEMP_ONLY hiển thị UNKNOWN_MODEL; kèm trace linh kiện đã xuất (POSTED).
     */
    private function buildWarrantyReport(Request $request, bool $includePii): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:30'],
            'defect_id' => ['nullable', 'uuid'],
            'identification_status' => ['nullable', 'in:IDENTIFIED,TEMP_ONLY'],
        ]);
        $from = $validated['from_date'] ?? $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to_date'] ?? $validated['to'] ?? now()->toDateString();

        $query = DB::table('warranty_case as wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->leftJoin('defect_catalogs as d', 'd.defect_id', '=', 'wc.defect_id')
            ->whereBetween('wc.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        foreach (['status', 'defect_id', 'identification_status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where("wc.{$filter}", $validated[$filter]);
            }
        }
        $cases = $query
            ->orderByDesc('wc.created_at')
            ->get([
                'wc.warranty_case_id', 'wc.warranty_case_code', 'wc.identification_status',
                'wc.status', 'wc.created_at', 'wc.updated_at', 'wc.temporary_item_code',
                'wc.customer_name', 'wc.customer_phone', 'wc.customer_address',
                's.sku_code', 'd.defect_code', 'd.defect_name',
            ]);

        // Trace linh kiện đã xuất: chỉ phiếu POSTED mới trừ tồn (BP-WMS-06).
        $issuedByCase = DB::table('component_issue_documents as cid')
            ->join('component_issue_lines as cil', 'cil.component_issue_document_id', '=', 'cid.id')
            ->join('sku as cs', 'cs.sku_id', '=', 'cil.sku_id')
            ->where('cid.status', 'POSTED')
            ->whereIn('cid.warranty_case_id', $cases->pluck('warranty_case_id')->all())
            ->groupBy('cid.warranty_case_id', 'cs.sku_code')
            ->selectRaw('cid.warranty_case_id, cs.sku_code, SUM(cil.scanned_qty) AS issued_qty')
            ->get()
            ->groupBy('warranty_case_id');

        $activeStatuses = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'];
        $rows = $cases->map(function (object $case) use ($issuedByCase, $includePii): array {
            $issued = collect($issuedByCase->get((string) $case->warranty_case_id) ?? [])
                ->map(fn (object $item): string => "{$item->sku_code} × ".(int) $item->issued_qty)
                ->implode('; ');
            $model = $case->identification_status === 'TEMP_ONLY' && $case->sku_code === null
                ? 'UNKNOWN_MODEL'
                : ($case->sku_code ?? 'UNKNOWN_MODEL');
            $row = [
                'warranty_case_id' => (string) $case->warranty_case_id,
                'case_no' => (string) $case->warranty_case_code,
                'identification_status' => (string) $case->identification_status,
                'temporary_item_code' => $case->temporary_item_code,
                'model' => $model,
                'defect' => $case->defect_code !== null
                    ? trim("{$case->defect_code} · {$case->defect_name}")
                    : 'Chưa phân loại',
                'components_issued' => $issued !== '' ? $issued : 'Chưa xuất',
                'status' => (string) $case->status,
                'received_at' => (string) $case->created_at,
                'updated_at' => (string) ($case->updated_at ?? $case->created_at),
            ];
            if ($includePii) {
                $row['customer_name'] = $case->customer_name;
                $row['customer_phone'] = $case->customer_phone;
                $row['customer_address'] = $case->customer_address;
            }

            return $row;
        })->values();

        $byDefect = $cases
            ->groupBy(fn (object $case): string => $case->defect_code !== null
                ? trim("{$case->defect_code} · {$case->defect_name}")
                : 'Chưa phân loại')
            ->map(function ($group, string $defect) use ($activeStatuses): array {
                return [
                    'defect' => $defect,
                    'total' => $group->count(),
                    'processing' => $group->whereIn('status', $activeStatuses)->count(),
                    'returned' => $group->where('status', 'RETURNED')->count(),
                    'cancelled' => $group->where('status', 'CANCELLED')->count(),
                    'temp_only' => $group->where('identification_status', 'TEMP_ONLY')->count(),
                ];
            })->values();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total' => $cases->count(),
                'processing' => $cases->whereIn('status', $activeStatuses)->count(),
                'returned' => $cases->where('status', 'RETURNED')->count(),
                'cancelled' => $cases->where('status', 'CANCELLED')->count(),
                'temp_only' => $cases->where('identification_status', 'TEMP_ONLY')->count(),
            ],
            'rows' => $rows->all(),
            'by_defect' => $byDefect->all(),
            'pii_included' => $includePii,
        ];
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
        $dimensions = DB::table('inventory_balances')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->union(DB::table('stock_movements')->select('warehouse_id', 'product_id', 'stock_status'));

        $ledger = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_qty, COUNT(*) AS movement_count')
            ->groupBy('warehouse_id', 'product_id', 'stock_status');

        $query = DB::query()
            ->fromSub($dimensions, 'd')
            ->leftJoin('inventory_balances as b', function ($join) {
                $join->on('b.warehouse_id', '=', 'd.warehouse_id')
                    ->on('b.product_id', '=', 'd.product_id')
                    ->on('b.stock_status', '=', 'd.stock_status');
            })
            ->leftJoinSub($ledger, 'ledger', function ($join) {
                $join->on('ledger.warehouse_id', '=', 'd.warehouse_id')
                    ->on('ledger.product_id', '=', 'd.product_id')
                    ->on('ledger.stock_status', '=', 'd.stock_status');
            })
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->selectRaw('d.warehouse_id, w.code AS warehouse_code, d.product_id, p.code AS product_code,
                d.stock_status, b.id AS balance_id,
                COALESCE(b.on_hand_qty, 0) AS current_on_hand_qty,
                COALESCE(b.available_qty, 0) AS current_available_qty,
                COALESCE(ledger.ledger_qty, 0) AS expected_qty,
                COALESCE(ledger.movement_count, 0) AS movement_count');

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where("d.{$filter}", $request->input($filter));
            }
        }

        $rows = $query->orderBy('w.code')->orderBy('p.code')->orderBy('d.stock_status')->get()
            ->map(function ($row) {
                $delta = bcsub((string) $row->current_on_hand_qty, (string) $row->expected_qty, 3);
                $balanceExists = $row->balance_id !== null;
                $row->balance_exists = $balanceExists;
                unset($row->balance_id);
                $row->current_on_hand_qty = (float) $row->current_on_hand_qty;
                $row->current_available_qty = (float) $row->current_available_qty;
                $row->expected_qty = (float) $row->expected_qty;
                $row->movement_count = (int) $row->movement_count;
                $row->delta_qty = (float) $delta;
                $row->status = bccomp($delta, '0', 3) === 0 ? 'MATCH' : 'MISMATCH';
                $row->mismatch_reason = match (true) {
                    $row->status === 'MATCH' => null,
                    ! $balanceExists && $row->movement_count > 0 => 'BALANCE_MISSING_FOR_LEDGER',
                    $balanceExists && $row->movement_count === 0 => 'LEDGER_MISSING_FOR_BALANCE',
                    default => 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM',
                };

                return $row;
            });

        return $request->boolean('only_mismatch')
            ? $rows->where('status', 'MISMATCH')->values()
            : $rows->values();
    }
}
