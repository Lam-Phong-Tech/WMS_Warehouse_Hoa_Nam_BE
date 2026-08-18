<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\ReportExportFileName;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Flow 06 · Báo cáo vận hành.
 *
 * Source of truth: signed stock_movements ledger. inventory_balances chỉ là
 * projection và mọi báo cáo tồn đều trả reconciliation evidence.
 *
 * Cải tiến v2:
 *  - LIKE tương thích SQLite + PostgreSQL (thay ilike)
 *  - by_agency đặt top-level response (khớp FE contract)
 *  - Actor names join app_user
 *  - Server-side pagination (page/per_page)
 *  - PDF export
 *  - Import/Match bổ sung filter baseline
 *  - Warranty date params dùng from_date/to_date nhất quán (fallback from/to)
 */
class OperationalReportController extends Controller
{
    use ApiResponse;

    private const STOCK_STATUSES = ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'];

    private const MOVEMENT_TYPES = ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'];

    private const EXPORT_ROW_LIMIT = 10000;

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /**
     * report_code (Annex C getReportByCode) → builder tương ứng. Danh sách khớp
     * đúng hub và §13.10.
     *
     * @var array<string, string>
     */
    private const REPORT_BUILDERS = [
        'inventory-balance' => 'buildInventoryBalance',
        'inventory-ledger' => 'buildMovementTrace',
        'inventory-xnt' => 'buildXnt',
        'document-history' => 'buildDocumentHistory',
        'movement-trace' => 'buildMovementTrace',
        'outbound-by-agency' => 'buildOutboundByAgency',
    ];

    /**
     * Ba báo cáo này được Flow06AlignmentServiceProvider override sang controller
     * Aligned; dispatcher phải đi qua đúng controller đó để trả cùng một contract
     * với endpoint chuyên biệt.
     *
     * @var array<string, class-string>
     */
    private const ALIGNED_REPORTS = [
        'import-match' => AlignedImportMatchReportController::class,
        'label-log' => AlignedLabelLogReportController::class,
        'warranty-defects' => AlignedWarrantyReportController::class,
    ];

    /** @var array<string, string> */
    private const EXPORT_DOWNLOAD_PATHS = [
        'inventory-balance' => '/api/v1/reports/inventory-balance/export-excel',
        'inventory-ledger' => '/api/v1/reports/movement-trace/export-excel',
        'inventory-xnt' => '/api/v1/reports/inventory-xnt/export-excel',
        'document-history' => '/api/v1/reports/document-history/export-excel',
        'movement-trace' => '/api/v1/reports/movement-trace/export-excel',
        'import-match' => '/api/v1/reports/import-match/export-excel',
        'label-log' => '/api/v1/reports/label-log/export-excel',
        'warranty-defects' => '/api/v1/reports/warranty-defects/export-excel',
        'outbound-by-agency' => '/api/v1/reports/outbound-by-agency/export-excel',
    ];

    // ── Hub ──────────────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/hub', operationId: 'report_hub', summary: 'Danh mục báo cáo vận hành Flow 06', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], responses: [new OA\Response(response: 200, description: 'Danh mục 8 báo cáo MVP')])]
    public function hub()
    {
        return $this->respondSuccess([
            ['key' => 'inventory-balance', 'name' => 'Tồn kho hiện tại'],
            ['key' => 'inventory-xnt', 'name' => 'Nhập-Xuất-Tồn'],
            ['key' => 'document-history', 'name' => 'Lịch sử phiếu nhập/xuất'],
            ['key' => 'movement-trace', 'name' => 'Movement/Trace'],
            ['key' => 'import-match', 'name' => 'Excel Import/Match'],
            ['key' => 'label-log', 'name' => 'Packing Label'],
            ['key' => 'warranty-defects', 'name' => 'Warranty/Component'],
            ['key' => 'outbound-by-agency', 'name' => 'Xuất theo nơi nhận'],
        ]);
    }

