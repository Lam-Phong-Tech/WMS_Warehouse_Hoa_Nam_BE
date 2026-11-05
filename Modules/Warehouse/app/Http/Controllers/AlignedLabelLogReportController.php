<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\ReportExportFileName;
use App\Support\ReportExportMemory;
use App\Support\ReportPdfRowLimit;
use App\Support\Validation\InputLimits;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Controllers\Concerns\CachesReportPayload;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Flow 06 Label/Print report for canonical one-Packing-Label-per-outbound-document semantics. */
class AlignedLabelLogReportController extends Controller
{
    use ApiResponse;
    use CachesReportPayload;

    private const EXPORT_ROW_LIMIT = 10000;

    /**
     * Kết quả một lần in được lưu bằng HAI bộ từ vựng: bộ nghiệp vụ mà API nhận
     * (`PENDING_PRINT` / `PRINTED` / `PRINT_FAILED`) và bộ ERD mà trigger PostgreSQL
     * chuẩn hoá `label_print_events.status` về (`PENDING` / `SENT` / `PRINTED` /
     * `FAILED`). Lọc theo một bộ duy nhất là bỏ sót dữ liệu thật trên DB tham chiếu.
     */
    private const EVENT_STATUS_VOCABULARY = [
        'PENDING_PRINT' => ['PENDING_PRINT', 'PENDING', 'SENT'],
        'PRINTED' => ['PRINTED'],
        'PRINT_FAILED' => ['PRINT_FAILED', 'FAILED'],
    ];

    /**
     * BR-EXP-01..05 "PII bị giới hạn" + NFR-SRS-015: file export chỉ hiện 3 số cuối
     * của SĐT khách; route export chỉ gác report.export nên không được xuất phone thô.
     */
    private function maskPhone(string $phone): string
    {
        return WarrantyPiiMasker::phone($phone);
    }

    #[OA\Get(path: '/api/v1/reports/label-log', operationId: 'report_label_log', summary: 'RPT-LABEL-01 nhật ký nhãn Packing Label', description: '§13.10 RPT-LABEL-01: label/outbound/printer/result/reprint count + tóm tắt SKU/số lượng. Cardinality hiện hành là đúng 01 Packing Label cho mỗi phiếu/lô xuất; mọi action in đều stock-neutral.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', description: 'Kết quả in — lọc nhãn CÓ ÍT NHẤT MỘT lần in mang kết quả này', schema: new OA\Schema(type: 'string', enum: ['PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'])), new OA\Parameter(name: 'action', in: 'query', description: 'Thao tác lần in — khớp cùng lần in với kết quả, máy in và người in; REPRINT gồm lần in lại tường minh và lịch sử suy từ lần thành công trước', schema: new OA\Schema(type: 'string', enum: ['PRINT', 'REPRINT'])), new OA\Parameter(name: 'printer_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'actor_id', in: 'query', description: 'Người thực hiện lần in', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Nhật ký nhãn')])]
    public function index(Request $request)
    {
        $report = $this->build($request);

        return $this->respondSuccess($this->paginate($report, $request));
    }

    #[OA\Get(path: '/api/v1/reports/label-log/export-excel', operationId: 'report_label_log_export', summary: 'Xuất XLSX/PDF nhật ký nhãn', description: 'BR-EXP-01..05 + NFR-SRS-015: dùng đúng bộ lọc màn hình, audit mọi lần xuất, tên file có timestamp, SĐT khách luôn ở dạng mask vì route chỉ gác report.export. GRANULARITY: file là bảng TỔNG HỢP THEO NHÃN (§13.10 RPT-LABEL-01), không phải từng lần in như bảng của màn RPT-05 — số dòng hai bên khác nhau có chủ ý; bộ lọc thì khớp, mọi bộ lọc theo lần in được dịch thành nhãn có ít nhất một lần in khớp.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'])), new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PRINT', 'REPRINT'])), new OA\Parameter(name: 'printer_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'actor_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
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

