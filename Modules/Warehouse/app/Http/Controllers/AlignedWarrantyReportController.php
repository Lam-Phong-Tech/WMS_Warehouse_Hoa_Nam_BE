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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Warranty/Component report aligned to the canonical RECEIVED->CHECKING->REPAIRING->COMPLETED->RETURNED lifecycle. */
class AlignedWarrantyReportController extends Controller
{
    use ApiResponse;

    private const EXPORT_ROW_LIMIT = 10000;

    private const PROCESSING_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'];

    private const ALL_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    /**
     * §13.8: lý do xuất PII phải đủ dài để làm evidence audit, không nhận "x".
     *
     * Public vì `OperationalReportController::createExport()` phải từ chối sớm
     * theo ĐÚNG ngưỡng này (WWHN-258 / FE258-P1-04); hai bản sao rời nhau sẽ trôi
     * khỏi nhau và tạo ra hai endpoint cùng nghiệp vụ nhưng khác hợp đồng.
     */
    public const PII_REASON_MIN_LENGTH = 5;

    #[OA\Get(path: '/api/v1/reports/warranty-defects', operationId: 'getWarrantyDefectReport', summary: 'Annex C getWarrantyDefectReport — RPT-WAR-01/RPT-WAR-COMP-01', description: '§13.10 RPT-WAR-01 + RPT-WAR-COMP-01: case, SKU/model hoặc UNKNOWN_MODEL, defect, status, received/returned, turnaround, linh kiện đã xuất (chỉ purpose=WARRANTY, case active lúc Post). §13.8: customer_name/phone/address luôn trả ở dạng mask trên API (pii_masked=true); TEMP_ONLY được đếm riêng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'])), new OA\Parameter(name: 'defect_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'model', in: 'query', description: 'SKU code/name hoặc mô tả thủ công của case TEMP_ONLY', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'identification_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['IDENTIFIED', 'TEMP_ONLY'])), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'WarrantyDefectReportPage — không chứa PII thô')])]
    public function index(Request $request)
    {
        // §13.10 RPT-WAR-01 + §13.8: "customer/address masked by default" — báo cáo
        // trên màn hình luôn hiển thị cột khách hàng nhưng ở dạng đã mask; giá trị
        // đầy đủ chỉ dành cho actor có warranty.pii.view.
        return $this->respondSuccess($this->paginate($this->build($request, false), $request));
    }

    #[OA\Get(path: '/api/v1/reports/warranty-defects/export-excel', operationId: 'report_warranty_defects_export', summary: 'Xuất XLSX/PDF báo cáo bảo hành & linh kiện', description: '§13.8: cột PII chỉ ở dạng thô khi actor có warranty.pii.export VÀ gửi kèm reason; thiếu một trong hai thì file vẫn xuất nhưng đã mask. reason kèm quyền thiếu → 403; reason quá ngắn → 422 PII_EXPORT_REASON_INVALID. Mọi lần xuất đều audit kèm reason; tên file có timestamp (BR-EXP-03).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'reason', in: 'query', description: 'Lý do xuất PII (>= 5 ký tự); bỏ trống thì file được mask', schema: new OA\Schema(type: 'string', minLength: 5, maxLength: 500)), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'])), new OA\Parameter(name: 'defect_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'model', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'identification_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['IDENTIFIED', 'TEMP_ONLY']))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function export(Request $request)
    {
        // §13.8 Privacy Contract: "PII export requires warranty.pii.export + reason".
        // Thiếu một trong hai thì file vẫn xuất được nhưng ở dạng mask, không phải PII thô.
        $reason = trim((string) $request->query('reason', ''));
        $hasPiiPermission = (bool) $request->user()?->hasPermission('warranty.pii.export');
        if ($reason !== '' && ! $hasPiiPermission) {
            ApiError::abort(403, 'PERMISSION_DENIED', 'Xuất PII bảo hành cần quyền warranty.pii.export.', [
                'required_permission' => 'warranty.pii.export',
            ]);
        }
        if ($reason !== '' && mb_strlen($reason) < self::PII_REASON_MIN_LENGTH) {
            ApiError::abort(422, 'PII_EXPORT_REASON_INVALID', 'Lý do xuất PII phải có ít nhất '.self::PII_REASON_MIN_LENGTH.' ký tự.', [
                'field' => 'reason',
                'min_length' => self::PII_REASON_MIN_LENGTH,
            ]);
        }
        $includePii = $hasPiiPermission && $reason !== '';

        $report = $this->build($request, $includePii);
        $headers = [
            'Case', 'Nhận diện', 'SKU/Model', 'Bệnh/lỗi', 'Linh kiện đã xuất', 'Trạng thái',
            'Ngày mua', 'Ngày nhận', 'Ngày trả khách', 'Turnaround (ngày)', 'Ngày cập nhật',
            'Khách hàng', 'SĐT', 'Địa chỉ',
        ];
        $rows = array_map(fn (array $row): array => [
            $row['case_no'], $row['identification_status'], $row['model'], $row['defect'],
            $row['components_issued'], $row['status'], $row['purchase_date'] ?? '',
            $row['received_at'], $row['returned_at'] ?? '', $row['turnaround_days'] ?? '',
            $row['updated_at'],
            $row['customer_name'] ?? '', $row['customer_phone'] ?? '', $row['customer_address'] ?? '',
        ], $report['rows']);

        $this->assertLimit($rows);
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $this->auditExport($request, count($rows), $includePii, $format, $reason);

        // BR-EXP-03: tên file phải có timestamp để không ghi đè lần export trước.
        $stem = sprintf(
            'bao-cao-bao-hanh-%s-den-%s',
            $report['period']['from_date'],
            $report['period']['to_date']
        );

        return $format === 'pdf'
            ? $this->pdf($headers, $rows, ReportExportFileName::stamp("{$stem}.pdf"))
            : $this->xlsx($headers, $rows, ReportExportFileName::stamp("{$stem}.xlsx"));
    }

