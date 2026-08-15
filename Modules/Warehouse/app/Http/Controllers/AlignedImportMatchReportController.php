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

/** Flow 06 import/match report with filter parity + server pagination + XLSX/PDF. */
class AlignedImportMatchReportController extends Controller
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
            $row['import_no'], $row['source_file_name'], $row['status'], $row['total_rows'],
            $row['valid_rows'], $row['error_rows'], $row['pass_rate'], $row['matched_qty'],
            $row['uploaded_at'], $row['uploaded_by_name'], $row['validated_at'],
            $row['consumed_at'], $row['consumed_by_name'],
        ])->all();
        $headers = ['Import', 'Source file', 'Status', 'Total rows', 'Valid rows', 'Error rows', 'Pass rate %', 'Matched qty', 'Uploaded at', 'Uploaded by', 'Validated at', 'Consumed at', 'Consumed by'];
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'pdf'
            ? $this->exportPdf($request, 'Excel Import Match', $headers, $rows, 'flow06-import-match.pdf', 'import-match')
            : $this->exportXlsx($request, 'Import Match', $headers, $rows, 'flow06-import-match.xlsx', 'import-match');
    }

    private function build(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'status' => ['nullable', 'in:UPLOADED,VALIDATED,REJECTED,CONSUMED'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'batch_id' => ['nullable', 'uuid'],
            'request_id' => ['nullable', 'uuid'],
            'sku_code' => ['nullable', 'string', 'max:120'],
            'error_status' => ['nullable', 'in:VALID,INVALID,ERROR,PENDING'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('outbound_request_imports as i')
            ->leftJoin('app_user as uploader', 'uploader.user_id', '=', 'i.created_by')
            ->leftJoin('app_user as consumer', 'consumer.user_id', '=', 'i.consumed_by')
            ->whereBetween('i.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('i.*', 'uploader.display_name as uploaded_by_name', 'consumer.display_name as consumed_by_name');

        if (! empty($validated['status'])) {
            $query->where('i.status', $validated['status']);
        }
        if (! empty($validated['batch_id'])) {
            $query->where('i.id', $validated['batch_id']);
        }
        if (! empty($validated['request_id'])) {
            $importId = DB::table('outbound_requests')->where('id', $validated['request_id'])->value('import_id');
            $importId === null ? $query->whereRaw('1 = 0') : $query->where('i.id', $importId);
        }
        if (! empty($validated['sku_code'])) {
            $skuCode = strtoupper(trim((string) $validated['sku_code']));
            $skuIds = DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [$skuCode])->pluck('sku_id')->map(fn ($v) => (string) $v)->all();
            $matchingImports = [];
            if ($skuIds !== []) {
                DB::table('outbound_request_import_rows')->orderBy('row_no')->get(['import_id', 'normalized_payload'])->each(
                    function (object $row) use (&$matchingImports, $skuIds, $skuCode): void {
                        $payload = json_decode((string) $row->normalized_payload, true) ?: [];
                        if (in_array((string) ($payload['sku_id'] ?? ''), $skuIds, true) || strtoupper((string) ($payload['sku_code'] ?? '')) === $skuCode) {
                            $matchingImports[(string) $row->import_id] = true;
                        }
                    }
                );
            }
            $ids = array_keys($matchingImports);
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('i.id', $ids);
        }
        if (! empty($validated['error_status'])) {
            $query->whereExists(function ($sub) use ($validated): void {
                $sub->selectRaw('1')
                    ->from('outbound_request_import_rows as ir')
                    ->whereColumn('ir.import_id', 'i.id')
                    ->where('ir.validation_status', $validated['error_status']);
            });
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.trim((string) $validated['keyword']).'%';
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($sub) use ($keyword, $operator): void {
                $sub->where('i.import_no', $operator, $keyword)
                    ->orWhere('i.source_file_name', $operator, $keyword)
                    ->orWhere('i.source_key', $operator, $keyword);
            });
        }

        $imports = $query->orderByDesc('i.created_at')->get();
        $importIds = $imports->pluck('id')->map(fn ($v) => (string) $v)->all();
        $matched = collect();
        if ($importIds !== []) {
            $matched = DB::table('outbound_requests as r')
                ->join('stock_documents as sd', 'sd.outbound_request_id', '=', 'r.id')
                ->join('stock_document_lines as l', 'l.stock_document_id', '=', 'sd.id')
                ->whereIn('r.import_id', $importIds)
                ->where('sd.status', 'POSTED')
                ->groupBy('r.import_id')
                ->select('r.import_id')
                ->selectRaw('SUM(l.scanned_qty) AS matched_qty')
                ->pluck('matched_qty', 'r.import_id');
        }

        $rows = $imports->map(function (object $i) use ($matched): array {
            $total = (int) ($i->total_rows ?? 0);
            $valid = (int) ($i->valid_rows ?? 0);

            return [
                'id' => (string) $i->id,
                'import_no' => (string) ($i->import_no ?? $i->id),
                'source_file_name' => (string) ($i->source_file_name ?? ''),
                'status' => (string) $i->status,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'error_rows' => (int) ($i->error_rows ?? 0),
                'pass_rate' => $total > 0 ? round($valid * 100 / $total, 2) : 0,
                'matched_qty' => (float) ($matched[(string) $i->id] ?? 0),
                'uploaded_at' => (string) $i->created_at,
                'uploaded_by_name' => $i->uploaded_by_name ?? null,
                'validated_at' => $i->validated_at ?? null,
                'consumed_at' => $i->consumed_at ?? ((string) $i->status === 'CONSUMED' ? ($i->updated_at ?? null) : null),
                'consumed_by_name' => $i->consumed_by_name ?? null,
            ];
        })->all();
        $collection = collect($rows);

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_batches' => count($rows),
                'consumed' => $collection->where('status', 'CONSUMED')->count(),
                'rejected' => $collection->where('status', 'REJECTED')->count(),
                'avg_pass_rate' => count($rows) > 0 ? round((float) $collection->avg('pass_rate'), 2) : 0,
                'total_matched_qty' => (float) $collection->sum('matched_qty'),
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

    private function exportXlsx(Request $request, string $sheetTitle, array $headers, array $rows, string $fileName, string $reportKey)
    {
        $this->assertExportLimit($rows);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, 'A'.($index + 2));
        }
        $this->auditExport($request, $reportKey, count($rows), 'xlsx');
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(Request $request, string $title, array $headers, array $rows, string $fileName, string $reportKey)
    {
        $this->assertExportLimit($rows);
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $escape = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:4px}th{background:#eee}</style></head><body>';
        $html .= '<h2>'.$escape($title).'</h2><table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>'.$escape($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.$escape($value).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $this->auditExport($request, $reportKey, count($rows), 'pdf');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    private function assertExportLimit(array $rows): void
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.', [
                'limit' => self::EXPORT_ROW_LIMIT,
                'row_count' => count($rows),
            ]);
        }
    }

    private function auditExport(Request $request, string $reportKey, int $rowCount, string $format): void
    {
        AuditLogger::record(
            action: 'REPORT_EXPORTED',
            objectType: 'report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => $reportKey, 'format' => $format, 'row_count' => $rowCount, 'filters' => $request->query()],
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
