<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Warranty/Component report aligned to the canonical RECEIVED->CHECKING->REPAIRING->COMPLETED->RETURNED lifecycle. */
class AlignedWarrantyReportController extends Controller
{
    use ApiResponse;

    private const EXPORT_ROW_LIMIT = 10000;

    private const PROCESSING_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'];

    private const ALL_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    public function index(Request $request)
    {
        return $this->respondSuccess($this->paginate($this->build($request, false), $request));
    }

    public function export(Request $request)
    {
        $includePii = (bool) $request->user()?->hasPermission('warranty.pii.export');
        $report = $this->build($request, $includePii);
        $headers = ['Case', 'Nhận diện', 'SKU/Model', 'Bệnh/lỗi', 'Linh kiện đã xuất', 'Trạng thái', 'Ngày nhận', 'Ngày cập nhật'];
        if ($includePii) {
            $headers = array_merge($headers, ['Khách hàng', 'SĐT', 'Địa chỉ']);
        }
        $rows = array_map(function (array $row) use ($includePii): array {
            $result = [
                $row['case_no'], $row['identification_status'], $row['model'], $row['defect'],
                $row['components_issued'], $row['status'], $row['received_at'], $row['updated_at'],
            ];
            if ($includePii) {
                $result = array_merge($result, [
                    $row['customer_name'] ?? '', $row['customer_phone'] ?? '', $row['customer_address'] ?? '',
                ]);
            }
            return $result;
        }, $report['rows']);

        $this->assertLimit($rows);
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $this->auditExport($request, count($rows), $includePii, $format);

        return $format === 'pdf'
            ? $this->pdf($headers, $rows, 'bao-cao-bao-hanh.pdf')
            : $this->xlsx($headers, $rows, 'bao-cao-bao-hanh.xlsx');
    }

    private function build(Request $request, bool $includePii): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'status' => ['nullable', 'in:'.implode(',', self::ALL_STATUSES)],
            'defect_id' => ['nullable', 'uuid'],
            'identification_status' => ['nullable', 'in:IDENTIFIED,TEMP_ONLY'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? now()->toDateString());

        $query = DB::table('warranty_case as wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->leftJoin('defect_catalogs as d', 'd.defect_id', '=', 'wc.defect_id')
            ->whereBetween('wc.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        foreach (['status', 'defect_id', 'identification_status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where("wc.{$filter}", $validated[$filter]);
            }
        }

        $cases = $query->orderByDesc('wc.created_at')->get([
            'wc.warranty_case_id', 'wc.warranty_case_code', 'wc.identification_status', 'wc.status',
            'wc.created_at', 'wc.updated_at', 'wc.temporary_item_code', 'wc.customer_name',
            'wc.customer_phone', 'wc.customer_address', 's.sku_code', 'd.defect_code', 'd.defect_name',
        ]);

        $caseIds = $cases->pluck('warranty_case_id')->all();
        $issuedByCase = collect();
        if ($caseIds !== []) {
            $issuedByCase = DB::table('component_issue_documents as cid')
                ->join('component_issue_lines as cil', 'cil.component_issue_document_id', '=', 'cid.id')
                ->join('sku as cs', 'cs.sku_id', '=', 'cil.sku_id')
                ->where('cid.status', 'POSTED')
                ->whereIn('cid.warranty_case_id', $caseIds)
                ->groupBy('cid.warranty_case_id', 'cs.sku_code')
                ->selectRaw('cid.warranty_case_id, cs.sku_code, SUM(cil.scanned_qty) AS issued_qty')
                ->get()->groupBy('warranty_case_id');
        }

        $rows = $cases->map(function (object $case) use ($issuedByCase, $includePii): array {
            $issued = collect($issuedByCase->get((string) $case->warranty_case_id) ?? [])
                ->map(fn (object $item): string => "{$item->sku_code} × ".(int) $item->issued_qty)
                ->implode('; ');
            $row = [
                'warranty_case_id' => (string) $case->warranty_case_id,
                'case_no' => (string) $case->warranty_case_code,
                'identification_status' => (string) $case->identification_status,
                'temporary_item_code' => $case->temporary_item_code,
                'model' => $case->identification_status === 'TEMP_ONLY' && $case->sku_code === null ? 'UNKNOWN_MODEL' : ($case->sku_code ?? 'UNKNOWN_MODEL'),
                'defect' => $case->defect_code !== null ? trim("{$case->defect_code} · {$case->defect_name}") : 'Chưa phân loại',
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

        $byDefect = $cases->groupBy(fn (object $case): string => $case->defect_code !== null
            ? trim("{$case->defect_code} · {$case->defect_name}")
            : 'Chưa phân loại')
            ->map(fn ($group, string $defect): array => [
                'defect' => $defect,
                'total' => $group->count(),
                'processing' => $group->whereIn('status', self::PROCESSING_STATUSES)->count(),
                'returned' => $group->where('status', 'RETURNED')->count(),
                'cancelled' => $group->where('status', 'CANCELLED')->count(),
                'temp_only' => $group->where('identification_status', 'TEMP_ONLY')->count(),
            ])->values()->all();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total' => $cases->count(),
                'processing' => $cases->whereIn('status', self::PROCESSING_STATUSES)->count(),
                'returned' => $cases->where('status', 'RETURNED')->count(),
                'cancelled' => $cases->where('status', 'CANCELLED')->count(),
                'temp_only' => $cases->where('identification_status', 'TEMP_ONLY')->count(),
            ],
            'rows' => $rows->all(),
            'by_defect' => $byDefect,
            'pii_included' => $includePii,
        ];
    }

    private function paginate(array $report, Request $request): array
    {
        if (! $request->filled('per_page')) return $report;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $rows = collect($report['rows']);
        $total = $rows->count();
        $report['rows'] = $rows->forPage($page, $perPage)->values()->all();
        $report['pagination'] = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
        return $report;
    }

    private function assertLimit(array $rows): void
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.', [
                'limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows),
            ]);
        }
    }

    private function auditExport(Request $request, int $rowCount, bool $pii, string $format): void
    {
        AuditLogger::record(
            action: 'WARRANTY_REPORT_EXPORTED', objectType: 'warranty_report', objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['pii_included' => $pii, 'row_count' => $rowCount, 'format' => $format, 'filters' => $request->query()],
        );
    }

    private function xlsx(array $headers, array $rows, string $fileName)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bao hanh');
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, 'A'.($index + 2));
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function pdf(array $headers, array $rows, string $fileName)
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $e = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:4px}</style></head><body><h2>Báo cáo bảo hành</h2><table><thead><tr>';
        foreach ($headers as $header) $html .= '<th>'.$e($header).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) $html .= '<td>'.$e($value).'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$fileName.'"']);
    }

    private function excelSafe(mixed $value): mixed
    {
        if (! is_string($value)) return $value;
        $trimmed = ltrim($value);
        return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