    /**
     * Annex C getInventoryLedgerReport (REQ-SRS-031). Sổ ledger là source of truth
     * theo DEC-INV-001: từng movement đã POSTED cộng dồn theo chiều thời gian.
     */
    #[OA\Get(path: '/api/v1/reports/inventory-ledger', operationId: 'getInventoryLedgerReport', summary: 'Annex C getInventoryLedgerReport — sổ ledger authoritative', description: 'REQ-SRS-031 / DEC-INV-001. Cùng contract với RPT-MOV-01: từng movement đã POSTED, append-only, kèm reversal chain.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'code_value', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'])), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'ReportRowPage — sổ ledger')])]
    public function inventoryLedger(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildMovementTrace($request), $request));
    }

    /**
     * Annex C getReportByCode (REQ-SRS-031): một cửa duy nhất trả bất kỳ báo cáo
     * nào trong hub theo report_code, dùng đúng filter/scope của báo cáo đó.
     */
    #[OA\Get(path: '/api/v1/reports/{report_code}', operationId: 'getReportByCode', summary: 'Annex C getReportByCode — trả báo cáo theo mã', description: 'REQ-SRS-031. Dispatcher trả đúng payload và đúng bộ filter của báo cáo tương ứng trong /reports/hub; query parameter chấp nhận theo từng report_code (xem endpoint chuyên biệt). report_code lạ trả 404 RESOURCE_NOT_FOUND kèm danh sách hợp lệ.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'report_code', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['inventory-balance', 'inventory-ledger', 'inventory-xnt', 'document-history', 'movement-trace', 'outbound-by-agency', 'import-match', 'label-log', 'warranty-defects'])), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'ReportRowPage của báo cáo tương ứng')])]
    public function byCode(Request $request, string $reportCode)
    {
        $this->assertKnownReportCode($reportCode);

        if (isset(self::ALIGNED_REPORTS[$reportCode])) {
            return app(self::ALIGNED_REPORTS[$reportCode])->index($request);
        }

        return $this->respondSuccess($this->paginateReport($this->{self::REPORT_BUILDERS[$reportCode]}($request), $request));
    }

    private function assertKnownReportCode(string $reportCode): void
    {
        $known = array_merge(array_keys(self::REPORT_BUILDERS), array_keys(self::ALIGNED_REPORTS));
        if (! in_array($reportCode, $known, true)) {
            sort($known);
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', "Không có báo cáo với report_code \"{$reportCode}\".", [
                'available' => $known,
            ]);
        }
    }

    /**
     * Annex C createReportExport (REQ-SRS-032): nhận ReportExportRequest kèm
     * Idempotency-Key, trả ExportJob. BR-EXP-01: export dùng đúng filter/scope của
     * màn hình nên job giữ nguyên filter đã gửi; BR-EXP-02: mọi export đều audit.
     */
    #[OA\Post(path: '/api/v1/reports/export', operationId: 'createReportExport', summary: 'Annex C createReportExport — tạo job xuất báo cáo', description: 'REQ-SRS-032 / BR-EXP-01..05. Bắt buộc Idempotency-Key; retry cùng key trả lại đúng job cũ (Idempotent-Replay: true). Export dùng đúng filter/scope của màn hình và luôn được audit; tên file trả về có timestamp.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 200))], requestBody: new OA\RequestBody(required: true, description: 'ReportExportRequest', content: new OA\JsonContent(required: ['report_code'], properties: [new OA\Property(property: 'report_code', type: 'string', enum: ['inventory-balance', 'inventory-ledger', 'inventory-xnt', 'document-history', 'movement-trace', 'import-match', 'label-log', 'warranty-defects', 'outbound-by-agency']), new OA\Property(property: 'format', type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx'), new OA\Property(property: 'filters', type: 'object', description: 'Bộ lọc y hệt màn hình đang xem', additionalProperties: true)])), responses: [new OA\Response(response: 201, description: 'ExportJob', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'export_job_id', type: 'string', format: 'uuid'), new OA\Property(property: 'report_code', type: 'string'), new OA\Property(property: 'format', type: 'string'), new OA\Property(property: 'status', type: 'string', enum: ['READY']), new OA\Property(property: 'row_count', type: 'integer'), new OA\Property(property: 'download_url', type: 'string'), new OA\Property(property: 'requested_at', type: 'string', format: 'date-time')], type: 'object')]))])]
    public function createExport(Request $request)
    {
        $validated = $request->validate([
            'report_code' => ['required', 'string', Rule::in(array_keys(self::EXPORT_DOWNLOAD_PATHS))],
            'format' => ['nullable', 'in:xlsx,pdf'],
            'filters' => ['nullable', 'array'],
        ]);
        $key = IdempotencyKeyGuard::require($request);
        $reportCode = (string) $validated['report_code'];
        $format = (string) ($validated['format'] ?? 'xlsx');
        // BR-EXP-01 "export dùng cùng filter/scope với màn hình": filter đến trong
        // body nên phải merge vào input trước khi builder chạy, nếu không job đếm
        // dòng trên TOÀN bộ dữ liệu (và báo cáo yêu cầu from_date sẽ 422 oan).
        $filters = $this->exportFilters($validated['filters'] ?? []);
        $request->merge($filters);
        $downloadUrl = self::EXPORT_DOWNLOAD_PATHS[$reportCode].'?'.http_build_query($filters + ['format' => $format]);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'report_export_'.$reportCode,
            $this->currentActorId(),
            IdempotencyStore::requestHash($request),
            function () use ($request, $reportCode, $format, $filters, $downloadUrl): array {
                $jobId = (string) Str::uuid();
                $rowCount = $this->exportRowCount($request, $reportCode);

                AuditLogger::record(
                    action: 'REPORT_EXPORT_REQUESTED',
                    objectType: 'report_export',
                    objectId: $jobId,
                    actorId: $this->currentActorId(),
                    requestId: $request->attributes->get('request_id'),
                    afterData: ['report_code' => $reportCode, 'format' => $format, 'row_count' => $rowCount, 'filters' => $filters],
                );

                return [201, [
                    'success' => true,
                    'message' => 'Đã tạo yêu cầu export; tải file qua download_url.',
                    'data' => [
                        'export_job_id' => $jobId,
                        'report_code' => $reportCode,
                        'format' => $format,
                        // Export chạy đồng bộ (không có queue worker trong MVP) nên job
                        // ở trạng thái READY ngay và download_url trỏ endpoint stream sẵn có.
                        'status' => 'READY',
                        'row_count' => $rowCount,
                        'filters' => $filters,
                        // download_url mang theo đúng filter đã gửi để file tải về không
                        // bao giờ rộng hơn phạm vi người dùng đang xem.
                        'download_url' => $downloadUrl,
                        'requested_at' => now()->toIso8601String(),
                    ],
                ]];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function currentActorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * Chuẩn hoá filter của ReportExportRequest về scalar để merge vào input và
     * ghép thành query string của download_url.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function exportFilters(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $name => $value) {
            if (! is_string($name) || is_array($value) || $value === null || $value === '') {
                continue;
            }
            // page/per_page thuộc màn hình, không thuộc phạm vi file export.
            if (in_array($name, ['page', 'per_page', 'format'], true)) {
                continue;
            }
            $normalized[$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $normalized;
    }

    /** Số dòng sẽ nằm trong file export, dùng đúng filter client gửi. */
    private function exportRowCount(Request $request, string $reportCode): int
    {
        if (isset(self::ALIGNED_REPORTS[$reportCode])) {
            $payload = app(self::ALIGNED_REPORTS[$reportCode])->index($request)->getData(true);

            return count($this->reportRows($payload['data'] ?? []));
        }

        return count($this->reportRows($this->{self::REPORT_BUILDERS[$reportCode]}($request)));
    }

    /** @param array<string, mixed> $report */
    private function reportRows(array $report): array
    {
        foreach (['rows', 'lines'] as $key) {
            if (isset($report[$key]) && is_array($report[$key])) {
                return $report[$key];
            }
        }

        return [];
    }

    // ── Inventory Balance ────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-balance', operationId: 'report_inventory_balance', summary: 'RPT-INV-01 tồn kho hiện tại kèm đối soát ledger', description: '§13.10 RPT-INV-01. Filter SKU/category/status + as_of (inclusive). Khi as_of ở quá khứ, báo cáo là ảnh chụp theo ledger và reconciliation_status = NOT_APPLICABLE.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'])), new OA\Parameter(name: 'as_of', in: 'query', description: 'Mốc chốt số liệu, inclusive (Asia/Ho_Chi_Minh)', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200))], responses: [new OA\Response(response: 200, description: 'Tồn kho hiện tại')])]
    public function inventoryBalance(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildInventoryBalance($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-balance/export-excel', operationId: 'report_inventory_balance_export', summary: 'Xuất XLSX/PDF tồn kho hiện tại', description: 'BR-EXP-01..05: cùng bộ lọc với /reports/inventory-balance, audit mọi lần xuất, tên file có timestamp, tối đa 10.000 dòng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'])), new OA\Parameter(name: 'as_of', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF tồn kho')])]
    public function inventoryBalanceExport(Request $request)
    {
        $report = $this->buildInventoryBalance($request);
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['category'], $row['stock_status'],
            $row['on_hand_qty'], $row['available_qty'], $row['serial_qty'], $row['box_qty'], $row['reserved_qty'],
            $row['location_hint'], $row['ledger_qty'], $row['variance'], $row['reconciliation_status'], $row['updated_at'],
        ], $report['rows']);
        $headers = ['Kho', 'Mã SKU', 'Tên SKU', 'Nhóm hàng', 'Trạng thái tồn', 'Tồn kho', 'Khả dụng', 'Serial', 'Trong khay/thùng', 'Đã giữ', 'Vị trí gợi ý', 'Ledger', 'Chênh lệch', 'Đối soát', 'Cập nhật'];

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Tồn kho hiện tại', $headers, $rows, 'bao-cao-ton-kho-hien-tai', 'inventory-balance', $request);
        }

        return $this->exportRows($request, 'Ton kho', $headers, $rows, 'bao-cao-ton-kho-hien-tai.xlsx', 'inventory-balance');
    }

    // ── XNT ──────────────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-xnt', operationId: 'report_inventory_xnt_current', summary: 'RPT-XNT-01 Nhập-Xuất-Tồn theo authoritative ledger', description: '§13.10 RPT-XNT-01 + BR-RPT-01: closing = opening + posted receipt - posted issue - component issue + posted reversal. Kỳ inclusive theo Asia/Ho_Chi_Minh.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200))], responses: [new OA\Response(response: 200, description: 'XNT')])]
    public function inventoryXnt(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildXnt($request), $request, 'lines'));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt/export-excel', operationId: 'report_xnt_export', summary: 'Xuất XLSX/PDF Nhập-Xuất-Tồn', description: 'BR-EXP-01..05: cùng bộ lọc với /reports/inventory-xnt, audit, tên file có timestamp.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function inventoryXntExport(Request $request)
    {
        return $this->exportXnt($request, 'bao-cao-xnt');
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced', operationId: 'report_xnt_enhanced', summary: 'RPT-XNT-01 Nhập-Xuất-Tồn chi tiết theo movement type', description: 'Cùng builder và cùng contract với /reports/inventory-xnt; alias giữ cho client đang dùng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200))], responses: [new OA\Response(response: 200, description: 'XNT chi tiết')])]
    public function inventoryXntEnhanced(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildXnt($request), $request, 'lines'));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced/export-excel', operationId: 'report_xnt_enhanced_export', summary: 'Xuất XLSX/PDF Nhập-Xuất-Tồn chi tiết', description: 'Alias của /reports/inventory-xnt/export-excel, cùng bộ lọc và cùng quy tắc BR-EXP.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function inventoryXntEnhancedExport(Request $request)
    {
        return $this->exportXnt($request, 'xnt-chitiet');
    }

    // ── Document History ─────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/document-history', operationId: 'report_document_history', summary: 'RPT-IO-01 lịch sử phiếu nhập/xuất kèm tầng dòng', description: '§13.10 RPT-IO-01. Mỗi phiếu kèm rows[].lines[] gồm expected/scanned/posted; posted lấy từ movement authoritative (DEC-INV-001). Filter: date, SKU, status, agency recipient.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'doc_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound', 'all'])), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Lịch sử phiếu')])]
    public function documentHistory(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildDocumentHistory($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/document-history/export-excel', operationId: 'report_document_history_export', summary: 'Xuất XLSX/PDF lịch sử phiếu (bung theo dòng)', description: 'BR-EXP-01..05 + §13.10 RPT-IO-01: mỗi dòng file là document × line kèm expected/scanned/posted để đối soát "Sum authoritative movements = posted/issued qty".', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'doc_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound', 'all'])), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function documentHistoryExport(Request $request)
    {
        $report = $this->buildDocumentHistory($request);
        // §13.10 RPT-IO-01: file xuất theo dòng document×line để đối soát
        // "Sum authoritative movements = posted/issued qty".
        $rows = [];
        foreach ($report['rows'] as $row) {
            $lines = $row['lines'] === [] ? [null] : $row['lines'];
            foreach ($lines as $line) {
                $rows[] = [
                    $row['document_no'], $row['doc_type'], $row['warehouse_code'], $row['counterpart'],
                    $line['line_no'] ?? '', $line['product_code'] ?? '', $line['product_name'] ?? '',
                    $line['expected_qty'] ?? '', $line['scanned_qty'] ?? '', $line['posted_qty'] ?? '',
                    $row['document_date'], $row['status'], $row['posted_at'], $row['cancelled_at'],
                    $row['created_by_name'], $row['posted_by_name'], $row['note'],
                ];
            }
        }
        $headers = ['Mã phiếu', 'Loại', 'Kho', 'Đối tác/Nơi nhận', 'Dòng', 'Mã SKU', 'Tên SKU', 'Kế hoạch', 'Đã scan', 'Đã post', 'Ngày phiếu', 'Trạng thái', 'Posted at', 'Cancelled at', 'Người tạo', 'Người post', 'Ghi chú'];

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Lịch sử phiếu', $headers, $rows, "lich-su-phieu-{$report['period']['from_date']}-{$report['period']['to_date']}", 'document-history', $request);
        }

        return $this->exportRows($request, 'Lich su phieu', $headers, $rows, "lich-su-phieu-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'document-history');
    }

    // ── Outbound by Agency ───────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency', operationId: 'report_outbound_by_agency', summary: 'RPT-RECIPIENT-01 xuất kho theo nơi nhận', description: '§13.10 RPT-RECIPIENT-01: source_request_no, outbound_no, receiver/agency, address, contact đã mask, SKU/qty, posted_at. Agency chỉ là chiều nơi nhận, không phải chủ tồn. contact_masked chỉ hiện đầy đủ khi actor có warranty.pii.view.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Xuất theo nơi nhận')])]
    public function outboundByAgency(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildOutboundByAgency($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency/export-excel', operationId: 'report_outbound_by_agency_export', summary: 'Xuất XLSX/PDF báo cáo theo nơi nhận', description: 'BR-EXP-01..05: cùng bộ lọc màn hình; cột liên hệ giữ nguyên trạng thái mask theo quyền của actor.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function outboundByAgencyExport(Request $request)
    {
        $report = $this->buildOutboundByAgency($request);
        $rows = array_map(fn (array $row): array => [
            $row['source_request_no'], $row['agency_code'], $row['agency_name'], $row['address'], $row['contact_masked'],
            $row['product_code'], $row['product_name'], $row['outbound_no'],
            $row['document_date'], $row['posted_at'], $row['required_qty'], $row['scanned_qty'],
        ], $report['rows']);
        $headers = ['Phiếu toa', 'Mã nơi nhận', 'Nơi nhận', 'Địa chỉ', 'Liên hệ', 'Mã SKU', 'Tên SKU', 'Phiếu xuất', 'Ngày phiếu', 'Posted at', 'Yêu cầu', 'Đã xuất'];

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Xuất theo nơi nhận', $headers, $rows, "xuat-theo-noi-nhan-{$report['period']['from_date']}-{$report['period']['to_date']}", 'outbound-by-agency', $request);
        }

        return $this->exportRows($request, 'Xuat noi nhan', $headers, $rows, "xuat-theo-noi-nhan-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'outbound-by-agency');
    }

    // ── Movement Trace ───────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/movement-trace', operationId: 'report_movement_trace', summary: 'RPT-MOV-01 lịch sử nhập/xuất/Reversal', description: '§13.10 RPT-MOV-01: movement_id, source_document, movement_type, SKU/item, qty, actor, time, reversal_chain. Hiển thị gross + chain, không bao giờ ẩn movement gốc.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'code_value', in: 'query', description: 'Mã vật lý (Barcode/QR/RFID) của Item hoặc Container', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'source_type', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'source_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'])), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Movement trace')])]
    public function movementTrace(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildMovementTrace($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/movement-trace/export-excel', operationId: 'report_movement_trace_export', summary: 'Xuất XLSX/PDF movement trace', description: 'BR-EXP-01..05 + §13.10 RPT-MOV-01: movement_id là cột đầu để truy ngược ledger.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'code_value', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL']))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function movementTraceExport(Request $request)
    {
        $report = $this->buildMovementTrace($request);
        // §13.10 RPT-MOV-01: movement_id là khoá truy ngược, không được thiếu trong file.
        $rows = array_map(fn (array $row): array => [
            $row['id'], $row['occurred_at'], $row['movement_type'], $row['warehouse_code'], $row['product_code'], $row['product_name'],
            $row['stock_status'], $row['qty_delta'], $row['item_id'], $row['container_id'], $row['physical_code'],
            $row['actor'], $row['source_type'], $row['source_id'], $row['original_movement_id'], $row['correction_chain_id'],
        ], $report['rows']);
        $headers = ['Movement ID', 'Thời gian', 'Movement', 'Kho', 'Mã SKU', 'Tên SKU', 'Stock status', 'Qty delta', 'Item', 'Container', 'Physical code', 'Người thực hiện', 'Source type', 'Source id', 'Original movement', 'Correction chain'];

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Movement Trace', $headers, $rows, "movement-trace-{$report['period']['from_date']}-{$report['period']['to_date']}", 'movement-trace', $request);
        }

        return $this->exportRows($request, 'Movement Trace', $headers, $rows, "movement-trace-{$report['period']['from_date']}-{$report['period']['to_date']}.xlsx", 'movement-trace');
    }

    // ── Reconciliation ───────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-reconciliation', operationId: 'report_inventory_reconciliation', summary: 'Đối soát tồn kho với sổ giao dịch', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'only_mismatch', in: 'query', schema: new OA\Schema(type: 'boolean'))], responses: [new OA\Response(response: 200, description: 'Kết quả đối soát')])]
    public function inventoryReconciliation(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'in:'.implode(',', self::STOCK_STATUSES)],
            'only_mismatch' => ['nullable', 'boolean'],
        ]);
        $report = $this->buildInventoryBalance($request);
        $rows = collect($report['rows'])->map(function (array $row): array {
            // §7.5: ngoài Ledger↔Balance còn 3 check bắt buộc.
            // - Serial subledger: available = số Item_Instance IN_STOCK sellable.
            // - Quantity subledger: available >= tổng quantity trong container mapping
            //   (container không tạo bucket tồn riêng — Container integrity).
            $available = (float) $row['available_qty'];
            $serialQty = (float) $row['serial_qty'];
            $boxQty = (float) $row['box_qty'];
            $isSerialDimension = $serialQty > 0;

            $row['serial_subledger_status'] = ! $isSerialDimension || $row['stock_status'] !== 'AVAILABLE'
                ? 'NOT_APPLICABLE'
                : (abs($available - $serialQty) < 0.001 ? 'MATCH' : 'MISMATCH');
            $row['quantity_subledger_status'] = $boxQty <= 0
                ? 'NOT_APPLICABLE'
                : ($boxQty <= (float) $row['on_hand_qty'] + 0.001 ? 'MATCH' : 'MISMATCH');
            $row['container_integrity_status'] = $boxQty > (float) $row['on_hand_qty'] + 0.001
                ? 'MISMATCH'
                : 'MATCH';

            $checks = [$row['reconciliation_status'], $row['serial_subledger_status'], $row['quantity_subledger_status'], $row['container_integrity_status']];
            $row['reconciliation_overall'] = in_array('MISMATCH', $checks, true) ? 'MISMATCH' : 'MATCH';

            return $row;
        });
        if ($request->boolean('only_mismatch')) {
            $rows = $rows->where('reconciliation_overall', 'MISMATCH')->values();
        }

        $mismatches = $rows->where('reconciliation_overall', 'MISMATCH')->values();

        return $this->respondSuccess([
            'formula' => 'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)',
            'dimension' => ['warehouse_id', 'product_id', 'stock_status'],
            'checks' => ['ledger_to_balance', 'serial_subledger', 'quantity_subledger', 'container_integrity'],
            'summary' => [
                'rows_checked' => $rows->count(),
                'mismatch_count' => $rows->where('reconciliation_status', 'MISMATCH')->count(),
                'serial_subledger_mismatch_count' => $rows->where('serial_subledger_status', 'MISMATCH')->count(),
                'quantity_subledger_mismatch_count' => $rows->where('quantity_subledger_status', 'MISMATCH')->count(),
                'container_integrity_mismatch_count' => $rows->where('container_integrity_status', 'MISMATCH')->count(),
                'status' => $rows->where('reconciliation_status', 'MISMATCH')->isEmpty() ? 'PASS' : 'FAIL',
                'overall_status' => $mismatches->isEmpty() ? 'PASS' : 'FAIL',
            ],
            'rows' => $rows->all(),
            'mismatches' => $mismatches->all(),
        ]);
    }

    // ── Dashboard Quick ──────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/dashboard-quick', operationId: 'report_dashboard_quick', summary: 'Số liệu nhanh cho dashboard (WWHN-328: endpoint tổng hợp KPI, tái sử dụng — không tạo route mới)', description: 'Aggregate 1 lần gọi: KPI tồn/nhập/xuất theo occurred_at, số ca bảo hành theo status, cảnh báo đối soát. Mặc định period 30 ngày gần nhất khi bỏ trống from_date/to_date.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', description: 'Mặc định: hôm nay trừ 30 ngày', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', description: 'Phải >= from_date (422 BUSINESS_RULE_ERROR nếu vi phạm). Mặc định: hôm nay', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Dashboard KPIs', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'period', properties: [new OA\Property(property: 'from_date', type: 'string', format: 'date'), new OA\Property(property: 'to_date', type: 'string', format: 'date')], type: 'object'), new OA\Property(property: 'available_qty', type: 'number', description: 'SUM(inventory_balances.available_qty WHERE stock_status=AVAILABLE), snapshot hiện tại — không lọc theo period'), new OA\Property(property: 'receipt_qty', type: 'number', description: 'max(0, SUM(qty_delta)) của stock_movements.movement_type=RECEIPT trong [from_date 00:00:00, to_date 23:59:59] theo occurred_at'), new OA\Property(property: 'issue_qty', type: 'number', description: 'abs(min(0, SUM(qty_delta))) của movement_type=ISSUE trong period'), new OA\Property(property: 'component_issue_qty', type: 'number', description: 'abs(min(0, SUM(qty_delta))) của movement_type=COMPONENT_ISSUE trong period'), new OA\Property(property: 'reversal_net', type: 'number', description: 'SUM(qty_delta) của movement_type=REVERSAL trong period, có thể âm'), new OA\Property(property: 'warranty_cases_by_status', type: 'object', description: 'Map status → COUNT(*), toàn bộ warranty_case không lọc theo period', additionalProperties: new OA\AdditionalProperties(type: 'integer')), new OA\Property(property: 'reconciliation_warnings', type: 'array', description: 'Các dòng RPT-INV-01 reconciliation_status=MISMATCH tại thời điểm gọi', items: new OA\Items(type: 'object'))], type: 'object'), new OA\Property(property: 'meta', properties: [new OA\Property(property: 'request_id', type: 'string', format: 'uuid'), new OA\Property(property: 'timestamp', type: 'string', format: 'date-time')], type: 'object')])), new OA\Response(response: 401, description: 'UNAUTHENTICATED'), new OA\Response(response: 403, description: 'ACCESS_DENIED — thiếu permission report.view'), new OA\Response(response: 422, description: 'BUSINESS_RULE_ERROR — from_date/to_date không hợp lệ hoặc to_date < from_date')])]
    public function dashboardQuick(Request $request)
    {
        // period() tự validate/áp default + ném 422 khi to_date < from_date — luôn
        // chạy trước cache, không phụ thuộc cache hit/miss.
        [$from, $to] = $this->period($request);

        $key = CacheKey::of('warehouse:report:dashboard-quick', [
            'from' => $from,
            'to' => $to,
        ], $this->cacheService()->currentVersion('stock:version'));

        // Endpoint dashboard tốn nhất (4 aggregate query/lần gọi theo audit) — cache
        // toàn bộ payload, TTL ngắn ('dashboard' policy) + bump ngay khi có mutation
        // tồn kho qua `stock:version`. warranty_case không đi qua mutateBalance() nên
        // chỉ được bảo vệ bởi TTL ngắn, chấp nhận được cho 1 dashboard KPI (mục 9).
        $data = $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds('dashboard'),
            fn (): array => $this->buildDashboardQuick($from, $to)
        );

        return $this->respondSuccess($data);
    }

    /** @return array<string, mixed> */
    private function buildDashboardQuick(string $from, string $to): array
    {
        $movement = DB::table('stock_movements')
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->get(['movement_type', 'qty_delta']);

        $sum = fn (string $type): float => (float) $movement->where('movement_type', $type)->sum('qty_delta');
        $reconciliation = $this->buildInventoryBalance(new Request(['only_mismatch' => true]));
        $warranty = DB::table('warranty_case')
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'available_qty' => (float) DB::table('inventory_balances')->where('stock_status', 'AVAILABLE')->sum('available_qty'),
            'receipt_qty' => max(0, $sum('RECEIPT')),
            'issue_qty' => abs(min(0, $sum('ISSUE'))),
            'component_issue_qty' => abs(min(0, $sum('COMPONENT_ISSUE'))),
            'reversal_net' => $sum('REVERSAL'),
            'warranty_cases_by_status' => $warranty,
            'reconciliation_warnings' => collect($reconciliation['rows'])->where('reconciliation_status', 'MISMATCH')->values()->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  PRIVATE BUILDERS
    // ══════════════════════════════════════════════════════════

    private function buildInventoryBalance(Request $request): array
    {
        // §13.10 RPT-INV-01: filter SKU/category/status + as_of (inclusive). Validate
        // LUÔN chạy trước cache (kể cả cache hit) — không được để request tham số sai
        // "may mắn" trùng key với 1 request hợp lệ trước đó rồi bỏ qua validate.
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'in:'.implode(',', self::STOCK_STATUSES)],
            'as_of' => ['nullable', 'date'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);

        $key = CacheKey::of('warehouse:report:inventory-balance', [
            'f' => CacheKey::hashFilters($validated),
        ], $this->cacheService()->currentVersion('stock:version'));

        // Report tồn kho — cùng strategy với InventoryBalanceController::index (short
        // TTL 'report' + version `stock:version` bump ngay sau mutation). Trả về mảng
        // thuần (không Response/Paginator) nên cache trực tiếp toàn bộ kết quả an toàn.
        return $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds('report'),
            fn (): array => $this->buildInventoryBalanceUncached($request, $validated)
        );
    }

    private function cacheService(): CacheService
    {
        return app(CacheService::class);
    }

    private function buildInventoryBalanceUncached(Request $request, array $validated): array
    {
        $asOf = ! empty($validated['as_of']) ? Carbon::parse($validated['as_of'])->toDateString() : null;

        $balanceQuery = DB::table('inventory_balances as b')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->select('b.*', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name', 'p.category_id', 'pc.category_name');
        $movementQuery = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name', 'p.category_id', 'pc.category_name')
            ->selectRaw('SUM(m.qty_delta) AS ledger_qty')
            ->selectRaw('MAX(m.occurred_at) AS movement_updated_at')
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code', 'p.code', 'p.name', 'p.category_id', 'pc.category_name');

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $filter) {
            if ($request->filled($filter)) {
                $balanceQuery->where("b.{$filter}", $request->input($filter));
                $movementQuery->where("m.{$filter}", $request->input($filter));
            }
        }
        if (! empty($validated['category_id'])) {
            $balanceQuery->where('p.category_id', $validated['category_id']);
            $movementQuery->where('p.category_id', $validated['category_id']);
        }
        if ($asOf !== null) {
            // DEC-INV-001: ledger là source of truth nên as_of chỉ cắt được trên
            // movement; balance projection luôn là trạng thái hiện tại.
            $movementQuery->where('m.occurred_at', '<=', "{$asOf} 23:59:59");
        }
        if ($request->filled('keyword')) {
            $keyword = '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%';
            $balanceQuery->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$keyword]);
            });
            $movementQuery->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$keyword]);
            });
        }

        $balances = $balanceQuery->get()->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));
        $movements = $movementQuery->get()->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));
        $keys = $balances->keys()->merge($movements->keys())->unique()->values();

        // §13.10 RPT-INV-01: serial_qty/box_qty là subledger (Item_Instance /
        // container quantity mapping), location_hint non-authoritative (DR-LOC-001).
        $productIds = $balances->pluck('product_id')->merge($movements->pluck('product_id'))->filter()->unique()->values()->all();
        $serialCounts = $this->serialSubledgerCounts($productIds);
        $boxCounts = $this->boxSubledgerCounts($productIds);
        $locationHints = $this->locationHints($productIds);

        // §13.10 RPT-INV-01 "as_of inclusive": khi client chốt một mốc quá khứ thì
        // ledger đã bị cắt tới mốc đó còn inventory_balances luôn là hiện tại, nên
        // hiệu hai vế KHÔNG phải divergence. Ở chế độ as_of, báo cáo trở thành ảnh
        // chụp theo ledger (DEC-INV-001 — ledger là authoritative) và đối soát
        // Ledger↔Balance được đánh NOT_APPLICABLE thay vì báo MISMATCH giả.
        $isAsOfSnapshot = $asOf !== null && $asOf < now()->toDateString();

        $rows = $keys->map(function (string $key) use ($balances, $movements, $serialCounts, $boxCounts, $locationHints, $isAsOfSnapshot): array {
            $balance = $balances->get($key);
            $movement = $movements->get($key);
            $balanceExists = $balance !== null;
            $currentOnHand = (float) ($balance->on_hand_qty ?? $balance->quantity ?? 0);
            $ledger = (float) ($movement->ledger_qty ?? 0);
            $onHand = $isAsOfSnapshot ? $ledger : $currentOnHand;
            $variance = bcsub((string) $currentOnHand, (string) $ledger, 3);
            $isMismatch = ! $isAsOfSnapshot && bccomp($variance, '0', 3) !== 0;
            $warehouseId = (string) ($balance->warehouse_id ?? $movement->warehouse_id ?? '');
            $productId = (string) ($balance->product_id ?? $movement->product_id ?? '');
            $status = (string) ($balance->stock_status ?? $movement->stock_status ?? 'AVAILABLE');

            $mismatchReason = null;
            if ($isMismatch) {
                $mismatchReason = match (true) {
                    ! $balanceExists => 'BALANCE_MISSING_FOR_LEDGER',
                    $movement === null => 'LEDGER_MISSING_FOR_BALANCE',
                    default => 'QUANTITY_MISMATCH',
                };
            }

            $available = $isAsOfSnapshot
                ? ($status === 'AVAILABLE' ? $ledger : 0.0)
                : (float) ($balance->available_qty ?? ($status === 'AVAILABLE' ? $currentOnHand : 0));

            return [
                'id' => $key,
                'warehouse_id' => $warehouseId,
                'warehouse_code' => (string) ($balance->warehouse_code ?? $movement->warehouse_code ?? ''),
                'product_id' => $productId,
                'product_code' => (string) ($balance->product_code ?? $movement->product_code ?? ''),
                'product_name' => (string) ($balance->product_name ?? $movement->product_name ?? ''),
                'category_id' => $balance->category_id ?? $movement->category_id ?? null,
                'category' => (string) ($balance->category_name ?? $movement->category_name ?? ''),
                'stock_status' => $status,
                'serial_qty' => (float) ($serialCounts[$productId] ?? 0),
                'box_qty' => (float) ($boxCounts[$productId] ?? 0),
                'location_hint' => (string) ($locationHints[$productId] ?? ''),
                'on_hand_qty' => $onHand,
                'available_qty' => $available,
                'reserved_qty' => $isAsOfSnapshot ? 0.0 : (float) ($balance->reserved_qty ?? 0),
                'ledger_qty' => $ledger,
                'expected_qty' => $ledger,
                'current_on_hand_qty' => $currentOnHand,
                'variance' => (float) $variance,
                'delta_qty' => (float) $variance,
                'balance_exists' => $balanceExists,
                'mismatch_reason' => $mismatchReason,
                'reconciliation_status' => $isAsOfSnapshot ? 'NOT_APPLICABLE' : ($isMismatch ? 'MISMATCH' : 'MATCH'),
                'updated_at' => (string) ($balance->updated_at ?? $movement->movement_updated_at ?? ''),
            ];
        })->sortBy(['warehouse_code', 'product_code', 'stock_status'])->values();

        return [
            'data_as_of' => now()->toIso8601String(),
            'as_of' => $asOf,
            // Chỉ có ý nghĩa khi as_of nằm ở quá khứ; FE dùng cờ này để đổi nhãn cột
            // "Tồn kho" thành tồn theo ledger tại mốc đã chọn.
            'as_of_snapshot' => $isAsOfSnapshot,
            'summary' => [
                'total_rows' => $rows->count(),
                'total_on_hand' => $rows->sum('on_hand_qty'),
                'total_available' => $rows->sum('available_qty'),
                'mismatch_count' => $rows->where('reconciliation_status', 'MISMATCH')->count(),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * §7.1 subledger serial: số Item_Instance đang nằm trong kho theo SKU.
     *
     * @param  list<string>  $productIds
     * @return array<string, int>
     */
    private function serialSubledgerCounts(array $productIds): array
    {
        if ($productIds === [] || ! Schema::hasTable('item_instances')) {
            return [];
        }

        return DB::table('item_instances')
            ->whereIn('sku_id', $productIds)
            ->where('item_status', 'IN_STOCK')
            ->groupBy('sku_id')
            ->selectRaw('sku_id, COUNT(*) AS total')
            ->pluck('total', 'sku_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /**
     * §7.1 subledger quantity: tổng số lượng đang nằm trong container mapping active.
     *
     * @param  list<string>  $productIds
     * @return array<string, int>
     */
    private function boxSubledgerCounts(array $productIds): array
    {
        if ($productIds === [] || ! Schema::hasTable('container_items')) {
            return [];
        }

        return DB::table('container_items')
            ->whereIn('sku_id', $productIds)
            ->where('is_active', true)
            ->groupBy('sku_id')
            ->selectRaw('sku_id, COALESCE(SUM(quantity), 0) AS total')
            ->pluck('total', 'sku_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /**
     * DR-LOC-001: location_hint chỉ để hiển thị, không authoritative — lấy hint
     * của container chứa SKU gần nhất.
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private function locationHints(array $productIds): array
    {
        if ($productIds === [] || ! Schema::hasTable('containers')) {
            return [];
        }

        return DB::table('containers')
            ->whereIn('primary_sku_id', $productIds)
            ->whereNotNull('location_hint')
            ->orderByDesc('updated_at')
            ->get(['primary_sku_id', 'location_hint'])
            ->groupBy('primary_sku_id')
            ->map(fn ($rows): string => (string) $rows->first()->location_hint)
            ->all();
    }

    private function buildXnt(Request $request): array
    {
        [$from, $to] = $this->period($request, true);
        // §13.10 RPT-XNT-01: filter date period; SKU/category.
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        $fromTs = "{$from} 00:00:00";
        $toTs = "{$to} 23:59:59";

        $query = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.occurred_at', '<=', $toTs)
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name')
            ->selectRaw("SUM(CASE WHEN m.occurred_at < ? OR (m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'OPENING') THEN m.qty_delta ELSE 0 END) AS opening", [$fromTs, $fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'RECEIPT' THEN m.qty_delta ELSE 0 END) AS receipt", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'ISSUE' THEN -m.qty_delta ELSE 0 END) AS issue", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'COMPONENT_ISSUE' THEN -m.qty_delta ELSE 0 END) AS component_issue", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'REVERSAL' THEN m.qty_delta ELSE 0 END) AS reversal_net", [$fromTs, $toTs])
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status', 'w.code', 'p.code', 'p.name');
        if (! empty($validated['category_id'])) {
            $query->where('p.category_id', $validated['category_id']);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('m.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('m.product_id', $request->input('product_id'));
        }
        if ($request->filled('keyword')) {
            $kw = '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%';
            $query->where(function ($q) use ($kw): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$kw])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$kw]);
            });
        }

        $lines = $query->get()->map(function ($row): array {
            $opening = (float) $row->opening;
            $receipt = (float) $row->receipt;
            $issue = (float) $row->issue;
            $component = (float) $row->component_issue;
            $reversal = (float) $row->reversal_net;

            return [
                'warehouse_code' => (string) $row->warehouse_code,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'stock_status' => (string) $row->stock_status,
                'opening' => $opening,
                'receipt' => $receipt,
                'issue' => $issue,
                'component_issue' => $component,
                'reversal_net' => $reversal,
                'closing' => $opening + $receipt - $issue - $component + $reversal,
            ];
        })->sortBy(['warehouse_code', 'product_code', 'stock_status'])->values()->all();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'formula' => 'Closing = Opening + Receipt - Issue - Component Issue + Reversal',
            'lines' => $lines,
        ];
    }

    private function exportXnt(Request $request, string $prefix)
    {
        $report = $this->buildXnt($request);
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['stock_status'], $row['opening'],
            $row['receipt'], $row['issue'], $row['component_issue'], $row['reversal_net'], $row['closing'],
        ], $report['lines']);

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Nhập-Xuất-Tồn', ['Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Đầu kỳ', 'Nhập', 'Xuất', 'Xuất linh kiện', 'Hoàn đảo', 'Cuối kỳ'], $rows, "{$prefix}-{$report['from_date']}-{$report['to_date']}", 'inventory-xnt', $request);
        }

        return $this->exportRows($request, 'XNT', ['Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Đầu kỳ', 'Nhập', 'Xuất', 'Xuất linh kiện', 'Hoàn đảo', 'Cuối kỳ'], $rows, "{$prefix}-{$report['from_date']}-{$report['to_date']}.xlsx", 'inventory-xnt');
    }

    private function buildDocumentHistory(Request $request): array
    {
        [$from, $to] = $this->period($request);
        // §13.10 RPT-IO-01 filters: date, SKU, status, agency recipient.
        $validated = $request->validate([
            'doc_type' => ['nullable', 'in:inbound,outbound,all'],
            'status' => ['nullable', 'in:DRAFT,SCANNING,POSTED,CANCELLED'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'product_id' => ['nullable', 'uuid'],
            'agency_id' => ['nullable', 'uuid'],
        ]);
        $docType = $validated['doc_type'] ?? 'all';
        $query = DB::table('stock_documents as sd')
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'sd.src_warehouse_id')
            ->leftJoin('warehouses as dw', 'dw.id', '=', 'sd.dst_warehouse_id')
            ->leftJoin('agencies as a', 'a.agency_id', '=', 'sd.agency_id')
            ->leftJoin('app_user as cu', 'cu.user_id', '=', 'sd.created_by')
            ->leftJoin('app_user as pu', 'pu.user_id', '=', 'sd.posted_by')
            ->whereIn('sd.doc_type', ['receipt', 'issue'])
            ->whereBetween('sd.doc_date', [$from, $to])
            ->select('sd.*', 'sw.code as src_warehouse_code', 'dw.code as dst_warehouse_code', 'a.agency_name',
                'cu.display_name as creator_name', 'pu.display_name as poster_name');
        if ($docType !== 'all') {
            $query->where('sd.doc_type', $docType === 'inbound' ? 'receipt' : 'issue');
        }
        if (! empty($validated['status'])) {
            $query->where('sd.status', $validated['status']);
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(sd.doc_no) LIKE ?', [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(sd.source_name, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(sd.recipient_name, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_name, '')) LIKE ?", [$keyword]);
            });
        }
        if (! empty($validated['product_id'])) {
            $query->whereExists(function ($q) use ($validated): void {
                $q->selectRaw('1')->from('stock_document_lines as sdl')
                    ->whereColumn('sdl.stock_document_id', 'sd.id')
                    ->where('sdl.product_id', $validated['product_id']);
            });
        }
        if (! empty($validated['agency_id'])) {
            $query->where('sd.agency_id', $validated['agency_id']);
        }
        $documents = $query->orderByDesc('sd.doc_date')->orderByDesc('sd.created_at')->get();

        // §13.10 RPT-IO-01 yêu cầu tầng line: expected/required, scanned/matched,
        // posted/issued. posted_qty lấy từ movement authoritative (DEC-INV-001)
        // để "Sum authoritative movements = posted/issued qty" kiểm chứng được.
        $documentIds = $documents->pluck('id')->all();
        $linesByDocument = $this->documentHistoryLines($documentIds, $validated['product_id'] ?? null);

        $rows = $documents->map(fn ($d): array => [
            'id' => (string) $d->id,
            'document_no' => (string) $d->doc_no,
            'doc_type' => $d->doc_type === 'receipt' ? 'INBOUND' : 'OUTBOUND',
            'warehouse_code' => (string) ($d->doc_type === 'receipt' ? $d->dst_warehouse_code : $d->src_warehouse_code),
            'counterpart' => (string) ($d->doc_type === 'receipt' ? ($d->source_name ?? '') : ($d->agency_name ?: ($d->recipient_name ?? ''))),
            'document_date' => (string) $d->doc_date,
            'status' => strtoupper((string) $d->status),
            'posted_at' => $d->posted_at ?? null,
            'cancelled_at' => $d->cancelled_at ?? null,
            'created_by_name' => $d->creator_name ?? null,
            'posted_by_name' => $d->poster_name ?? null,
            'note' => $d->note ?? null,
            'lines' => $linesByDocument[(string) $d->id] ?? [],
        ])->all();
        $statuses = collect($rows)->countBy('status');

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'doc_type_filter' => $docType,
            'summary' => [
                'total' => count($rows), 'draft' => (int) ($statuses['DRAFT'] ?? 0), 'scanning' => (int) ($statuses['SCANNING'] ?? 0),
                'posted' => (int) ($statuses['POSTED'] ?? 0), 'cancelled' => (int) ($statuses['CANCELLED'] ?? 0),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $documentIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function documentHistoryLines(array $documentIds, ?string $productId): array
    {
        if ($documentIds === []) {
            return [];
        }

        $postedByLine = DB::table('stock_movements')
            ->whereIn('stock_document_id', $documentIds)
            ->whereIn('movement_type', ['RECEIPT', 'ISSUE'])
            ->groupBy('stock_document_id', 'product_id')
            ->selectRaw('stock_document_id, product_id, SUM(ABS(qty_delta)) AS posted_qty')
            ->get()
            ->keyBy(fn ($row): string => $row->stock_document_id.'|'.$row->product_id);

        $query = DB::table('stock_document_lines as sdl')
            ->leftJoin('products as p', 'p.id', '=', 'sdl.product_id')
            ->whereIn('sdl.stock_document_id', $documentIds)
            ->select('sdl.id', 'sdl.stock_document_id', 'sdl.line_no', 'sdl.product_id', 'sdl.qty_planned', 'sdl.scanned_qty', 'p.code as product_code', 'p.name as product_name')
            ->orderBy('sdl.line_no');
        if ($productId !== null && $productId !== '') {
            $query->where('sdl.product_id', $productId);
        }

        return $query->get()
            ->groupBy('stock_document_id')
            ->map(fn (Collection $lines): array => $lines->map(fn ($line): array => [
                'id' => (string) $line->id,
                'line_no' => (int) $line->line_no,
                'product_id' => (string) $line->product_id,
                'product_code' => (string) ($line->product_code ?? ''),
                'product_name' => (string) ($line->product_name ?? ''),
                'expected_qty' => (float) $line->qty_planned,
                'scanned_qty' => (float) $line->scanned_qty,
                'posted_qty' => (float) ($postedByLine[$line->stock_document_id.'|'.$line->product_id]->posted_qty ?? 0),
            ])->values()->all())
            ->all();
    }

    private function buildOutboundByAgency(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'agency_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        // §13.10 RPT-RECIPIENT-01: cột bắt buộc gồm source_request_no, address và
        // contact đã mask — source_request_no là khoá trace về toa nguồn.
        $query = DB::table('stock_documents as sd')
            ->join('stock_document_lines as l', 'l.stock_document_id', '=', 'sd.id')
            ->leftJoin('agencies as a', 'a.agency_id', '=', 'sd.agency_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('outbound_requests as orq', 'orq.id', '=', 'sd.outbound_request_id')
            ->where('sd.doc_type', 'issue')->where('sd.status', 'POSTED')
            ->whereBetween('sd.doc_date', [$from, $to])
            ->select('sd.id', 'sd.doc_no', 'sd.doc_date', 'sd.posted_at', 'sd.agency_id', 'sd.recipient_name', 'sd.recipient_address', 'a.agency_code', 'a.agency_name', 'a.phone as agency_phone', 'orq.request_no as source_request_no', 'p.code as product_code', 'p.name as product_name', 'l.product_id', 'l.qty_planned', 'l.scanned_qty');
        if (! empty($validated['agency_id'])) {
            $query->where('sd.agency_id', $validated['agency_id']);
        }
        if (! empty($validated['product_id'])) {
            $query->where('l.product_id', $validated['product_id']);
        }
        if (! empty($validated['keyword'])) {
            $kw = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($q) use ($kw): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$kw])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$kw])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_name, '')) LIKE ?", [$kw])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_code, '')) LIKE ?", [$kw]);
            });
        }
        // BR-EXP-01..05 + Annex D: contact là PII, mặc định mask; chỉ actor có
        // warranty.pii.view mới thấy đầy đủ.
        $showContact = (bool) $request->user()?->hasPermission('warranty.pii.view');
        $rows = $query->orderByDesc('sd.doc_date')->get()->map(fn ($r): array => [
            'agency_code' => (string) ($r->agency_code ?? 'DIRECT'),
            'agency_name' => (string) ($r->agency_name ?? $r->recipient_name ?? 'Người nhận trực tiếp'),
            'source_request_no' => (string) ($r->source_request_no ?? ''),
            'address' => (string) ($r->recipient_address ?? ''),
            'contact_masked' => $showContact
                ? (string) ($r->agency_phone ?? '')
                : WarrantyPiiMasker::phone((string) ($r->agency_phone ?? '')),
            'product_code' => (string) ($r->product_code ?? ''),
            'product_name' => (string) ($r->product_name ?? ''),
            'outbound_no' => (string) $r->doc_no,
            'document_date' => (string) $r->doc_date,
            'posted_at' => (string) $r->posted_at,
            'required_qty' => (float) $r->qty_planned,
            'scanned_qty' => (float) $r->scanned_qty,
            '_document_id' => (string) $r->id,
        ])->all();
        $collection = collect($rows);
        $byAgency = $collection->groupBy(fn ($r) => $r['agency_code'].'|'.$r['agency_name'])->map(function (Collection $items): array {
            $first = $items->first();

            return [
                'agency_code' => $first['agency_code'],
                'agency_name' => $first['agency_name'],
                'document_count' => $items->pluck('_document_id')->unique()->count(),
                'total_issued_qty' => (float) $items->sum('scanned_qty'),
                'sku_count' => $items->pluck('product_code')->unique()->count(),
            ];
        })->values()->all();
        $publicRows = $collection->map(function (array $row): array {
            unset($row['_document_id']);

            return $row;
        })->all();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_agencies' => count($byAgency),
                'total_documents' => $collection->pluck('_document_id')->unique()->count(),
                'total_issued_qty' => (float) $collection->sum('scanned_qty'),
            ],
            'by_agency' => $byAgency,
            'rows' => $publicRows,
        ];
    }

    private function buildMovementTrace(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'], 'item_id' => ['nullable', 'uuid'],
            'movement_type' => ['nullable', 'in:'.implode(',', self::MOVEMENT_TYPES)], 'code_value' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:80'], 'source_id' => ['nullable', 'uuid'],
        ]);
        // §13.10 RPT-MOV-01 yêu cầu cột actor — join app_user như document-history.
        $query = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('app_user as au', 'au.user_id', '=', 'm.created_by')
            ->whereBetween('m.occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('m.*', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name', 'au.username as actor_username', 'au.display_name as actor_display_name');
        foreach (['warehouse_id', 'product_id', 'item_id', 'movement_type', 'source_type', 'source_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where("m.{$filter}", $validated[$filter]);
            }
        }
        if (! empty($validated['code_value'])) {
            $objectIds = DB::table('physical_codes')->where('code_value', $validated['code_value'])->pluck('assigned_object_id')->filter()->all();
            if ($objectIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($objectIds): void {
                    $q->whereIn('m.item_id', $objectIds)->orWhereIn('m.container_id', $objectIds);
                });
            }
        }
        // stock_movements là ledger append-only nên có thể rất lớn: phân trang ở
        // tầng SQL, còn summary dùng aggregate riêng để vẫn phản ánh toàn bộ kỳ.
        $pagination = null;
        $aggregate = null;
        if ($request->filled('per_page')) {
            $perPage = min(max(1, (int) $request->input('per_page', self::DEFAULT_PER_PAGE)), self::MAX_PER_PAGE);
            $page = max(1, (int) $request->input('page', 1));
            // select() thay thế danh sách cột (selectRaw chỉ nối thêm, sẽ vỡ vì
            // m.* không nằm trong GROUP BY).
            $aggregate = (clone $query)->reorder()->select(DB::raw(
                "COUNT(*) AS total_movements, COALESCE(SUM(m.qty_delta), 0) AS net_effect, SUM(CASE WHEN m.movement_type = 'REVERSAL' THEN 1 ELSE 0 END) AS reversal_count"
            ))->first();
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($aggregate->total_movements ?? 0),
                'last_page' => max(1, (int) ceil(((int) ($aggregate->total_movements ?? 0)) / $perPage)),
            ];
            $query->forPage($page, $perPage);
        }

        $movements = $query->orderByDesc('m.occurred_at')->orderByDesc('m.id')->get();
        $objectIds = $movements->pluck('item_id')->merge($movements->pluck('container_id'))->filter()->unique()->values()->all();
        $codeMap = $objectIds === [] ? collect() : DB::table('physical_codes')->whereIn('assigned_object_id', $objectIds)->where('status', 'ACTIVE')->orderBy('created_at')->get(['assigned_object_id', 'code_value'])->groupBy('assigned_object_id')->map(fn ($rows) => (string) $rows->first()->code_value);
        $rows = $movements->map(fn ($m): array => [
            'id' => (string) $m->id,
            'occurred_at' => (string) $m->occurred_at,
            'movement_type' => (string) $m->movement_type,
            'warehouse_id' => (string) $m->warehouse_id,
            'warehouse_code' => (string) ($m->warehouse_code ?? ''),
            'product_id' => (string) $m->product_id,
            'product_code' => (string) ($m->product_code ?? ''),
            'product_name' => (string) ($m->product_name ?? ''),
            'stock_status' => (string) $m->stock_status,
            'qty_delta' => (float) $m->qty_delta,
            'item_id' => $m->item_id ?? null,
            'container_id' => $m->container_id ?? null,
            'physical_code' => (string) ($codeMap[(string) ($m->item_id ?? $m->container_id ?? '')] ?? ''),
            'source_type' => (string) ($m->source_type ?? ''),
            'source_id' => $m->source_id ?? null,
            'actor' => (string) ($m->actor_display_name ?? $m->actor_username ?? ''),
            'original_movement_id' => $m->original_movement_id ?? null,
            'correction_chain_id' => $m->correction_chain_id ?? null,
        ])->all();
        $collection = collect($rows);

        $report = [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'formula' => 'net_effect = SUM(qty_delta); reversal/correction append-only, không sửa movement gốc',
            'summary' => [
                'total_movements' => (int) ($aggregate->total_movements ?? count($rows)),
                'net_effect' => (float) ($aggregate->net_effect ?? $collection->sum('qty_delta')),
                'reversal_count' => (int) ($aggregate->reversal_count ?? $collection->where('movement_type', 'REVERSAL')->count()),
            ],
            'rows' => $rows,
        ];
        if ($pagination !== null) {
            $report['pagination'] = $pagination;
        }

        return $report;
    }

    // ══════════════════════════════════════════════════════════
    //  UTILITIES
    // ══════════════════════════════════════════════════════════

    private function period(Request $request, bool $required = false): array
    {
        $rules = [
            'from_date' => [$required ? 'required' : 'nullable', 'date'],
            'to_date' => [$required ? 'required' : 'nullable', 'date', 'after_or_equal:from_date'],
        ];
        $validated = $request->validate($rules);
        $from = (string) ($validated['from_date'] ?? $request->input('from') ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? $request->input('to') ?? now()->toDateString());

        return [$from, $to];
    }

    private function paginateReport(array $report, Request $request, string $rowsKey = 'rows'): array
    {
        // Builder đã phân trang ở tầng SQL thì không cắt lại trong bộ nhớ.
        if (isset($report['pagination'])) {
            return $report;
        }
        if (! $request->filled('per_page')) {
            return $report;
        }
        $perPage = min(max(1, (int) $request->input('per_page', self::DEFAULT_PER_PAGE)), self::MAX_PER_PAGE);
        $page = max(1, (int) $request->input('page', 1));
        $allRows = collect($report[$rowsKey]);
        $total = $allRows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $report[$rowsKey] = $allRows->forPage($page, $perPage)->values()->all();
        $report['pagination'] = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ];

        return $report;
    }

    private function dimensionKey(mixed $warehouseId, mixed $productId, mixed $status): string
    {
        return (string) $warehouseId.'|'.(string) $productId.'|'.(string) $status;
    }

    private function exportRows(Request $request, string $sheetTitle, array $headers, array $rows, string $fileName, string $reportKey)
    {
        $fileName = ReportExportFileName::stamp($fileName);

        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(
                422,
                'REPORT_EXPORT_ROW_LIMIT_EXCEEDED',
                'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.',
                ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]
            );
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, "A{$rowIndex}");
            $rowIndex++;
        }
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        AuditLogger::record(
            action: 'REPORT_EXPORTED',
            objectType: 'report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => $reportKey, 'row_count' => count($rows), 'filters' => $request->query()],
        );
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(fn () => $writer->save('php://output'), $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(string $title, array $headers, array $rows, string $filePrefix, string $reportKey, Request $request)
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.', ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]);
        }

        AuditLogger::record(
            action: 'REPORT_EXPORTED',
            objectType: 'report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => $reportKey, 'format' => 'pdf', 'row_count' => count($rows), 'filters' => $request->query()],
        );

        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #333;padding:4px 6px;text-align:left}th{background:#e0e0e0;font-weight:bold}h1{font-size:16px;margin:0 0 5px}p{margin:2px 0;font-size:9px;color:#666}</style></head><body>';
        $html .= '<h1>'.htmlspecialchars($title).'</h1>';
        $html .= '<p>Xuất lúc: '.now()->format('d/m/Y H:i:s').' · Tổng dòng: '.count($rows).'</p>';
        $html .= '<table><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>'.htmlspecialchars((string) $h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.htmlspecialchars((string) ($cell ?? '')).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', count($headers) > 8 ? 'landscape' : 'portrait');
        $dompdf->render();
        $fileName = ReportExportFileName::stamp("{$filePrefix}.pdf");

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    private function excelSafe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
