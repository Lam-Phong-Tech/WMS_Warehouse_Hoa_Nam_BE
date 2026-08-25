<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\ReportExportFileName;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Flow 06 Label/Print report for canonical one-Packing-Label-per-outbound-document semantics. */
class AlignedLabelLogReportController extends Controller
{
    use ApiResponse;

    private const EXPORT_ROW_LIMIT = 10000;

    /**
     * BR-EXP-01..05 "PII bị giới hạn" + NFR-SRS-015: file export chỉ hiện 3 số cuối
     * của SĐT khách; route export chỉ gác report.export nên không được xuất phone thô.
     */
    private function maskPhone(string $phone): string
    {
        return WarrantyPiiMasker::phone($phone);
    }

    #[OA\Get(path: '/api/v1/reports/label-log', operationId: 'report_label_log', summary: 'RPT-LABEL-01 nhật ký nhãn Packing Label', description: '§13.10 RPT-LABEL-01: label/outbound/printer/result/reprint count + tóm tắt SKU/số lượng. Cardinality hiện hành là đúng 01 Packing Label cho mỗi phiếu/lô xuất; mọi action in đều stock-neutral.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', description: 'Kết quả in', schema: new OA\Schema(type: 'string', enum: ['PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'])), new OA\Parameter(name: 'printer_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'actor_id', in: 'query', description: 'Người thực hiện lần in', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Nhật ký nhãn')])]
    public function index(Request $request)
    {
        $report = $this->build($request);

        return $this->respondSuccess($this->paginate($report, $request));
    }

    #[OA\Get(path: '/api/v1/reports/label-log/export-excel', operationId: 'report_label_log_export', summary: 'Xuất XLSX/PDF nhật ký nhãn', description: 'BR-EXP-01..05 + NFR-SRS-015: dùng đúng bộ lọc màn hình, audit mọi lần xuất, tên file có timestamp, SĐT khách luôn ở dạng mask vì route chỉ gác report.export.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'])), new OA\Parameter(name: 'printer_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'actor_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function export(Request $request)
    {
        $report = $this->build($request);
        $rows = collect($report['rows'])->map(fn (array $row): array => [
            $row['outbound_no'], $row['label_code'], $row['id'], $row['outbound_date'], $row['recipient'], $this->maskPhone((string) ($row['customer_phone'] ?? '')),
            $row['label_count'], $row['item_count'], $row['sku_count'], $row['sku_summary'],
            $row['status'], $row['applied'] ? 'YES' : 'NO', $row['printer_code'], $row['printer_name'], $row['printed_by_name'], $row['total_attempts'],
            $row['failed_count'], $row['reprint_count'], $row['last_attempt_at'], $row['created_at'],
        ])->all();
        // §13.10 RPT-LABEL-01 columns: label/outbound/printer/result/reprint count + SKU/quantity summary.
        $headers = ['Outbound', 'Packing Label', 'Label Snapshot', 'Date', 'Recipient', 'Phone', 'Label count', 'Item count', 'SKU count', 'SKU summary', 'Status', 'Applied', 'Printer code', 'Printer', 'Printed by', 'Attempts', 'Failed', 'Reprint', 'Last attempt', 'Created at'];
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'pdf'
            ? $this->exportPdf($request, 'Packing Label / Print Log', $headers, $rows, ReportExportFileName::stamp('flow06-label-log.pdf'))
            : $this->exportXlsx($request, 'Label Log', $headers, $rows, ReportExportFileName::stamp('flow06-label-log.xlsx'));
    }