    /**
     * Job-facing tương đương export(): cùng build/headers/rows, chỉ khác điểm cuối trả
     * bytes thay vì HTTP response. Dùng bởi `GenerateReportExportJob` qua
     * `OperationalReportController::exportBytesFor()` — xem checklist "Hiệu năng và triển
     * khai" 27/08 (export chạy nền thay vì đồng bộ).
     *
     * @return array{bytes: string, fileName: string, rowCount: int, contentType: string}
     */
    public function exportPayload(Request $request, ?string $format = null): array
    {
        $report = $this->build($request);
        $rows = collect($report['rows'])->map(fn (array $row): array => [
            $row['outbound_no'], $row['label_code'], $row['id'], $row['outbound_date'], $row['recipient'], $this->maskPhone((string) ($row['customer_phone'] ?? '')),
            $row['label_count'], $row['item_count'], $row['sku_count'], $row['sku_summary'],
            $row['status'], $row['applied'] ? 'YES' : 'NO', $row['printer_code'], $row['printer_name'], $row['printed_by_name'], $row['total_attempts'],
            $row['failed_count'], $row['reprint_count'], $row['last_attempt_at'], $row['created_at'],
        ])->all();
        $headers = ['Outbound', 'Packing Label', 'Label Snapshot', 'Date', 'Recipient', 'Phone', 'Label count', 'Item count', 'SKU count', 'SKU summary', 'Status', 'Applied', 'Printer code', 'Printer', 'Printed by', 'Attempts', 'Failed', 'Reprint', 'Last attempt', 'Created at'];
        // WWHN-345 (TC_FUNC_06): request của JOB NỀN không mang `format` — `exportFilters()`
        // CỐ Ý loại nó khỏi filters (format thuộc về lần request, không thuộc bộ lọc báo cáo).
        // Đọc mỗi `$request->query()` ở đây thì mọi lần "Xuất PDF" đi qua job đều âm thầm dựng
        // XLSX: job vẫn READY nên không lỗi nào lộ ra, người dùng bấm PDF và tải về file XLSX.
        // Vì vậy tham số truyền vào THẮNG; query string chỉ còn phục vụ đường HTTP trực tiếp.
        $format = strtolower($format ?? (string) $request->query('format', 'xlsx'));
        $this->assertLimit($rows);
        $this->audit($request, count($rows), $format);

        return $format === 'pdf'
            ? ['bytes' => $this->exportPdfBytes('Packing Label / Print Log', $headers, $rows), 'fileName' => ReportExportFileName::stamp('flow06-label-log.pdf'), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->exportXlsxBytes('Label Log', $headers, $rows), 'fileName' => ReportExportFileName::stamp('flow06-label-log.xlsx'), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /**
     * Nhật ký nhãn: join label_snapshots với label_print_events (lần in gần nhất
     * của từng nhãn) trên toàn kỳ. Cùng khuôn cache với các báo cáo khác.
     *
     * GRANULARITY — HỢP ĐỒNG, KHÔNG PHẢI LỖI. §13.10 RPT-LABEL-01 chốt báo cáo này ở
     * mức NHÃN: mỗi dòng là một label_snapshot kèm số lần in / lỗi / in lại đã gộp.
     * Màn RPT-05 lại vẽ MỘT DÒNG MỘT LẦN IN (đọc thêm `/label-print-events`), nên số
     * dòng trên màn hình và trong file KHÁC NHAU một cách có chủ ý — màn hình nói rõ
     * điều đó ngay cạnh nút Xuất (i18n `reports.packingLabel.exportGranularityNote`).
     * Đừng "sửa" bằng cách đổi truy vấn này sang per-event: đó là đổi bộ 20 cột đã ký
     * ở WWHN-310 và phải qua BA.
     *
     * Cái PHẢI khớp là BỘ LỌC (BR-EXP-01): bốn bộ lọc của màn hình đều lọc theo LẦN
     * IN, nên ở đây chúng được dịch thành "nhãn có ít nhất một lần in khớp" — xem
     * $hasAttempt trong buildUncached().
     *
     * Phân trang cắt TRONG BỘ NHỚ ở paginate() -> bỏ page/per_page khỏi key.
     */
    private function build(Request $request): array
    {
        return $this->cachedReportPayload(
            'warehouse:report:label-log',
            $this->reportFiltersWithoutPaging($request),
            $this->reportScopeIds(),
            fn (): array => $this->buildUncached($request)
        );
    }

    private function buildUncached(Request $request): array
    {
        [$from, $to] = $this->period($request);
        // §13.10 RPT-LABEL-01 filters: date / printer / result / actor — cộng `action`
        // (In / In lại) để cả BỐN bộ lọc của màn RPT-05 tới được file, đúng BR-EXP-01
        // "export dùng đúng bộ lọc màn hình". Xem ghi chú hợp đồng ở đầu build().
        $validated = $request->validate([
            'status' => ['nullable', 'in:PENDING_PRINT,PRINTED,PRINT_FAILED'],
            'action' => ['nullable', 'in:PRINT,REPRINT'],
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

        // A filtered screen row is one attempt: status, printer and actor must all
        // match that same attempt, even though export aggregates one row per label.
        if (! empty($validated['status']) || ! empty($validated['printer_id']) || ! empty($validated['actor_id']) || ! empty($validated['action'])) {
            $query->whereExists(function ($sub) use ($validated): void {
                $sub->selectRaw('1')->from('label_print_events as lpe')
                    ->whereColumn('lpe.label_snapshot_id', 'ls.id');
                $this->constrainAttempt($sub, $validated, 'lpe');
            });
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.trim((string) $validated['keyword']).'%';
            $driver = DB::getDriverName();
            $operator = $driver === 'pgsql' ? 'ilike' : 'like';
            // Mã nhãn nằm trong `label_payload` (json) chứ không có cột riêng.
            $labelCode = $driver === 'pgsql'
                ? "ls.label_payload::jsonb ->> 'label_code'"
                : "json_extract(ls.label_payload, '\$.label_code')";
            $skuSummary = $driver === 'pgsql'
                ? "CAST(ls.label_payload::jsonb -> 'sku_summary' AS TEXT)"
                : "CAST(json_extract(ls.label_payload, '\$.sku_summary') AS TEXT)";
            $query->where(function ($sub) use ($keyword, $operator, $labelCode, $skuSummary, $validated): void {
                $sub->where('sd.doc_no', $operator, $keyword)
                    ->orWhere('sd.recipient_name', $operator, $keyword)
                    ->orWhere('ag.agency_name', $operator, $keyword)
                    ->orWhereRaw("{$labelCode} {$operator} ?", [$keyword])
                    ->orWhereRaw("{$skuSummary} {$operator} ?", [$keyword])
                    ->orWhereRaw("CAST(ls.id AS TEXT) {$operator} ?", [$keyword])
                    // Ô tìm kiếm của màn hình soi cả mã nhãn, máy in, người in và mã
                    // lỗi. Bốn thứ sau chỉ có ở bảng sự kiện / danh mục, nên trước bản
                    // vá này gõ tên máy in rồi bấm Xuất ra file KHÔNG lọc gì cả.
                    ->orWhereExists(function ($inner) use ($keyword, $operator, $validated): void {
                        $inner->selectRaw('1')->from('label_print_events as lpe2')
                            ->leftJoin('printer_configs as pc2', 'pc2.id', '=', 'lpe2.printer_config_id')
                            ->leftJoin('app_user as au2', 'au2.user_id', '=', 'lpe2.created_by')
                            ->whereColumn('lpe2.label_snapshot_id', 'ls.id')
                            ->where(function ($match) use ($keyword, $operator): void {
                                $match->where('pc2.printer_code', $operator, $keyword)
                                    ->orWhere('pc2.printer_name', $operator, $keyword)
                                    ->orWhere('au2.display_name', $operator, $keyword)
                                    ->orWhere('au2.username', $operator, $keyword)
                                    ->orWhere('lpe2.error_code', $operator, $keyword);
                            });
                        $this->constrainAttempt($inner, $validated, 'lpe2');
                    });
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

    /** Keep event filters and event-specific keyword matches on one screen row. */
    private function constrainAttempt($query, array $filters, string $alias): void
    {
        if (! empty($filters['status'])) {
            $query->whereIn($alias.'.status', self::EVENT_STATUS_VOCABULARY[$filters['status']]);
        }
        if (! empty($filters['printer_id'])) {
            $query->where($alias.'.printer_config_id', $filters['printer_id']);
        }
        if (! empty($filters['actor_id'])) {
            $query->where($alias.'.created_by', $filters['actor_id']);
        }
        if (! empty($filters['action'])) {
            // Legacy writers left event_type=PRINT on reprints. Match the screen:
            // explicit REPRINT wins; otherwise infer from chronological successes.
            $currentTime = "COALESCE({$alias}.requested_at, {$alias}.printed_at, {$alias}.created_at)";
            $priorTime = 'COALESCE(previous_print.requested_at, previous_print.printed_at, previous_print.created_at)';
            $reprint = "(COALESCE({$alias}.event_type, 'PRINT') = 'REPRINT' OR
                (SELECT COUNT(*) FROM label_print_events AS previous_print
                  WHERE previous_print.label_snapshot_id = {$alias}.label_snapshot_id
                    AND previous_print.status = 'PRINTED'
                    AND ({$priorTime}, COALESCE(previous_print.attempt_no, 0), previous_print.id)
                        < ({$currentTime}, COALESCE({$alias}.attempt_no, 0), {$alias}.id))
                >= CASE WHEN {$alias}.status = 'PRINTED' THEN 1 ELSE 2 END)";
            $query->whereRaw($filters['action'] === 'REPRINT' ? $reprint : "NOT {$reprint}");
        }
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
            // date() chứ không pastDate(): client vẫn gửi to_date = ngày mai làm mốc chặn trên của kỳ.
            'from_date' => array_merge(['nullable'], InputLimits::date()),
            'to_date' => array_merge(['nullable'], InputLimits::date(), ['after_or_equal:from_date']),
        ]);

        return [
            (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString()),
            (string) ($validated['to_date'] ?? now()->toDateString()),
        ];
    }

    private function exportXlsx(Request $request, string $sheetTitle, array $headers, array $rows, string $fileName)
    {
        $this->assertLimit($rows);
        $bytes = $this->exportXlsxBytes($sheetTitle, $headers, $rows);
        $this->audit($request, count($rows), 'xlsx');

        return response()->streamDownload(fn () => print $bytes, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(Request $request, string $title, array $headers, array $rows, string $fileName)
    {
        $this->assertLimit($rows);
        $bytes = $this->exportPdfBytes($title, $headers, $rows);
        $this->audit($request, count($rows), 'pdf');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /** Phần dựng bytes dùng chung giữa response HTTP (exportXlsx()) và job nền (exportPayload()). */
    private function exportXlsxBytes(string $sheetTitle, array $headers, array $rows): string
    {
        // WWHN-345: controller này dựng bytes bằng đường riêng nên KHÔNG đi qua hàng rào
        // của OperationalReportController::exportRows(). 128M của base image không đủ cho
        // một file XLSX cỡ hợp đồng (10.000 dòng ≈ 211 MB, đo 09/09/2026) — xem ReportExportMemory.
        ReportExportMemory::raise();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, 'A'.($index + 2));
        }
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /** Phần dựng bytes dùng chung giữa response HTTP (exportPdf()) và job nền (exportPayload()). */
    private function exportPdfBytes(string $title, array $headers, array $rows): string
    {
        // WWHN-345 (TC_FUNC_06). Hai hàng rào dưới đây là thứ ngăn "Xuất PDF" của RPT-05
        // biến thành job treo im lặng — trước bản vá, 166 dòng đã đủ vượt memory_limit 128M
        // và giết cả tiến trình đang dựng file (worker `queue` chết theo).
        ReportPdfRowLimit::assert($rows, count($headers));
        ReportExportMemory::raise();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $e = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // CSS rút gọn theo WWHN-347: mỗi thuộc tính viền/padding khai trên `td` là một style
        // Dompdf phải resolve cho TỪNG ô, và `border-collapse:collapse` bắt nó dựng thêm bản
        // đồ viền của cả bảng. Với đúng bảng 20 cột này, đổi sang chỉ `border-bottom` cắt
        // đỉnh bộ nhớ hơn một nửa ở cỡ trần (800 dòng: 1.188 MB → 460 MB, đo 09/09/2026) —
        // chính là thứ làm trần 800 dòng của `wms_export.pdf_row_limit` trở nên đúng ở màn này.
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;margin-top:8px}th,td{border-bottom:0.5px solid #999;padding:2px 4px;text-align:left}th{background:#e0e0e0;font-weight:bold}</style></head><body><h2>'.$e($title).'</h2><table><thead><tr>';
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

        return $dompdf->output();
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
