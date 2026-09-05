<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\ReportExportFileName;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Controllers\Concerns\CachesReportPayload;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Flow 06 import/match report with filter parity + server pagination + XLSX/PDF. */
class AlignedImportMatchReportController extends Controller
{
    use ApiResponse;
    use CachesReportPayload;

    private const EXPORT_ROW_LIMIT = 10000;

    #[OA\Get(path: '/api/v1/reports/import-match', operationId: 'report_import_match', summary: 'RPT-OUT-MATCH-01 đối soát toa yêu cầu xuất', description: '§13.10 RPT-OUT-MATCH-01. rows là tầng dòng SKU của toa: source_request_no, source_version, SKU, required/matched/scanned/posted, validation status. batches giữ tổng hợp theo batch Excel (upload/validate/consume).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', description: 'Trạng thái batch import', schema: new OA\Schema(type: 'string', enum: ['UPLOADED', 'VALIDATED', 'REJECTED', 'CONSUMED'])), new OA\Parameter(name: 'error_status', in: 'query', description: 'Validation status ở tầng dòng', schema: new OA\Schema(type: 'string', enum: ['VALID', 'INVALID', 'ERROR', 'PENDING'])), new OA\Parameter(name: 'sku_code', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'request_id', in: 'query', description: 'Lọc theo toa canonical', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'batch_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Đối soát toa tầng dòng')])]
    public function index(Request $request)
    {
        $report = $this->build($request);

        return $this->respondSuccess($this->paginate($report, $request));
    }

    #[OA\Get(path: '/api/v1/reports/import-match/export-excel', operationId: 'report_import_match_export', summary: 'Xuất XLSX/PDF đối soát toa', description: 'BR-EXP-01..05: dùng đúng bộ lọc của màn hình, audit mọi lần xuất, tên file có timestamp, tối đa 10.000 dòng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['UPLOADED', 'VALIDATED', 'REJECTED', 'CONSUMED'])), new OA\Parameter(name: 'error_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['VALID', 'INVALID', 'ERROR', 'PENDING'])), new OA\Parameter(name: 'sku_code', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function export(Request $request)
    {
        $report = $this->build($request);
        // §13.10 RPT-OUT-MATCH-01: file xuất theo đúng chiều dòng của báo cáo —
        // source_request_no + SKU + required/matched/scanned + validation status.
        $rows = collect($report['rows'])->map(fn (array $row): array => [
            $row['source_request_no'], $row['source_version'], $row['row_no'], $row['sku_code'], $row['sku_name'],
            $row['required_qty'], $row['matched_qty'], $row['scanned_qty'], $row['posted_qty'],
            $row['validation_status'], $row['error_code'], $row['error_message'],
            $row['outbound_no'], $row['outbound_status'],
            $row['import_no'], $row['source_file_name'], $row['import_status'],
            $row['uploaded_at'], $row['uploaded_by_name'], $row['validated_at'],
            $row['consumed_at'], $row['consumed_by_name'],
        ])->all();
        $headers = [
            'Phiếu toa', 'Source version', 'Dòng', 'Mã SKU', 'Tên SKU',
            'Required', 'Matched', 'Scanned', 'Posted',
            'Validation', 'Error code', 'Error message',
            'Phiếu xuất', 'Trạng thái phiếu xuất',
            'Import', 'Source file', 'Trạng thái import',
            'Uploaded at', 'Uploaded by', 'Validated at', 'Consumed at', 'Consumed by',
        ];
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return $format === 'pdf'
            ? $this->exportPdf($request, 'Excel Import Match', $headers, $rows, ReportExportFileName::stamp('flow06-import-match.pdf'), 'import-match')
            : $this->exportXlsx($request, 'Import Match', $headers, $rows, ReportExportFileName::stamp('flow06-import-match.xlsx'), 'import-match');
    }