    private function build(Request $request): array
    {
        [$from, $to] = $this->period($request);
        // §13.10 RPT-LABEL-01 filters: date / printer / result / actor.
        $validated = $request->validate([
            'status' => ['nullable', 'in:PENDING_PRINT,PRINTED,PRINT_FAILED'],
            'printer_id' => ['nullable', 'uuid'],
            'actor_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // WWHN-310 §13.10 RPT-LABEL-01. Hai lệch của bản cũ:
        //  - failed_count chỉ đếm 'PRINT_FAILED', nhưng trên PostgreSQL trigger
        //    chuẩn hoá status của sự kiện in về bộ ERD nên giá trị lưu là 'FAILED'
        //    ⇒ số lần in lỗi luôn bằng 0 trên DB tham chiếu.
        //  - reprint_count suy ra bằng total_attempts - 1 ⇒ một lần in lỗi rồi in
        //    lại thành công bị báo là "đã in lại 1 lần" dù chưa hề in lại.
        // Định nghĩa đúng: in lại là lần in THÀNH CÔNG thứ hai trở đi; retry sau lỗi
        // thuộc cột failed. Cách tính này đúng trên cả hai driver.
        $attempts = DB::table('label_print_events')
            ->select('label_snapshot_id')
            ->selectRaw('COUNT(*) AS total_attempts')
            ->selectRaw("SUM(CASE WHEN status IN ('PRINT_FAILED', 'FAILED') THEN 1 ELSE 0 END) AS failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'PRINTED' THEN 1 ELSE 0 END) AS printed_count")
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
                'pa.total_attempts', 'pa.failed_count', 'pa.printed_count'
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
        if (! empty($validated['actor_id'])) {
            $actorId = $validated['actor_id'];
            $query->whereExists(function ($sub) use ($actorId): void {
                $sub->selectRaw('1')->from('label_print_events as lpe')
                    ->whereColumn('lpe.label_snapshot_id', 'ls.id')
                    ->where('lpe.created_by', $actorId);
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

        $snapshots = $query->orderByDesc('ls.created_at')->get();
        $lastAttempts = $this->lastPrintAttempts($snapshots->pluck('id')->map(fn ($id): string => (string) $id)->all());

        $rows = $snapshots->map(function (object $s) use ($lastAttempts): array {
            $payload = json_decode((string) $s->label_payload, true) ?: [];
            $attempts = (int) ($s->total_attempts ?? 0);
            $lastAttempt = $lastAttempts[(string) $s->id] ?? null;
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
                'label_code' => (string) ($payload['label_code'] ?? ''),
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
                // §13.10 RPT-LABEL-01 cột "printer" + "actor": lấy theo attempt gần
                // nhất của chính nhãn đó (ERD bảng 44 — mỗi attempt một dòng).
                'printer_config_id' => $lastAttempt->printer_config_id ?? null,
                'printer_code' => (string) ($lastAttempt->printer_code ?? ''),
                'printer_name' => (string) ($lastAttempt->printer_name ?? ''),
                'printed_by' => $lastAttempt->created_by ?? null,
                'printed_by_name' => (string) ($lastAttempt->actor_name ?? ''),
                'last_attempt_at' => (string) ($lastAttempt->created_at ?? ''),
                'total_attempts' => $attempts,
                'failed_count' => (int) ($s->failed_count ?? 0),
                'reprint_count' => max(0, (int) ($s->printed_count ?? 0) - 1),
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

    /**
     * Attempt in gần nhất của từng nhãn, kèm máy in và actor. Gom một truy vấn rồi
     * rút gọn trong PHP để tránh window function (sqlite lẫn PostgreSQL đều chạy).
     *
     * @param  list<string>  $snapshotIds
     * @return array<string, object>
     */
    private function lastPrintAttempts(array $snapshotIds): array
    {
        if ($snapshotIds === []) {
            return [];
        }

        return DB::table('label_print_events as lpe')
            ->leftJoin('printer_configs as pc', 'pc.id', '=', 'lpe.printer_config_id')
            ->leftJoin('app_user as au', 'au.user_id', '=', 'lpe.created_by')
            ->whereIn('lpe.label_snapshot_id', $snapshotIds)
            ->orderBy('lpe.created_at')
            ->get([
                'lpe.label_snapshot_id', 'lpe.printer_config_id', 'lpe.created_by', 'lpe.created_at',
                'pc.printer_code', 'pc.printer_name',
                DB::raw('COALESCE(au.display_name, au.username) as actor_name'),
            ])
            ->keyBy(fn (object $row): string => (string) $row->label_snapshot_id)
            ->all();
    }

    private function paginate(array $report, Request $request): array
    {
        if (! $request->filled('per_page')) {
            return $report;
        }
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 10)));
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
