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

/** Flow 06 Label/Print report for canonical one-Packing-Label-per-outbound-document semantics. */
class AlignedLabelLogReportController extends Controller
{
    use ApiResponse;

    private const EXPORT_ROW_LIMIT = 10000;

    public function index(Request $request)
    {
        $report = $this->build($request);

        return $this->respondSuccess($this->paginate($report, $request));
    }

    public function export(Request $request)
    {
        $report = $this->build($request);
        $rows = collect($report['rows'])->map(fn (array $row): array => [
            $row['outbound_no'], $row['outbound_date'], $row['recipient'], $row['customer_phone'],
            $row['label_count'], $row['item_count'], $row['sku_count'], $row['sku_summary'],
            $row['status'], $row['applied'] ? 'YES' : 'NO', $row['total_attempts'],
            $row['failed_count'], $row['reprint_count'], $row['created_at'],
        ])->all();
        $headers = ['Outbound', 'Date', 'Recipient', 'Phone', 'Label count', 'Item count', 'SKU count', 'SKU summary', 'Status', 'Applied', 'Attempts', 'Failed', 'Reprint', 'Created at'];
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'pdf'
            ? $this->exportPdf($request, 'Packing Label / Print Log', $headers, $rows, 'flow06-label-log.pdf')
            : $this->exportXlsx($request, 'Label Log', $headers, $rows, 'flow06-label-log.xlsx');
    }

    private function build(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'status' => ['nullable', 'in:PENDING_PRINT,PRINTED,PRINT_FAILED'],
            'printer_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $attempts = DB::table('label_print_events')
            ->select('label_snapshot_id')
            ->selectRaw('COUNT(*) AS total_attempts')
            ->selectRaw("SUM(CASE WHEN status = 'PRINT_FAILED' THEN 1 ELSE 0 END) AS failed_count")
            ->groupBy('label_snapshot_id');

        $query = DB::table('label_snapshots as ls')
            ->join('stock_documents as sd', 'sd.id', '=', 'ls.outbound_document_id')
            ->leftJoin('agencies as ag', 'ag.agency_id', '=', 'sd.agency_id')
            ->leftJoinSub($attempts, 'pa', 'pa.label_snapshot_id', '=', 'ls.id')
            ->whereBetween('ls.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->whereNotNull('ls.outbound_document_id')
            ->select(
                'ls.id', 'ls.outbound_document_id', 'ls.status', 'ls.label_payload', 'ls.applied_at', 'ls.created_at',
                'sd.doc_no as outbound_no', 'sd.doc_date as outbound_date',
                DB::raw('COALESCE(ag.agency_name, sd.recipient_name) as recipient'),
                'pa.total_attempts', 'pa.failed_count'
            );

        if (! empty($validated['status'])) {
            $query->where('ls.status', $validated['status']);
        }
        if (! empty($validated['printer_id'])) {
            $printerId = $validated['printer_id'];
            $query->whereExists(function ($sub) use ($printerId): void {
                $sub->selectRaw('1')->from('label_print_events as lpe')
                    ->whereColumn('lpe.label_snapshot_id', 'ls.id')
                    ->where('lpe.printer_config_id', $printerId);
            });
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.trim((string) $validated['keyword']).'%';
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($sub) use ($keyword, $operator): void {
                $sub->where('sd.doc_no', $operator, $keyword)
                    ->orWhere('sd.recipient_name', $operator, $keyword)
                    ->orWhere('ag.agency_name', $operator, $keyword);
            });
        }

        $rows = $query->orderByDesc('ls.created_at')->get()->map(function (object $s): array {
            $payload = json_decode((string) $s->label_payload, true) ?: [];
            $attempts = (int) ($s->total_attempts ?? 0);
            $skuSummary = $payload['sku_summary'] ?? [];
            if (is_array($skuSummary)) {
                $skuSummary = collect($skuSummary)->map(function ($row): string {
                    if (! is_array($row)) {
                        return (string) $row;
                    }
                    $code = (string) ($row['sku_code'] ?? $row['sku_id'] ?? '');
                    $qty = (int) ($row['quantity'] ?? 0);

                    return trim($code.($qty > 0 ? ' x'.$qty : ''));
                })->filter()->implode(', ');
            }

            return [
                'id' => (string) $s->id,
                'outbound_document_id' => (string) $s->outbound_document_id,
                'outbound_no' => (string) $s->outbound_no,
                'outbound_date' => (string) $s->outbound_date,
                'recipient' => (string) ($s->recipient ?? $payload['recipient_name'] ?? ''),
                'customer_phone' => (string) ($payload['customer_phone'] ?? ''),
                'label_count' => 1,
                'item_count' => (int) ($payload['item_count'] ?? 0),
                'sku_count' => (int) ($payload['sku_count'] ?? 0),
                'sku_summary' => (string) $skuSummary,
                'status' => (string) $s->status,
                'applied' => $s->applied_at !== null,
                'total_attempts' => $attempts,
                'failed_count' => (int) ($s->failed_count ?? 0),
                'reprint_count' => max(0, $attempts - 1),
                'created_at' => (string) $s->created_at,
            ];
        })->all();

        $collection = collect($rows);
        $total = count($rows);

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_labels' => $total,
                'printed' => $collection->where('status', 'PRINTED')->count(),
                'pending' => $collection->where('status', 'PENDING_PRINT')->count(),
                'failed' => $collection->where('status', 'PRINT_FAILED')->count(),
                'applied' => $collection->where('applied', true)->count(),
                'reprinted' => $collection->where('reprint_count', '>', 0)->count(),
                'failure_rate' => $total > 0 ? round($collection->where('status', 'PRINT_FAILED')->count() * 100 / $total, 2) : 0,
                'reprint_rate' => $total > 0 ? round($collection->where('reprint_count', '>', 0)->count() * 100 / $total, 2) : 0,
            ],
            'rows' => $rows,
        ];
    }

    private function paginate(array $report, Request $request): array
    {
        if (! $request->filled('per_page')) {
            return $report;
        }
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $rows = collect($report['rows'] ?? []);
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

    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        return [
            (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString()),
            (string) ($validated['to_date'] ?? now()->toDateString()),
        ];
    }

    private function exportXlsx(Request $request, string $sheetTitle, array $headers, array $rows, string $fileName)
    {
        $this->assertLimit($rows);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, 'A'.($index + 2));
        }
        $this->audit($request, count($rows), 'xlsx');
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(Request $request, string $title, array $headers, array $rows, string $fileName)
    {
        $this->assertLimit($rows);
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $e = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:4px}th{background:#eee}</style></head><body><h2>'.$e($title).'</h2><table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>'.$e($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.$e($value).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $this->audit($request, count($rows), 'pdf');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    private function assertLimit(array $rows): void
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.');
        }
    }

    private function audit(Request $request, int $rowCount, string $format): void
    {
        AuditLogger::record(
            action: 'REPORT_EXPORTED', objectType: 'report', objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => 'label-log', 'format' => $format, 'row_count' => $rowCount, 'filters' => $request->query()],
        );
    }

    private function excelSafe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = ltrim($value);

        return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