    /**
     * Job-facing tương đương export(): cùng build/headers/rows, chỉ khác điểm cuối trả
     * bytes thay vì HTTP response. Dùng bởi `GenerateReportExportJob` qua
     * `OperationalReportController::exportBytesFor()` — xem checklist "Hiệu năng và triển
     * khai" 27/08 (export chạy nền thay vì đồng bộ).
     *
     * @return array{bytes: string, fileName: string, rowCount: int, contentType: string}
     */
    public function exportPayload(Request $request): array
    {
        $report = $this->build($request);
        $rows = collect($report['rows'])->map(fn (array $row): array => [
            $row['source_request_no'], $row['source_version'], $row['row_no'], $row['sku_code'], $row['sku_name'],
            $row['required_qty'], $row['matched_qty'], $row['scanned_qty'], $row['posted_qty'],
            $row['validation_status'], $row['error_code'], $row['error_message'],
            $row['outbound_no'], $row['outbound_status'],
            $row['import_no'], $row['source_file_name'], $row['import_status'],
            $row['uploaded_at'], $row['uploaded_by_name'], $row['validated_at'],
            $row['consumed_at'], $row['consumed_by_name'],
        ])->all();
        $headers = [
            'Phiếu toa', 'Source version', 'Dòng', 'Mã SKU', 'Tên SKU',
            'Required', 'Matched', 'Scanned', 'Posted',
            'Validation', 'Error code', 'Error message',
            'Phiếu xuất', 'Trạng thái phiếu xuất',
            'Import', 'Source file', 'Trạng thái import',
            'Uploaded at', 'Uploaded by', 'Validated at', 'Consumed at', 'Consumed by',
        ];
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $this->assertExportLimit($rows);
        $this->auditExport($request, 'import-match', count($rows), $format);

        return $format === 'pdf'
            ? ['bytes' => $this->exportPdfBytes('Excel Import Match', $headers, $rows), 'fileName' => ReportExportFileName::stamp('flow06-import-match.pdf'), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->exportXlsxBytes('Import Match', $headers, $rows), 'fileName' => ReportExportFileName::stamp('flow06-import-match.xlsx'), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /**
     * Báo cáo đối soát toa: nhiều lượt join qua outbound_request_imports ->
     * import_rows -> outbound_requests -> stock_movements trên toàn kỳ. Đây là một
     * trong những truy vấn nặng nhất còn lại chưa có cache.
     *
     * Phân trang được cắt TRONG BỘ NHỚ ở paginate() sau khi build() chạy xong, nên
     * một entry phục vụ được mọi trang -> page/per_page không nằm trong key.
     */
    private function build(Request $request): array
    {
        return $this->cachedReportPayload(
            'warehouse:report:import-match',
            $this->reportFiltersWithoutPaging($request),
            $this->reportScopeIds(),
            fn (): array => $this->buildUncached($request)
        );
    }

    private function buildUncached(Request $request): array
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

        $batches = $imports->map(function (object $i) use ($matched): array {
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
        $batchCollection = collect($batches);
        $rows = $this->matchLines($batches, $importIds, $validated);
        $lineCollection = collect($rows);

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_batches' => count($batches),
                'consumed' => $batchCollection->where('status', 'CONSUMED')->count(),
                'rejected' => $batchCollection->where('status', 'REJECTED')->count(),
                'avg_pass_rate' => count($batches) > 0 ? round((float) $batchCollection->avg('pass_rate'), 2) : 0,
                'total_matched_qty' => (float) $batchCollection->sum('matched_qty'),
                // Đối soát tầng dòng theo §13.10: "Exact required quantity".
                'total_lines' => $lineCollection->count(),
                'total_required_qty' => (float) $lineCollection->sum('required_qty'),
                'total_scanned_qty' => (float) $lineCollection->sum('scanned_qty'),
                'fully_matched_lines' => $lineCollection->where('is_fully_matched', true)->count(),
                'invalid_lines' => $lineCollection->whereIn('validation_status', ['ERROR', 'INVALID'])->count(),
            ],
            // §13.10 RPT-OUT-MATCH-01: rows là tầng dòng SKU của toa; batches giữ
            // tổng hợp theo batch Excel để không mất evidence upload/validate/consume.
            'rows' => $rows,
            'batches' => $batches,
        ];
    }

    /**
     * Tầng dòng của RPT-OUT-MATCH-01: mỗi dòng SKU trong toa nguồn kèm
     * required/matched/scanned/posted và validation status.
     *
     * required lấy từ snapshot canonical của toa (outbound_request_import_rows →
     * normalized_payload), matched từ Outbound_Item_Match, scanned từ dòng phiếu
     * xuất và posted từ movement authoritative (DEC-INV-001).
     *
     * @param  list<array<string, mixed>>  $batches
     * @param  list<string>  $importIds
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function matchLines(array $batches, array $importIds, array $validated): array
    {
        $batchByImportId = collect($batches)->keyBy('id');
        if ($importIds === []) {
            return [];
        }

        $importRows = DB::table('outbound_request_import_rows')
            ->whereIn('import_id', $importIds)
            ->orderBy('import_id')
            ->orderBy('row_no')
            ->get(['import_id', 'row_no', 'validation_status', 'normalized_payload', 'raw_payload', 'validation_errors']);

        $requests = DB::table('outbound_requests')
            ->whereIn('import_id', $importIds)
            ->get(['id', 'import_id', 'request_no', 'version', 'status']);
        $requestByNo = $requests->keyBy(fn (object $r): string => (string) $r->request_no);
        $requestByImport = $requests->groupBy(fn (object $r): string => (string) $r->import_id);

        $fulfilment = $this->fulfilmentByRequestAndSku($requests->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $skuCatalog = $this->skuCatalog($importRows);

        $skuFilter = isset($validated['sku_code']) && $validated['sku_code'] !== ''
            ? strtoupper(trim((string) $validated['sku_code']))
            : null;
        $errorFilter = $validated['error_status'] ?? null;

        $lines = [];
        foreach ($importRows as $row) {
            $importId = (string) $row->import_id;
            $batch = $batchByImportId->get($importId);
            if ($batch === null) {
                continue;
            }
            $payload = json_decode((string) $row->normalized_payload, true) ?: (json_decode((string) $row->raw_payload, true) ?: []);
            $skuId = (string) ($payload['sku_id'] ?? '');
            $skuCode = strtoupper(trim((string) ($payload['sku_code'] ?? ($skuCatalog['byId'][$skuId]['code'] ?? ''))));
            $resolvedId = $skuId !== '' ? $skuId : (string) ($skuCatalog['byCode'][$skuCode]['id'] ?? '');
            $requestNo = trim((string) ($payload['request_no'] ?? ''));
            $request = $requestNo !== '' ? $requestByNo->get($requestNo) : ($requestByImport->get($importId)?->first());
            $validationStatus = (string) ($row->validation_status ?? 'PENDING');

            if ($skuFilter !== null && $skuCode !== $skuFilter) {
                continue;
            }
            if ($errorFilter !== null && $validationStatus !== $errorFilter) {
                continue;
            }

            $fulfilled = $fulfilment[($request !== null ? (string) $request->id : '').'|'.$resolvedId] ?? null;
            $requiredQty = (float) ($payload['required_qty'] ?? 0);
            $scannedQty = (float) ($fulfilled->scanned_qty ?? 0);
            $errors = json_decode((string) $row->validation_errors, true) ?: [];
            $firstError = is_array($errors) ? ($errors[0] ?? []) : [];

            $lines[] = [
                'id' => $importId.'|'.$row->row_no,
                'source_request_no' => $requestNo !== '' ? $requestNo : (string) ($request->request_no ?? ''),
                // Version của canonical request snapshot (ERD bảng 37) — khoá trace
                // "canonical request snapshot + outbound" ở cột Reconciliation.
                'source_version' => $request !== null ? (int) ($request->version ?? 0) : null,
                'source_request_status' => (string) ($request->status ?? ''),
                'row_no' => (int) $row->row_no,
                'sku_id' => $resolvedId !== '' ? $resolvedId : null,
                'sku_code' => $skuCode,
                'sku_name' => (string) ($skuCatalog['byId'][$resolvedId]['name'] ?? ($skuCatalog['byCode'][$skuCode]['name'] ?? '')),
                'required_qty' => $requiredQty,
                'matched_qty' => (float) ($fulfilled->matched_qty ?? 0),
                'scanned_qty' => $scannedQty,
                'posted_qty' => (float) ($fulfilled->posted_qty ?? 0),
                'is_fully_matched' => $requiredQty > 0 && abs($scannedQty - $requiredQty) < 0.001,
                'validation_status' => $validationStatus,
                'error_code' => $firstError['error_code'] ?? null,
                'error_message' => $firstError['message'] ?? null,
                'outbound_no' => (string) ($fulfilled->doc_no ?? ''),
                'outbound_status' => strtoupper((string) ($fulfilled->doc_status ?? '')),
                'import_no' => $batch['import_no'],
                'source_file_name' => $batch['source_file_name'],
                'import_status' => $batch['status'],
                'uploaded_at' => $batch['uploaded_at'],
                'uploaded_by_name' => $batch['uploaded_by_name'],
                'validated_at' => $batch['validated_at'],
                'consumed_at' => $batch['consumed_at'],
                'consumed_by_name' => $batch['consumed_by_name'],
            ];
        }

        // Batch chưa parse được dòng nào (upload lỗi/đang chờ) vẫn phải hiện trong
        // báo cáo đối soát, nếu không người dùng không thấy toa nào đang kẹt.
        $importsWithLines = collect($lines)->pluck('import_no')->unique()->all();
        if ($skuFilter === null && $errorFilter === null) {
            foreach ($batches as $batch) {
                if (in_array($batch['import_no'], $importsWithLines, true)) {
                    continue;
                }
                $request = $requestByImport->get($batch['id'])?->first();
                $lines[] = [
                    'id' => $batch['id'],
                    'source_request_no' => (string) ($request->request_no ?? ''),
                    'source_version' => $request !== null ? (int) ($request->version ?? 0) : null,
                    'source_request_status' => (string) ($request->status ?? ''),
                    'row_no' => null,
                    'sku_id' => null,
                    'sku_code' => '',
                    'sku_name' => '',
                    'required_qty' => 0.0,
                    'matched_qty' => 0.0,
                    'scanned_qty' => 0.0,
                    'posted_qty' => 0.0,
                    'is_fully_matched' => false,
                    'validation_status' => $batch['status'] === 'REJECTED' ? 'ERROR' : 'PENDING',
                    'error_code' => null,
                    'error_message' => null,
                    'outbound_no' => '',
                    'outbound_status' => '',
                    'import_no' => $batch['import_no'],
                    'source_file_name' => $batch['source_file_name'],
                    'import_status' => $batch['status'],
                    'uploaded_at' => $batch['uploaded_at'],
                    'uploaded_by_name' => $batch['uploaded_by_name'],
                    'validated_at' => $batch['validated_at'],
                    'consumed_at' => $batch['consumed_at'],
                    'consumed_by_name' => $batch['consumed_by_name'],
                ];
            }
        }

        return $lines;
    }

    /**
     * matched/scanned/posted theo (outbound_request_id, sku).
     *
     * @param  list<string>  $requestIds
     * @return array<string, object>
     */
    private function fulfilmentByRequestAndSku(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $matched = DB::table('outbound_item_matches as oim')
            ->join('stock_document_lines as l', 'l.id', '=', 'oim.outbound_line_id')
            ->join('stock_documents as sd', 'sd.id', '=', 'l.stock_document_id')
            ->whereIn('sd.outbound_request_id', $requestIds)
            ->groupBy('sd.outbound_request_id', 'l.product_id')
            ->select('sd.outbound_request_id', 'l.product_id')
            ->selectRaw('COALESCE(SUM(oim.quantity), 0) AS matched_qty')
            ->get()
            ->keyBy(fn (object $r): string => $r->outbound_request_id.'|'.$r->product_id);

        $posted = DB::table('stock_movements as m')
            ->join('stock_documents as sd', 'sd.id', '=', 'm.stock_document_id')
            ->whereIn('sd.outbound_request_id', $requestIds)
            ->where('m.movement_type', 'ISSUE')
            ->groupBy('sd.outbound_request_id', 'm.product_id')
            ->select('sd.outbound_request_id', 'm.product_id')
            ->selectRaw('COALESCE(SUM(ABS(m.qty_delta)), 0) AS posted_qty')
            ->get()
            ->keyBy(fn (object $r): string => $r->outbound_request_id.'|'.$r->product_id);

        return DB::table('stock_document_lines as l')
            ->join('stock_documents as sd', 'sd.id', '=', 'l.stock_document_id')
            ->whereIn('sd.outbound_request_id', $requestIds)
            ->groupBy('sd.outbound_request_id', 'l.product_id', 'sd.doc_no', 'sd.status')
            ->select('sd.outbound_request_id', 'l.product_id', 'sd.doc_no', 'sd.status as doc_status')
            ->selectRaw('COALESCE(SUM(l.scanned_qty), 0) AS scanned_qty')
            ->get()
            ->keyBy(fn (object $r): string => $r->outbound_request_id.'|'.$r->product_id)
            ->map(function (object $row) use ($matched, $posted): object {
                $key = $row->outbound_request_id.'|'.$row->product_id;
                $row->matched_qty = (float) ($matched[$key]->matched_qty ?? 0);
                $row->posted_qty = (float) ($posted[$key]->posted_qty ?? 0);

                return $row;
            })
            ->all();
    }

    /**
     * Danh mục SKU cần thiết để hiển thị mã/tên cho từng dòng toa.
     *
     * @param  Collection<int, object>  $importRows
     * @return array{byId: array<string, array{id: string, code: string, name: string}>, byCode: array<string, array{id: string, code: string, name: string}>}
     */
    private function skuCatalog($importRows): array
    {
        $ids = [];
        $codes = [];
        foreach ($importRows as $row) {
            $payload = json_decode((string) $row->normalized_payload, true) ?: (json_decode((string) $row->raw_payload, true) ?: []);
            if (! empty($payload['sku_id'])) {
                $ids[] = (string) $payload['sku_id'];
            }
            if (! empty($payload['sku_code'])) {
                $codes[] = strtoupper(trim((string) $payload['sku_code']));
            }
        }
        if ($ids === [] && $codes === []) {
            return ['byId' => [], 'byCode' => []];
        }

        $products = DB::table('products')
            ->where(function ($query) use ($ids, $codes): void {
                if ($ids !== []) {
                    $query->orWhereIn('id', array_unique($ids));
                }
                if ($codes !== []) {
                    $query->orWhereIn(DB::raw('UPPER(code)'), array_unique($codes));
                }
            })
            ->get(['id', 'code', 'name']);

        $byId = [];
        $byCode = [];
        foreach ($products as $product) {
            $entry = ['id' => (string) $product->id, 'code' => (string) $product->code, 'name' => (string) $product->name];
            $byId[$entry['id']] = $entry;
            $byCode[strtoupper($entry['code'])] = $entry;
        }

        return ['byId' => $byId, 'byCode' => $byCode];
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

    private function exportXlsx(Request $request, string $sheetTitle, array $headers, array $rows, string $fileName, string $reportKey)
    {
        $this->assertExportLimit($rows);
        $bytes = $this->exportXlsxBytes($sheetTitle, $headers, $rows);
        $this->auditExport($request, $reportKey, count($rows), 'xlsx');

        return response()->streamDownload(fn () => print $bytes, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(Request $request, string $title, array $headers, array $rows, string $fileName, string $reportKey)
    {
        $this->assertExportLimit($rows);
        $bytes = $this->exportPdfBytes($title, $headers, $rows);
        $this->auditExport($request, $reportKey, count($rows), 'pdf');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /** Phần dựng bytes dùng chung giữa response HTTP (exportXlsx()) và job nền (exportPayload()). */
    private function exportXlsxBytes(string $sheetTitle, array $headers, array $rows): string
    {
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

        return $dompdf->output();
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