    /** §13.10 RPT-WAR-01 "turnaround": số ngày từ tiếp nhận tới trả khách/hoàn tất sửa. */
    private function turnaroundDays(string $receivedAt, mixed $closedAt): ?int
    {
        if ($receivedAt === '' || $closedAt === null || (string) $closedAt === '') {
            return null;
        }

        return Carbon::parse($receivedAt)->diffInDays(Carbon::parse((string) $closedAt));
    }

    private function build(Request $request, bool $includePii): array
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'status' => ['nullable', 'in:'.implode(',', self::ALL_STATUSES)],
            'defect_id' => ['nullable', 'uuid'],
            'identification_status' => ['nullable', 'in:IDENTIFIED,TEMP_ONLY'],
            // §13.10 RPT-WAR-01 filters: date, model, defect, status.
            'model' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? now()->toDateString());

        $query = DB::table('warranty_case as wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->leftJoin('defect_catalogs as d', 'd.defect_id', '=', 'wc.defect_id')
            // §13.10 "event timestamps Asia/Ho_Chi_Minh" + cột "received": kỳ báo cáo
            // phải chạy theo ngày tiếp nhận thực tế, không phải created_at của bản ghi.
            ->whereBetween(DB::raw('COALESCE(wc.received_at, wc.created_at)'), ["{$from} 00:00:00", "{$to} 23:59:59"]);
        foreach (['status', 'defect_id', 'identification_status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where("wc.{$filter}", $validated[$filter]);
            }
        }
        if (! empty($validated['model'])) {
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $model = '%'.$validated['model'].'%';
            $query->where(function ($nested) use ($operator, $model): void {
                $nested->where('s.sku_code', $operator, $model)
                    ->orWhere('s.sku_name', $operator, $model)
                    ->orWhere('wc.manual_product_description', $operator, $model);
            });
        }

        $cases = $query->orderByDesc(DB::raw('COALESCE(wc.received_at, wc.created_at)'))->get([
            'wc.warranty_case_id', 'wc.warranty_case_code', 'wc.identification_status', 'wc.status',
            'wc.created_at', 'wc.updated_at', 'wc.temporary_item_code', 'wc.customer_name',
            'wc.customer_phone', 'wc.customer_address', 'wc.purchase_date', 'wc.received_at',
            'wc.completed_at', 'wc.returned_at', 's.sku_code', 'd.defect_code', 'd.defect_name',
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
            $receivedAt = (string) ($case->received_at ?? $case->created_at);
            $row = [
                'warranty_case_id' => (string) $case->warranty_case_id,
                'case_no' => (string) $case->warranty_case_code,
                'identification_status' => (string) $case->identification_status,
                'temporary_item_code' => $case->temporary_item_code,
                'model' => $case->identification_status === 'TEMP_ONLY' && $case->sku_code === null ? 'UNKNOWN_MODEL' : ($case->sku_code ?? 'UNKNOWN_MODEL'),
                'defect' => $case->defect_code !== null ? trim("{$case->defect_code} · {$case->defect_name}") : 'Chưa phân loại',
                'components_issued' => $issued !== '' ? $issued : 'Chưa xuất',
                'status' => (string) $case->status,
                // §13.10 RPT-WAR-01 columns: received/returned + turnaround; purchase_date optional.
                'purchase_date' => $case->purchase_date,
                'received_at' => $receivedAt,
                'returned_at' => $case->returned_at,
                'turnaround_days' => $this->turnaroundDays($receivedAt, $case->returned_at ?? $case->completed_at),
                'updated_at' => (string) ($case->updated_at ?? $case->created_at),
            ];
            // §13.8: cột khách hàng LUÔN có mặt; chỉ nội dung đổi giữa full và mask
            // (N*** H*** / ******9922 / "***, <địa giới cuối>").
            $row['customer_name'] = $includePii
                ? (string) ($case->customer_name ?? '')
                : WarrantyPiiMasker::name((string) ($case->customer_name ?? ''));
            $row['customer_phone'] = $includePii
                ? (string) ($case->customer_phone ?? '')
                : WarrantyPiiMasker::phone((string) ($case->customer_phone ?? ''));
            $row['customer_address'] = $includePii
                ? (string) ($case->customer_address ?? '')
                : WarrantyPiiMasker::address((string) ($case->customer_address ?? ''));

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
            'pii_masked' => ! $includePii,
        ];
    }

    private function paginate(array $report, Request $request): array
    {
        if (! $request->filled('per_page')) {
            return $report;
        }
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 10)));
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

    private function auditExport(Request $request, int $rowCount, bool $pii, string $format, string $reason): void
    {
        // §13.8 "export audited": lý do là bằng chứng bắt buộc của lần xuất PII.
        $filters = $request->query();
        unset($filters['reason']);

        AuditLogger::record(
            action: 'WARRANTY_REPORT_EXPORTED', objectType: 'warranty_report', objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'pii_included' => $pii,
                'pii_reason' => $pii ? $reason : null,
                'row_count' => $rowCount,
                'format' => $format,
                'filters' => $filters,
            ],
        );
    }

    private function xlsx(array $headers, array $rows, string $fileName)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bao hanh');
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, 'A'.($index + 2));
        }
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

        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$fileName.'"']);
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
