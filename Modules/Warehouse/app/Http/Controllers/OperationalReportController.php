<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheTtl;
use App\Support\Database\SchemaCache;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\ReportExportFileName;
use App\Support\ReportExportMemory;
use App\Support\ReportPdfRowLimit;
use App\Support\Validation\InputLimits;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\Concerns\CachesReportPayload;
use Modules\Warehouse\Jobs\GenerateReportExportJob;
use Modules\Warehouse\Models\ReportExportJob;
use Modules\Warehouse\Services\LedgerReconciliationService;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use Modules\Warehouse\Support\ReportExportFileStore;
use Modules\Warehouse\Support\ReportExportJobRunner;
use Modules\Warehouse\Support\SkuInformationCompleteness;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyOverdue;
use Modules\Warehouse\Support\WarrantyReportTime;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
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
    use CachesReportPayload;
    use ResolvesActorOrNull { actorIdOrNull as currentActorId; }

    private const STOCK_STATUSES = ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'];

    private const MOVEMENT_TYPES = ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'];

    /**
     * WWHN-328: dashboard-quick chỉ phát hành 4 KPI luồng; OPENING không nằm trong
     * contract nên loại ngay ở WHERE thay vì gom rồi bỏ.
     */
    private const DASHBOARD_MOVEMENT_TYPES = ['RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'];

    /**
     * WWHN-328: trần số dòng MISMATCH nhúng vào payload dashboard. Tổng thật đi qua
     * `reconciliation_warning_count`; danh sách đầy đủ vẫn ở /reports/inventory-balance.
     * Không có trần thì một lần lệch đối soát diện rộng sẽ thổi payload dashboard lên
     * to bằng cả báo cáo tồn kho.
     */
    private const DASHBOARD_WARNING_LIMIT = 50;

    /**
     * WWHN-240 / BR-REV-04, BR-REV-08: correction chain là append-only và một Reversal có
     * thể tham chiếu chính một Reversal trước đó. Trần độ sâu để việc đi ngược chuỗi không
     * lặp vô hạn nếu dữ liệu cũ có vòng.
     */
    private const MAX_CORRECTION_CHAIN_DEPTH = 32;

    /** WWHN-240: khung rỗng của breakdown reversal theo movement_type của giao dịch gốc. */
    private const EMPTY_XNT_REVERSAL = ['RECEIPT' => 0.0, 'ISSUE' => 0.0, 'COMPONENT_ISSUE' => 0.0, 'UNLINKED' => 0.0];

    /**
     * WWHN-240 / BR-RPT-01..07 ("hiển thị gross/net") + BR-REV-11 ("report hiển thị movement
     * gốc, toàn bộ Reversal chain và net effect"): khai báo ngay trong payload cột nào là
     * GROSS, cột nào là REVERSAL và cột nào là NET, để FE/BA/QA đọc cùng một baseline thay
     * vì suy diễn từ tên cột.
     *
     * @var array<string, string>
     */
    private const XNT_COLUMN_SEMANTICS = [
        'opening' => 'NET — tồn đầu kỳ, đã gồm mọi reversal đã Post trước from_date',
        'receipt' => 'GROSS — bí danh tương thích của receipt_gross',
        'receipt_gross' => 'GROSS — tổng RECEIPT đã Post trong kỳ, chưa trừ reversal',
        'receipt_reversal' => 'REVERSAL — lượng RECEIPT trong kỳ đã bị đảo, quy về chiều dương của cột Nhập',
        'receipt_net' => 'NET — receipt_gross - receipt_reversal',
        'issue' => 'GROSS — bí danh tương thích của issue_gross',
        'issue_gross' => 'GROSS — tổng ISSUE đã Post trong kỳ, chưa trừ reversal',
        'issue_reversal' => 'REVERSAL — lượng ISSUE trong kỳ đã bị đảo, quy về chiều dương của cột Xuất',
        'issue_net' => 'NET — issue_gross - issue_reversal',
        'component_issue' => 'GROSS — bí danh tương thích của component_issue_gross',
        'component_issue_gross' => 'GROSS — tổng COMPONENT_ISSUE đã Post trong kỳ',
        'component_issue_reversal' => 'REVERSAL — lượng COMPONENT_ISSUE đã bị đảo, quy về chiều dương',
        'component_issue_net' => 'NET — component_issue_gross - component_issue_reversal',
        'reversal_unlinked_net' => 'REVERSAL — qty_delta của reversal không truy được movement gốc (dữ liệu migrate/OPENING); vẫn vào closing để báo cáo khớp ledger',
        'reversal_net' => 'REVERSAL — tổng qty_delta có dấu của mọi REVERSAL trong kỳ; bằng -receipt_reversal + issue_reversal + component_issue_reversal + reversal_unlinked_net',
        'closing' => 'NET — opening + receipt_net - issue_net - component_issue_net + reversal_unlinked_net',
    ];

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
    /**
     * KHONG cache — co chu dich, khong phai bo sot.
     *
     * Danh sach nay la hang so viet thang trong ma nguon, khong cham co so du lieu.
     * Dua no qua Valkey chi them mot vong di-ve mang de doi lay dung thu vua doc
     * duoc tu bo nho. Cache chi dang gia khi no thay the duoc mot phan viec that.
     */
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
    #[OA\Get(path: '/api/v1/reports/inventory-ledger', operationId: 'getInventoryLedgerReport', summary: 'Annex C getInventoryLedgerReport — sổ ledger authoritative', description: 'REQ-SRS-031 / DEC-INV-001. Cùng contract với RPT-MOV-01: từng movement đã POSTED, append-only, kèm reversal chain.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'code_value', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'])), new OA\Parameter(name: 'source_type', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'source_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'ReportRowPage — sổ ledger')])]
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
    #[OA\Post(path: '/api/v1/reports/export', operationId: 'createReportExport', summary: 'Annex C createReportExport — tạo job xuất báo cáo', description: 'REQ-SRS-032 / BR-EXP-01..05. Bắt buộc Idempotency-Key; retry cùng key trả lại đúng job cũ (Idempotent-Replay: true). Export dùng đúng filter/scope của màn hình và luôn được audit; tên file trả về có timestamp. §13.8: với report_code=warranty-defects, filters.reason kèm quyền thiếu warranty.pii.export → 403 PERMISSION_DENIED và filters.reason ngắn hơn 5 ký tự → 422 PII_EXPORT_REASON_INVALID, kiểm ngay khi tạo job nên không sinh ExportJob lẫn audit; bỏ trống reason thì file vẫn xuất ở dạng đã mask. Checklist "Hiệu năng và triển khai" 27/08: job chạy NỀN qua GenerateReportExportJob (không còn READY giả) — client poll GET /reports/export/{export_job_id} (status_url) tới khi status=READY (có download_url) hoặc FAILED (có error_code/error_message).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 200))], requestBody: new OA\RequestBody(required: true, description: 'ReportExportRequest', content: new OA\JsonContent(required: ['report_code'], properties: [new OA\Property(property: 'report_code', type: 'string', enum: ['inventory-balance', 'inventory-ledger', 'inventory-xnt', 'document-history', 'movement-trace', 'import-match', 'label-log', 'warranty-defects', 'outbound-by-agency'], example: 'inventory-balance'), new OA\Property(property: 'format', type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx', example: 'xlsx'), new OA\Property(property: 'filters', type: 'object', description: 'Bộ lọc y hệt màn hình đang xem', additionalProperties: true, example: ['from_date' => '2026-08-01', 'to_date' => '2026-08-21'])])), responses: [new OA\Response(response: 202, description: 'ExportJob (PENDING) — poll status_url', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'export_job_id', type: 'string', format: 'uuid'), new OA\Property(property: 'report_code', type: 'string'), new OA\Property(property: 'format', type: 'string'), new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'PROCESSING', 'READY', 'FAILED']), new OA\Property(property: 'row_count', type: 'integer', nullable: true), new OA\Property(property: 'status_url', type: 'string'), new OA\Property(property: 'download_url', type: 'string', nullable: true), new OA\Property(property: 'requested_at', type: 'string', format: 'date-time')], type: 'object')])), new OA\Response(response: 401, description: 'Chưa xác thực'), new OA\Response(response: 403, description: 'PERMISSION_DENIED — thiếu warranty.pii.export khi export warranty-defects kèm filters.reason'), new OA\Response(response: 422, description: 'VALIDATION_ERROR (report_code/format không hợp lệ, Idempotency-Key sai độ dài), hoặc PII_EXPORT_REASON_INVALID (filters.reason ngắn hơn 5 ký tự)'), new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key')])]
    public function createExport(Request $request)
    {
        $validated = $request->validate([
            'report_code' => ['required', 'string', Rule::in(array_keys(self::EXPORT_DOWNLOAD_PATHS))],
            'format' => ['nullable', 'in:xlsx,pdf'],
            'filters' => ['nullable', 'array', 'max:500'],
        ]);
        $key = IdempotencyKeyGuard::require($request);
        $reportCode = (string) $validated['report_code'];
        $format = (string) ($validated['format'] ?? 'xlsx');
        // BR-EXP-01 "export dùng cùng filter/scope với màn hình": filter đến trong
        // body nên phải merge vào input trước khi builder chạy, nếu không job đếm
        // dòng trên TOÀN bộ dữ liệu (và báo cáo yêu cầu from_date sẽ 422 oan).
        $filters = $this->exportFilters($validated['filters'] ?? []);
        $this->assertPiiExportAllowed($request, $reportCode, $filters);
        $actorId = $this->currentActorId();

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'report_export_'.$reportCode,
            $actorId,
            IdempotencyStore::requestHash($request),
            function () use ($request, $reportCode, $format, $filters, $actorId): array {
                $jobId = (string) Str::uuid();

                AuditLogger::record(
                    action: 'REPORT_EXPORT_REQUESTED',
                    objectType: 'report_export',
                    objectId: $jobId,
                    actorId: $actorId,
                    requestId: $request->attributes->get('request_id'),
                    afterData: ['report_code' => $reportCode, 'format' => $format, 'filters' => $filters],
                );

                // Hiệu năng và triển khai (checklist 27/08): trước đây row_count được tính
                // NGAY ở đây bằng cách chạy builder đồng bộ (một trong hai lần chạy builder
                // của cùng một export, lần còn lại là lúc GET download_url) — đúng nguồn
                // chậm mà checklist mô tả. Giờ toàn bộ builder + render chỉ chạy MỘT lần,
                // trong GenerateReportExportJob; row_count/download_url có ở GET status bên
                // dưới khi job xong.
                ReportExportJob::query()->create([
                    'export_job_id' => $jobId,
                    'report_code' => $reportCode,
                    'format' => $format,
                    'filters' => $filters,
                    'requested_by' => $actorId,
                    'status' => 'PENDING',
                ]);
                GenerateReportExportJob::dispatch($jobId, $reportCode, $format, $filters, $actorId);

                return [202, [
                    'success' => true,
                    'message' => 'Đã tạo yêu cầu export; theo dõi qua status_url rồi tải qua download_url khi status=READY.',
                    'data' => [
                        'export_job_id' => $jobId,
                        'report_code' => $reportCode,
                        'format' => $format,
                        'status' => 'PENDING',
                        'row_count' => null,
                        'filters' => $filters,
                        'status_url' => "/api/v1/reports/export/{$jobId}",
                        'download_url' => null,
                        'requested_at' => now()->toIso8601String(),
                    ],
                ]];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    #[OA\Get(path: '/api/v1/reports/export/{export_job_id}', operationId: 'getReportExportStatus', summary: 'Trạng thái job export báo cáo nền', description: 'Poll tới khi status=READY (có download_url) hoặc FAILED (có error_code/error_message). `filters` trả lại đúng bộ lọc đã gửi khi tạo job (BR-EXP-01: file luôn dùng đúng filter/scope của lần request đó). Chỉ actor đã tạo job mới đọc được — job của người khác trả 404 y hệt job không tồn tại (fail-closed). WWHN-343: job KHÔNG BAO GIỜ đứng PENDING vô thời hạn — nếu worker hàng đợi không nhặt job sau `wms_export.pickup_grace_seconds` (mặc định 20s) thì chính lần poll này chạy export đồng bộ rồi trả READY, và nếu tiến trình đang chạy chết giữa chừng quá `processing_timeout_seconds` (mặc định 180s) thì job được chốt FAILED + error_code=REPORT_EXPORT_STALLED.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'export_job_id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Trạng thái job'), new OA\Response(response: 404, description: 'Không tìm thấy job (hoặc job của actor khác)')])]
    public function exportStatus(string $exportJobId)
    {
        $job = $this->ownedExportJob($exportJobId);
        if ($job === null) {
            return $this->respondNotFound('Không tìm thấy export job.');
        }

        // WWHN-343: endpoint này KHÔNG chỉ đọc. Nếu worker hàng đợi không nhặt job trong
        // thời gian ân hạn (container `queue` chết/không được deploy), chính lần poll này
        // chạy export rồi chốt READY hoặc FAILED. Không có bước đó thì câu trả lời mãi là
        // `PENDING` + `download_url: null` + `error_code: null` — FE không tải được file
        // mà cũng không có lý do nào để hiển thị.
        $job = ReportExportJobRunner::ensureProgress($job);

        return $this->respondSuccess([
            'export_job_id' => $job->export_job_id,
            'report_code' => $job->report_code,
            'format' => $job->format,
            'status' => $job->status,
            'row_count' => $job->row_count,
            'filters' => $job->filters,
            'download_url' => $job->status === 'READY' ? "/api/v1/reports/export/{$job->export_job_id}/download" : null,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
        ]);
    }

    #[OA\Get(path: '/api/v1/reports/export/{export_job_id}/download', operationId: 'downloadReportExport', summary: 'Tải file export báo cáo đã READY', description: 'Chỉ hợp lệ khi job status=READY và chưa quá expires_at (24h); ngoài ra trả 404 y hệt job không tồn tại.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'export_job_id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF'), new OA\Response(response: 404, description: 'Job không tồn tại, chưa READY, hoặc đã hết hạn')])]
    public function exportDownload(string $exportJobId)
    {
        $job = $this->ownedExportJob($exportJobId);
        // WWHN-343: client bấm Tải về thẳng (không poll) trên một job mà worker bỏ quên
        // vẫn phải nhận được file thay vì 404 vĩnh viễn.
        if ($job !== null) {
            $job = ReportExportJobRunner::ensureProgress($job);
        }
        if ($job === null || $job->status !== 'READY' || $job->file_path === null) {
            return $this->respondNotFound('Không tìm thấy file export (job chưa sẵn sàng hoặc không tồn tại).');
        }
        if ($job->expires_at !== null && now()->greaterThan($job->expires_at)) {
            return $this->respondNotFound('File export này đã hết hạn.');
        }

        $bytes = ReportExportFileStore::read($job);
        if ($bytes === null) {
            // READY + còn hạn mà không đọc được nội dung ở đâu cả thì KHÔNG phải "đã bị dọn
            // dẹp" (hết hạn có nhánh riêng ngay trên, purge thì null hoá file_path): nội dung
            // chưa từng tới được nơi lưu mà tiến trình này đọc. Dựng lại ngay tại đây thay cho
            // một 404 lặp lại vô hạn — xem ReportExportJobRunner::recoverMissingFile().
            $job = ReportExportJobRunner::recoverMissingFile($job);
            $bytes = $job->status === 'READY' ? ReportExportFileStore::read($job) : null;

            if ($bytes === null && $job->status === 'READY') {
                $job = ReportExportJobRunner::markFileMissing($job);
            }
            if ($bytes === null) {
                return $job->status === 'FAILED'
                    ? $this->respondError($job->error_message, [], 404, $job->error_code)
                    : $this->respondNotFound('Không tìm thấy file export (job chưa sẵn sàng hoặc không tồn tại).');
            }
        }

        // Trả thẳng nội dung vừa đọc, MIME khai tường minh: nội dung lưu trong database không có
        // đuôi file để Flysystem tự đoán như `$disk->download()` trước đây.
        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            $job->file_name ?? ($job->export_job_id.'.'.($job->format === 'pdf' ? 'pdf' : 'xlsx')),
            [
                'Content-Type' => $job->format === 'pdf'
                    ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Length' => (string) strlen($bytes),
            ],
        );
    }

    /** Fail-closed như ImportReportController::scopedQuery(): job của actor khác = không tồn tại. */
    private function ownedExportJob(string $exportJobId): ?ReportExportJob
    {
        if (! Str::isUuid($exportJobId)) {
            return null;
        }

        return ReportExportJob::query()
            ->where('export_job_id', $exportJobId)
            ->where('requested_by', $this->currentActorId())
            ->first();
    }

    /**
     * §13.8 Privacy Contract: "PII export requires warranty.pii.export + reason".
     *
     * WWHN-258 / FE258-P1-04. Cửa cấp PII thô vẫn chỉ có một là
     * `AlignedWarrantyReportController::export()`; hàm này không nới thêm quyền gì,
     * nó chỉ từ chối SỚM đúng những request mà cửa đó chắc chắn sẽ chặn. Không có
     * nó thì một actor thiếu `warranty.pii.export` vẫn nhận 201 + `download_url`
     * và để lại một bản audit `REPORT_EXPORT_REQUESTED` cho lần export không bao
     * giờ xảy ra được — người đọc audit không phân biệt nổi với export hợp lệ.
     *
     * @param  array<string, string>  $filters
     */
    private function assertPiiExportAllowed(Request $request, string $reportCode, array $filters): void
    {
        if ($reportCode !== 'warranty-defects') {
            return;
        }

        $reason = trim((string) ($filters['reason'] ?? ''));
        if ($reason === '') {
            // Không gửi reason là hợp lệ: file vẫn xuất nhưng đã mask.
            return;
        }

        if (! $request->user()?->hasPermission('warranty.pii.export')) {
            ApiError::abort(403, 'PERMISSION_DENIED', 'Xuất PII bảo hành cần quyền warranty.pii.export.', [
                'required_permission' => 'warranty.pii.export',
            ]);
        }

        $minLength = AlignedWarrantyReportController::PII_REASON_MIN_LENGTH;
        if (mb_strlen($reason) < $minLength) {
            ApiError::abort(422, 'PII_EXPORT_REASON_INVALID', 'Lý do xuất PII phải có ít nhất '.$minLength.' ký tự.', [
                'field' => 'reason',
                'min_length' => $minLength,
            ]);
        }
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

    // ── Inventory Balance ────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-balance', operationId: 'report_inventory_balance', summary: 'RPT-INV-01 tồn kho hiện tại kèm đối soát ledger', description: '§13.10 RPT-INV-01. Filter SKU/category/status + as_of (inclusive). Khi as_of ở quá khứ, báo cáo là ảnh chụp theo ledger và reconciliation_status = NOT_APPLICABLE. DATA-SCOPE (WWHN-254 DEV254-P1-03): kết quả bị cắt theo warehouse data-scope của người gọi (cùng nguồn scope_ref với /inventory/balances); payload trả data_scope=GLOBAL|WAREHOUSE_SCOPED và scope_warehouse_ids. warehouse_id nằm ngoài scope trả 403 ACCESS_DENIED. Export dùng đúng scope này (BR-EXP-01).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'])), new OA\Parameter(name: 'as_of', in: 'query', description: 'Mốc chốt số liệu, inclusive (Asia/Ho_Chi_Minh)', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200))], responses: [new OA\Response(response: 200, description: 'Tồn kho hiện tại')])]
    public function inventoryBalance(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildInventoryBalance($request), $request));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-balance/export-excel', operationId: 'report_inventory_balance_export', summary: 'Xuất XLSX/PDF tồn kho hiện tại', description: 'BR-EXP-01..05: cùng bộ lọc với /reports/inventory-balance, audit mọi lần xuất, tên file có timestamp, tối đa 10.000 dòng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'])), new OA\Parameter(name: 'as_of', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF tồn kho')])]
    public function inventoryBalanceExport(Request $request)
    {
        $report = $this->buildInventoryBalance($request);
        // Cột "Đối soát" phải là verdict TỔNG HỢP như trên màn hình (RPT-INV-01 §2.5).
        // Trước đây file xuất chỉ in reconciliation_status (Ledger↔Balance) nên một
        // dòng lệch ở sổ phụ hiện "Có chênh lệch" trên màn hình mà "MATCH" trong file.
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['category'], $row['stock_status'],
            $row['on_hand_qty'], $row['available_qty'], $row['serial_qty'], $row['box_qty'], $row['reserved_qty'],
            $row['location_hint'], $row['ledger_qty'], $row['variance'],
            $row['reconciliation_overall'], $this->mismatchChecksLabel($row), $row['updated_at'],
        ], $this->withSubledgerChecks($request, $report['rows']));
        $headers = ['Kho', 'Mã SKU', 'Tên SKU', 'Nhóm hàng', 'Trạng thái tồn', 'Tồn kho', 'Khả dụng', 'Serial', 'Trong khay/thùng', 'Đã giữ', 'Vị trí gợi ý', 'Ledger', 'Chênh lệch', 'Đối soát', 'Lệch ở', 'Cập nhật'];

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Tồn kho hiện tại', $headers, $rows, 'bao-cao-ton-kho-hien-tai', 'inventory-balance', $request);
        }

        return $this->exportRows($request, 'Ton kho', $headers, $rows, 'bao-cao-ton-kho-hien-tai.xlsx', 'inventory-balance');
    }

    // ── XNT ──────────────────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/inventory-xnt', operationId: 'report_inventory_xnt_current', summary: 'RPT-XNT-01 Nhập-Xuất-Tồn theo authoritative ledger', description: '§13.10 RPT-XNT-01 + BR-RPT-01: closing = opening + posted receipt - posted issue - component issue + posted reversal. Kỳ inclusive theo Asia/Ho_Chi_Minh. DATA-SCOPE (WWHN-254 DEV254-P1-03): kết quả bị cắt theo warehouse data-scope của người gọi (cùng nguồn scope_ref với /inventory/balances); payload trả data_scope=GLOBAL|WAREHOUSE_SCOPED và scope_warehouse_ids. warehouse_id nằm ngoài scope trả 403 ACCESS_DENIED. Export dùng đúng scope này (BR-EXP-01).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200))], responses: [new OA\Response(response: 200, description: 'XNT — ba tầng cột gross / reversal / net theo BR-RPT-01..07', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/XntReport')])])), new OA\Response(response: 401, description: 'Chưa xác thực', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')), new OA\Response(response: 403, description: 'Thiếu report.view hoặc warehouse_id nằm ngoài warehouse data scope (error.code = ACCESS_DENIED)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')), new OA\Response(response: 422, description: 'from_date/to_date hoặc filter không hợp lệ', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'))])]
    public function inventoryXnt(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildXnt($request), $request, 'lines'));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt/export-excel', operationId: 'report_xnt_export', summary: 'Xuất XLSX/PDF Nhập-Xuất-Tồn', description: 'BR-EXP-01..05: cùng bộ lọc với /reports/inventory-xnt, audit, tên file có timestamp.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function inventoryXntExport(Request $request)
    {
        return $this->exportXnt($request, 'bao-cao-xnt');
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced', operationId: 'report_xnt_enhanced', summary: 'RPT-XNT-01 Nhập-Xuất-Tồn chi tiết theo movement type', description: 'Cùng builder và cùng contract với /reports/inventory-xnt; alias giữ cho client đang dùng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200))], responses: [new OA\Response(response: 200, description: 'XNT chi tiết — cùng schema XntReport với /reports/inventory-xnt', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/XntReport')])])), new OA\Response(response: 401, description: 'Chưa xác thực', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')), new OA\Response(response: 403, description: 'Thiếu report.view hoặc warehouse_id ngoài data scope (ACCESS_DENIED)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'))])]
    public function inventoryXntEnhanced(Request $request)
    {
        return $this->respondSuccess($this->paginateReport($this->buildXnt($request), $request, 'lines'));
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-enhanced/export-excel', operationId: 'report_xnt_enhanced_export', summary: 'Xuất XLSX/PDF Nhập-Xuất-Tồn chi tiết', description: 'Alias của /reports/inventory-xnt/export-excel, cùng bộ lọc và cùng quy tắc BR-EXP.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'], default: 'xlsx')), new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File XLSX/PDF')])]
    public function inventoryXntEnhancedExport(Request $request)
    {
        return $this->exportXnt($request, 'xnt-chitiet');
    }

    #[OA\Get(path: '/api/v1/reports/inventory-xnt-daily', operationId: 'report_inventory_xnt_daily', summary: 'RPT-XNT-02 chuỗi Nhập/Xuất/Tồn cuối theo ngày', description: 'Cùng bộ filter (from_date/to_date/warehouse_id/category_id/keyword) và cùng data-scope với /reports/inventory-xnt-enhanced, nhưng gộp theo ngày trên toàn bộ tập lọc (không tách theo SKU) để vẽ chart xu hướng. closing_qty là số dư luỹ kế tính từ opening_before_period — không giới hạn per_page vì mỗi ngày chỉ 1 dòng.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Chuỗi ngày kèm inbound_qty/outbound_qty/reversal_net/closing_qty'), new OA\Response(response: 403, description: 'warehouse_id ngoài data scope (ACCESS_DENIED)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')), new OA\Response(response: 422, description: 'from_date/to_date không hợp lệ', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'))])]
    public function inventoryXntDaily(Request $request)
    {
        return $this->respondSuccess($this->buildXntDaily($request));
    }

    // ── Document History ─────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/document-history', operationId: 'report_document_history', summary: 'RPT-IO-01 lịch sử phiếu nhập/xuất kèm tầng dòng', description: '§13.10 RPT-IO-01. Mỗi phiếu kèm rows[].lines[] gồm expected/scanned/posted; posted lấy từ movement authoritative (DEC-INV-001). Filter: date, SKU, status, agency recipient. DATA-SCOPE (WWHN-254 DEV254-P1-03): kết quả bị cắt theo warehouse data-scope của người gọi (cùng nguồn scope_ref với /inventory/balances); payload trả data_scope=GLOBAL|WAREHOUSE_SCOPED và scope_warehouse_ids. warehouse_id nằm ngoài scope trả 403 ACCESS_DENIED. Export dùng đúng scope này (BR-EXP-01).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'doc_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound', 'all'])), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Lịch sử phiếu')])]
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

    #[OA\Get(path: '/api/v1/reports/outbound-by-agency', operationId: 'report_outbound_by_agency', summary: 'RPT-RECIPIENT-01 xuất kho theo nơi nhận', description: '§13.10 RPT-RECIPIENT-01: source_request_no, outbound_no, receiver/agency, address, contact đã mask, SKU/qty, posted_at. Agency chỉ là chiều nơi nhận, không phải chủ tồn. contact_masked chỉ hiện đầy đủ khi actor có warranty.pii.view. DATA-SCOPE (WWHN-254 DEV254-P1-03): kết quả bị cắt theo warehouse data-scope của người gọi (cùng nguồn scope_ref với /inventory/balances); payload trả data_scope=GLOBAL|WAREHOUSE_SCOPED và scope_warehouse_ids. warehouse_id nằm ngoài scope trả 403 ACCESS_DENIED. Export dùng đúng scope này (BR-EXP-01).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Xuất theo nơi nhận')])]
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

    #[OA\Get(path: '/api/v1/reports/movement-trace', operationId: 'report_movement_trace', summary: 'RPT-MOV-01 lịch sử nhập/xuất/Reversal', description: '§13.10 RPT-MOV-01: movement_id, source_document, movement_type, SKU/item, qty, actor, time, reversal_chain. Hiển thị gross + chain, không bao giờ ẩn movement gốc. DATA-SCOPE (WWHN-254 DEV254-P1-03): kết quả bị cắt theo warehouse data-scope của người gọi (cùng nguồn scope_ref với /inventory/balances); payload trả data_scope=GLOBAL|WAREHOUSE_SCOPED và scope_warehouse_ids. warehouse_id nằm ngoài scope trả 403 ACCESS_DENIED. Export dùng đúng scope này (BR-EXP-01).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'item_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'code_value', in: 'query', description: 'Mã vật lý (Barcode/QR/RFID) của Item hoặc Container', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'source_type', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'source_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'])), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Movement trace — mỗi dòng giữ nguyên bút toán gross; original_movement_id/correction_chain_id là khoá ghép chuỗi; summary.net_effect = SUM(qty_delta) của toàn kỳ (net effect theo BR-REV-11). Chi tiết một chain đọc ở GET /stock-movements/{id}/trace-source.', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessEnvelope')), new OA\Response(response: 401, description: 'Chưa xác thực', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')), new OA\Response(response: 403, description: 'Thiếu report.view hoặc warehouse_id ngoài warehouse data scope (ACCESS_DENIED)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')), new OA\Response(response: 422, description: 'Filter không hợp lệ', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope'))])]
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

    #[OA\Get(path: '/api/v1/reports/inventory-reconciliation', operationId: 'report_inventory_reconciliation', summary: 'Đối soát tồn kho với sổ giao dịch', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'])), new OA\Parameter(name: 'only_mismatch', in: 'query', schema: new OA\Schema(type: 'boolean'))], responses: [new OA\Response(response: 200, description: 'Kết quả đối soát')])]
    public function inventoryReconciliation(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'stock_status' => ['nullable', 'in:'.implode(',', self::STOCK_STATUSES)],
            'only_mismatch' => ['nullable', 'boolean'],
        ]);

        // buildInventoryBalance() da co cache, nhung phan dat cua endpoint nay la
        // withSubledgerChecks(): no doi chieu serial/so luong/thung cho TUNG dong
        // ton kho. Khong cache o day thi moi lan mo man doi soat lai chay tron bo
        // doi chieu do, du ben duoi ton kho da doc ra tu cache.
        return $this->respondSuccess($this->cachedReportPayload(
            'warehouse:report:inventory-reconciliation',
            $this->reportFiltersWithoutPaging($request),
            $this->reportWarehouseScope($request),
            fn (): array => $this->inventoryReconciliationData($request)
        ));
    }

    /** @return array<string, mixed> */
    private function inventoryReconciliationData(Request $request): array
    {
        $report = $this->buildInventoryBalance($request);
        $rows = collect($this->withSubledgerChecks($request, $report['rows']));
        if ($request->boolean('only_mismatch')) {
            $rows = $rows->where('reconciliation_overall', 'MISMATCH')->values();
        }

        $mismatches = $rows->where('reconciliation_overall', 'MISMATCH')->values();

        return [
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
        ];
    }

    // ── Dashboard Quick ──────────────────────────────────────

    #[OA\Get(path: '/api/v1/reports/dashboard-quick', operationId: 'report_dashboard_quick', summary: 'Số liệu nhanh cho dashboard (WWHN-328: endpoint tổng hợp KPI, tái sử dụng — không tạo route mới)', description: 'Aggregate 1 lần gọi: KPI tồn/nhập/xuất theo occurred_at, số ca bảo hành theo status, cảnh báo đối soát. Mặc định period là [hôm nay - 30 ngày, hôm nay], inclusive hai đầu (= 31 ngày) khi bỏ trống from_date/to_date. DATA-SCOPE (WWHN-328 DEV328-P1-01): dashboard chịu đúng warehouse data-scope của người gọi, cùng nguồn scope_ref với /inventory/balances và /stock-movements — người dùng không bị giới hạn kho nhận GLOBAL, người dùng bị giới hạn chỉ nhận số liệu các kho trong scope. Ngoại lệ đã ghi nhận: warranty_cases_by_status luôn GLOBAL vì ERD warranty_case không có warehouse_id.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', description: 'Mặc định: hôm nay trừ 30 ngày (Asia/Ho_Chi_Minh)', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', description: 'Phải >= from_date (422 BUSINESS_RULE_ERROR nếu vi phạm). Mặc định: hôm nay', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', description: 'Thu hẹp KPI về 1 kho. Phải nằm trong data-scope của người gọi, nếu không trả 403 ACCESS_DENIED.', schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dashboard KPIs', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'period', properties: [new OA\Property(property: 'from_date', type: 'string', format: 'date'), new OA\Property(property: 'to_date', type: 'string', format: 'date')], type: 'object'), new OA\Property(property: 'data_scope', type: 'string', enum: ['GLOBAL', 'WAREHOUSE_SCOPED'], description: 'GLOBAL = không giới hạn kho; WAREHOUSE_SCOPED = đã lọc theo scope_warehouse_ids'), new OA\Property(property: 'scope_warehouse_ids', type: 'array', nullable: true, description: 'null khi data_scope=GLOBAL; danh sách kho đã áp dụng khi WAREHOUSE_SCOPED', items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'available_qty', type: 'number', description: 'SUM(inventory_balances.available_qty WHERE stock_status=AVAILABLE) trong data-scope, snapshot hiện tại — không lọc theo period'), new OA\Property(property: 'receipt_qty', type: 'number', description: 'max(0, SUM(qty_delta)) của stock_movements.movement_type=RECEIPT trong [from_date 00:00:00, to_date 23:59:59] theo occurred_at, trong data-scope'), new OA\Property(property: 'issue_qty', type: 'number', description: 'abs(min(0, SUM(qty_delta))) của movement_type=ISSUE trong period, trong data-scope'), new OA\Property(property: 'component_issue_qty', type: 'number', description: 'abs(min(0, SUM(qty_delta))) của movement_type=COMPONENT_ISSUE trong period, trong data-scope'), new OA\Property(property: 'reversal_net', type: 'number', description: 'SUM(qty_delta) của movement_type=REVERSAL trong period, trong data-scope, có thể âm'), new OA\Property(property: 'warranty_cases_by_status', type: 'object', description: 'Map status → COUNT(*), toàn bộ warranty_case: KHÔNG lọc theo period và KHÔNG lọc theo kho (warranty_case không có warehouse_id trong ERD)', additionalProperties: new OA\AdditionalProperties(type: 'integer')), new OA\Property(property: 'reconciliation_warning_count', type: 'integer', description: 'Tổng số dòng RPT-INV-01 reconciliation_status=MISMATCH trong data-scope — đây là con số dùng để hiển thị và đối soát'), new OA\Property(property: 'reconciliation_warnings', type: 'array', description: 'Tối đa 50 dòng MISMATCH đầu tiên (mẫu để soi nhanh). Dùng reconciliation_warning_count cho tổng số; xem đầy đủ tại /reports/inventory-balance.', items: new OA\Items(type: 'object')), new OA\Property(property: 'below_min_stock_count', type: 'integer', description: 'GĐ1 (2026-08-27): số SKU có min_stock đã đặt và tổng available_qty (mọi kho) < min_stock. SKU chưa đặt min_stock không tính.'), new OA\Property(property: 'below_min_stock_skus', type: 'array', description: 'Tối đa 50 SKU đầu tiên (mẫu). Dùng below_min_stock_count cho tổng số.', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'sku_code', type: 'string'), new OA\Property(property: 'sku_name', type: 'string'), new OA\Property(property: 'available_qty', type: 'number'), new OA\Property(property: 'min_stock', type: 'number')])), new OA\Property(property: 'above_max_stock_count', type: 'integer', description: 'GĐ1 (2026-08-27): số SKU có max_stock đã đặt và tổng available_qty (mọi kho) > max_stock. SKU chưa đặt max_stock không tính.'), new OA\Property(property: 'above_max_stock_skus', type: 'array', description: 'Tối đa 50 SKU đầu tiên (mẫu). Dùng above_max_stock_count cho tổng số.', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'sku_code', type: 'string'), new OA\Property(property: 'sku_name', type: 'string'), new OA\Property(property: 'available_qty', type: 'number'), new OA\Property(property: 'max_stock', type: 'number')])), new OA\Property(property: 'daily', type: 'array', description: 'GĐ1 (2026-08-27): chuỗi theo ngày trong [from_date,to_date], cùng data-scope/warehouse filter như các KPI trên — dùng cho chart Dashboard "Nhập/Xuất theo ngày" + "Xu hướng bảo hành". warranty_received_count KHÔNG lọc theo kho (cùng lý do warranty_cases_by_status ở trên).', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'date', type: 'string', format: 'date'), new OA\Property(property: 'receipt_qty', type: 'number'), new OA\Property(property: 'issue_qty', type: 'number'), new OA\Property(property: 'warranty_received_count', type: 'integer')]))], type: 'object'), new OA\Property(property: 'meta', properties: [new OA\Property(property: 'request_id', type: 'string', format: 'uuid'), new OA\Property(property: 'timestamp', type: 'string', format: 'date-time')], type: 'object')])), new OA\Response(response: 401, description: 'UNAUTHENTICATED'), new OA\Response(response: 403, description: 'ACCESS_DENIED — thiếu permission report.view, hoặc warehouse_id nằm ngoài data-scope của người gọi'), new OA\Response(response: 422, description: 'BUSINESS_RULE_ERROR — from_date/to_date/warehouse_id không hợp lệ hoặc to_date < from_date')])]
    public function dashboardQuick(Request $request)
    {
        // period() tự validate/áp default + ném 422 khi to_date < from_date — luôn
        // chạy trước cache, không phụ thuộc cache hit/miss.
        [$from, $to] = $this->period($request);

        // buildDashboardQuick() dựng chart theo-ngày bằng vòng lặp PHP (giống
        // buildXntDaily()) — chặn range quá lớn cùng ngưỡng 366 ngày để tránh dựng
        // hàng nghìn điểm dữ liệu vô ích.
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            ApiError::abort(422, 'BUSINESS_RULE_ERROR', 'Khoảng ngày cho dashboard tối đa 366 ngày.', [
                'to_date' => ['Khoảng from_date..to_date vượt quá 366 ngày.'],
            ]);
        }

        // WWHN-328 DEV328-P1-01/P1-04: data-scope được giải NGAY TẠI ĐÂY, trước cache.
        // Nếu để sau, một cache hit sẽ nuốt mất 403 của warehouse_id ngoài scope.
        $scope = $this->reportWarehouseScope($request);

        $key = CacheKey::of('warehouse:report:dashboard-quick:instant-v2', [
            'from' => $from,
            'to' => $to,
            // Scope BẮT BUỘC có trong key. Hai người dùng khác data-scope gọi cùng kỳ
            // phải nhận hai payload khác nhau; dùng chung key là rò rỉ số liệu chéo kho.
            'scope' => $scope === null ? 'global' : CacheKey::hashFilters($scope),
        ], $this->cacheService()->currentVersion('stock:version'));

        // Endpoint dashboard tốn nhất (4 aggregate query/lần gọi theo audit) — cache
        // toàn bộ payload, TTL ngắn ('dashboard' policy) + bump ngay khi có mutation
        // tồn kho qua `stock:version`. warranty_case không đi qua mutateBalance() nên
        // chỉ được bảo vệ bởi TTL ngắn, chấp nhận được cho 1 dashboard KPI (mục 9).
        $data = $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds('dashboard'),
            fn (): array => $this->buildDashboardQuick($from, $to, $scope)
        );

        return $this->respondSuccess($data);
    }

    #[OA\Get(path: '/api/v1/reports/dashboard-overview', operationId: 'report_dashboard_overview', summary: 'Dashboard tổng quan — 1 lần gọi cho toàn màn hình', description: 'Aggregate phục vụ màn Dashboard tổng quan: KPI tồn/nhập/xuất tách theo sku_type, chuỗi nhập-xuất theo ngày tách 4 chiều, xu hướng tồn, cơ cấu tồn theo trạng thái × loại SKU, tình trạng bảo hành kèm số ca quá hạn, và 4 nhóm vấn đề cần xử lý. Chuỗi thời gian CHỈ phát hành theo NGÀY — client tự gộp sang tuần/tháng/quý/năm (tránh DATE_TRUNC vốn chỉ có trên PostgreSQL trong khi repo gate cả SQLite). DATA-SCOPE: giống dashboard-quick — người dùng bị giới hạn kho chỉ nhận số liệu các kho trong scope. Ngoại lệ đã ghi nhận: mọi số liệu bảo hành luôn GLOBAL vì ERD warranty_case không có warehouse_id.', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', description: 'Mặc định: hôm nay trừ 30 ngày (Asia/Ho_Chi_Minh). Áp cho flow_daily, stock_trend, top_sku và các bộ đếm issues.', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', description: 'Phải >= from_date (422 BUSINESS_RULE_ERROR nếu vi phạm). Khoảng tối đa 366 ngày. Mặc định: hôm nay', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'warehouse_id', in: 'query', description: 'Thu hẹp về 1 kho. Phải nằm trong data-scope của người gọi, nếu không trả 403 ACCESS_DENIED.', schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dashboard overview', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'period', properties: [new OA\Property(property: 'from_date', type: 'string', format: 'date'), new OA\Property(property: 'to_date', type: 'string', format: 'date')], type: 'object'), new OA\Property(property: 'data_scope', type: 'string', enum: ['GLOBAL', 'WAREHOUSE_SCOPED']), new OA\Property(property: 'scope_warehouse_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'kpi', description: 'Mỗi KPI tách {product, component, total}. on_hand là snapshot hiện tại (không lọc theo period); receipt_today/issue_today theo hôm nay; receipt_month/issue_month theo tháng dương lịch hiện tại — độc lập với from_date/to_date.', properties: [new OA\Property(property: 'on_hand', ref: '#/components/schemas/DashboardSkuSplit'), new OA\Property(property: 'receipt_today', ref: '#/components/schemas/DashboardSkuSplit'), new OA\Property(property: 'issue_today', ref: '#/components/schemas/DashboardSkuSplit'), new OA\Property(property: 'receipt_month', ref: '#/components/schemas/DashboardSkuSplit'), new OA\Property(property: 'issue_month', ref: '#/components/schemas/DashboardSkuSplit')], type: 'object'), new OA\Property(property: 'warranty_received', description: 'Số ca bảo hành tiếp nhận theo received_at. GLOBAL, không lọc kho.', properties: [new OA\Property(property: 'today', type: 'integer'), new OA\Property(property: 'week', type: 'integer'), new OA\Property(property: 'month', type: 'integer')], type: 'object'), new OA\Property(property: 'top_sku', description: 'SKU xuất nhiều nhất / ít nhất trong period, chỉ tính movement_type=ISSUE và chỉ SKU có issue_qty > 0. null khi kỳ không phát sinh xuất.', properties: [new OA\Property(property: 'best', nullable: true, ref: '#/components/schemas/DashboardTopSku'), new OA\Property(property: 'worst', nullable: true, ref: '#/components/schemas/DashboardTopSku')], type: 'object'), new OA\Property(property: 'flow_daily', type: 'array', description: 'Chuỗi theo ngày, gap-fill đủ mọi ngày trong period. receipt_* tách theo products.sku_type; issue_product = movement_type ISSUE, issue_component = COMPONENT_ISSUE (xuất linh kiện bảo hành).', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'date', type: 'string', format: 'date'), new OA\Property(property: 'receipt_product', type: 'number'), new OA\Property(property: 'receipt_component', type: 'number'), new OA\Property(property: 'issue_product', type: 'number'), new OA\Property(property: 'issue_component', type: 'number')])), new OA\Property(property: 'stock_trend', type: 'array', description: 'Tồn cuối mỗi ngày = opening_before_period + luỹ kế qty_delta của MỌI movement tới hết ngày đó (cùng công thức closing_qty của /reports/inventory-xnt-daily).', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'date', type: 'string', format: 'date'), new OA\Property(property: 'closing_qty', type: 'number')])), new OA\Property(property: 'stock_structure', description: 'Ma trận sku_type × stock_status của SUM(on_hand_qty) hiện tại. Dùng on_hand_qty (không phải available_qty) vì available_qty bằng 0 ở các status khác AVAILABLE nên biểu đồ cơ cấu sẽ méo. Luôn phát hành đủ 2 loại × 4 status, thiếu dữ liệu thì bằng 0.', properties: [new OA\Property(property: 'PRODUCT', ref: '#/components/schemas/DashboardStockStatusSplit'), new OA\Property(property: 'COMPONENT', ref: '#/components/schemas/DashboardStockStatusSplit')], type: 'object'), new OA\Property(property: 'warranty', properties: [new OA\Property(property: 'by_status', type: 'object', description: 'Map status → COUNT(*), toàn bộ warranty_case, không lọc period và không lọc kho.', additionalProperties: new OA\AdditionalProperties(type: 'integer')), new OA\Property(property: 'overdue_count', type: 'integer', description: 'TỔNG THẬT số ca quá hạn: estimated_completion_date < hôm nay VÀ status chưa kết thúc. Ca chưa điền ngày dự kiến hoàn thành không tính.'), new OA\Property(property: 'overdue_sample', type: 'array', description: 'Tối đa 50 ca quá hạn (mẫu để soi nhanh), sắp xếp theo hạn cũ nhất trước. Dùng overdue_count cho tổng số — count() của mảng này KHÔNG phải tổng.', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'warranty_case_code', type: 'string'), new OA\Property(property: 'customer_name', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'estimated_completion_date', type: 'string', format: 'date'), new OA\Property(property: 'days_overdue', type: 'integer')]))], type: 'object'), new OA\Property(property: 'issues', description: 'Bộ đếm "vấn đề cần xử lý". import_error = SUM(import_reports.error_rows) trong period. sku_pending_info là snapshot hiện tại (KHÔNG lọc theo period). LƯU Ý: không có số "scan lỗi" — scan_record_events chỉ ghi kết quả thành công, quét lỗi ném exception và không lưu dòng nào.', properties: [new OA\Property(property: 'inventory_mismatch', type: 'integer'), new OA\Property(property: 'import_error', type: 'integer'), new OA\Property(property: 'warranty_overdue', type: 'integer'), new OA\Property(property: 'print_failed', type: 'integer'), new OA\Property(property: 'sku_pending_info', type: 'integer', description: 'Số SKU ACTIVE thiếu ít nhất một trong brand_id/category_id/model_id — snapshot hiện tại, không lọc theo period.')], type: 'object')], type: 'object'), new OA\Property(property: 'meta', properties: [new OA\Property(property: 'request_id', type: 'string', format: 'uuid'), new OA\Property(property: 'timestamp', type: 'string', format: 'date-time')], type: 'object')])), new OA\Response(response: 401, description: 'UNAUTHENTICATED'), new OA\Response(response: 403, description: 'ACCESS_DENIED — thiếu permission report.view, hoặc warehouse_id ngoài data-scope'), new OA\Response(response: 422, description: 'BUSINESS_RULE_ERROR — from_date/to_date không hợp lệ, to_date < from_date, hoặc khoảng > 366 ngày')])]
    public function dashboardOverview(Request $request)
    {
        [$from, $to] = $this->period($request);

        // Cùng ngưỡng 366 ngày như dashboard-quick/xnt-daily: builder gap-fill chuỗi
        // ngày bằng vòng lặp PHP nên range vô hạn = dựng hàng nghìn điểm vô ích.
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            ApiError::abort(422, 'BUSINESS_RULE_ERROR', 'Khoảng ngày cho dashboard tối đa 366 ngày.', [
                'to_date' => ['Khoảng from_date..to_date vượt quá 366 ngày.'],
            ]);
        }

        // Giải data-scope TRƯỚC cache — nếu để sau, một cache hit sẽ nuốt mất 403 của
        // warehouse_id ngoài scope (cùng lý do đã ghi ở dashboardQuick).
        $scope = $this->reportWarehouseScope($request);

        $key = CacheKey::of('warehouse:report:dashboard-overview:instant-v2', [
            'from' => $from,
            'to' => $to,
            'scope' => $scope === null ? 'global' : CacheKey::hashFilters($scope),
            // The SLA advances at Vietnam midnight even with a fixed report period.
            'sla_date' => WarrantyOverdue::today()->toDateString(),
        ], $this->cacheService()->currentVersion('stock:version'));

        $data = $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds('dashboard'),
            fn (): array => $this->buildDashboardOverview($from, $to, $scope)
        );

        return $this->respondSuccess($data);
    }

    /**
     * WWHN-328 (dashboard), mở rộng cho WWHN-254 DEV254-P1-03: warehouse data-scope
     * hiệu lực cho **mọi** báo cáo có chiều kho, không riêng dashboard.
     *
     * Trước WWHN-254, chỉ `/reports/dashboard-quick` áp scope; các route `/reports/*`
     * còn lại chỉ có `permission:report.view|report.export` nên người dùng bị giới hạn
     * kho vẫn đọc và export được tồn, XNT, lịch sử chứng từ, movement của toàn hệ thống.
     * Đó là vi phạm AC-SRS-032 ("dữ liệu đúng scope") và BR-EXP-01..05 ("export dùng
     * cùng filter/scope với màn hình").
     *
     * null  = GLOBAL (super-admin, hoặc role gắn scope_ref rỗng) — giữ nguyên hành vi cũ.
     * list  = WAREHOUSE_SCOPED, lấy đúng nguồn `scope_ref` mà EnforceInventoryWarehouseScope
     *         và ScopesWarehouseData đang dùng cho /inventory/balances, /stock-movements.
     *         Không đồng bộ hai chỗ này thì cùng một người dùng sẽ thấy tồn kho bị giới hạn
     *         ở màn Tra cứu tồn nhưng lại thấy tổng KPI toàn hệ thống ở Dashboard.
     * []    = fail-closed (role có scope nhưng không kho nào hợp lệ) — cùng quy ước với
     *         ScopesWarehouseData::bootScopesWarehouseData().
     *
     * @return list<string>|null
     */
    private function reportWarehouseScope(Request $request): ?array
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        $user = auth('api')->user();
        $scope = $user instanceof User ? $user->warehouseScopeIds() : null;
        if ($scope !== null) {
            // sort() để cache key ổn định, không phụ thuộc thứ tự role trả về.
            $scope = collect($scope)->map(fn ($id): string => (string) $id)->unique()->sort()->values()->all();
        }

        $requested = $validated['warehouse_id'] ?? null;
        if ($requested === null || $requested === '') {
            return $scope;
        }

        // Cùng mã lỗi/thông điệp với EnforceInventoryWarehouseScope để FE và QA chỉ
        // phải xử lý một contract 403 duy nhất cho vi phạm data-scope.
        if ($scope !== null && ! in_array((string) $requested, $scope, true)) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Kho yêu cầu nằm ngoài data scope hiện hành.');
        }

        return [(string) $requested];
    }

    /**
     * @param  list<string>|null  $warehouseIds  null = GLOBAL; [] = fail-closed (rỗng).
     * @return array<string, mixed>
     */
    private function buildDashboardQuick(string $from, string $to, ?array $warehouseIds = null): array
    {
        // Gộp ngay tại SQL. Bản cũ `->get(['movement_type','qty_delta'])` kéo TOÀN BỘ
        // movement của kỳ về PHP rồi mới sum() ⇒ bộ nhớ tăng tuyến tính theo số movement.
        $totals = DB::table('stock_movements')
            ->select('movement_type')
            ->selectRaw('SUM(qty_delta) AS total_delta')
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->whereIn('movement_type', self::DASHBOARD_MOVEMENT_TYPES)
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->groupBy('movement_type')
            ->get()
            ->keyBy('movement_type');

        $sum = fn (string $type): float => (float) ($totals[$type]->total_delta ?? 0);

        $availableQty = (float) DB::table('inventory_balances')
            ->where('stock_status', 'AVAILABLE')
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->sum('available_qty');

        // GĐ1 Dashboard "tồn dưới/vượt định mức": định mức đặt theo SKU, TOÀN HỆ
        // THỐNG (không theo kho, theo quyết định BA) — SUM available_qty cộng dồn
        // MỌI kho, không áp $warehouseIds, cùng lý do GLOBAL đã ghi cho
        // warranty_cases_by_status ở dưới. SKU chưa đặt min_stock/max_stock (NULL)
        // không tính vào cảnh báo nào cả.
        $stockByProduct = DB::table('inventory_balances')
            ->where('stock_status', 'AVAILABLE')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(available_qty) AS total_available')
            ->pluck('total_available', 'product_id');
        $belowMinStock = [];
        $aboveMaxStock = [];
        $skuThresholds = DB::table('sku')
            ->where(fn ($q) => $q->whereNotNull('min_stock')->orWhereNotNull('max_stock'))
            ->select('sku_id', 'sku_code', 'sku_name', 'min_stock', 'max_stock')
            ->orderBy('sku_code')
            ->get();
        foreach ($skuThresholds as $sku) {
            $available = (float) ($stockByProduct[$sku->sku_id] ?? 0);
            if ($sku->min_stock !== null && $available < (float) $sku->min_stock) {
                $belowMinStock[] = [
                    'sku_code' => $sku->sku_code,
                    'sku_name' => $sku->sku_name,
                    'available_qty' => $available,
                    'min_stock' => (float) $sku->min_stock,
                ];
            }
            if ($sku->max_stock !== null && $available > (float) $sku->max_stock) {
                $aboveMaxStock[] = [
                    'sku_code' => $sku->sku_code,
                    'sku_name' => $sku->sku_name,
                    'available_qty' => $available,
                    'max_stock' => (float) $sku->max_stock,
                ];
            }
        }

        // §13.10 RPT-INV-01 là nguồn duy nhất của reconciliation_status; dùng lại đúng
        // builder đó (đã có cache riêng) rồi mới cắt theo data-scope, để dashboard và báo
        // cáo tồn kho không bao giờ lệch định nghĩa MISMATCH.
        $reconciliation = $this->buildInventoryBalance(new Request(['only_mismatch' => true]));
        $warnings = collect($reconciliation['rows'])->where('reconciliation_status', 'MISMATCH');
        if ($warehouseIds !== null) {
            $warnings = $warnings->whereIn('warehouse_id', $warehouseIds);
        }
        $warnings = $warnings->values();

        // Warranty aggregates now follow persisted case ownership, including TEMP_ONLY.
        $warranty = WarrantyWarehouseScope::dashboardQuery($warehouseIds)
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        // "Biểu đồ luồng kho theo ngày" + "Xu hướng bảo hành" (Dashboard chart):
        // cùng scope/period như KPI ở trên, chỉ khác chiều group (theo ngày).
        // warranty_received theo received_at, không lọc warehouseIds — cùng lý do
        // GLOBAL đã ghi ở trên.
        $dailyMovement = DB::table('stock_movements')
            ->select(DB::raw('DATE(occurred_at) as bucket_date'), 'movement_type')
            ->selectRaw('SUM(qty_delta) AS total_delta')
            ->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->whereIn('movement_type', self::DASHBOARD_MOVEMENT_TYPES)
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->groupBy(DB::raw('DATE(occurred_at)'), 'movement_type')
            ->get()
            ->groupBy('bucket_date');

        $dailyWarranty = WarrantyWarehouseScope::dashboardQuery($warehouseIds)
            ->select(DB::raw(WarrantyReportTime::receivedDay().' as bucket_date'), DB::raw('COUNT(*) AS total'))
            ->where(WarrantyReportTime::range($from, $to, receivedOnly: true, alias: 'warranty_case'))
            ->groupBy(DB::raw(WarrantyReportTime::receivedDay()))
            ->pluck('total', 'bucket_date');

        $daily = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->toDateString();
            $movementsOfDay = $dailyMovement->get($dateKey, collect())->keyBy('movement_type');
            $sumOfDay = fn (string $type): float => (float) ($movementsOfDay[$type]->total_delta ?? 0);
            $daily[] = [
                'date' => $dateKey,
                'receipt_qty' => max(0, $sumOfDay('RECEIPT')),
                'issue_qty' => abs(min(0, $sumOfDay('ISSUE'))),
                'warranty_received_count' => (int) ($dailyWarranty[$dateKey] ?? 0),
            ];
            $cursor->addDay();
        }

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'data_scope' => $warehouseIds === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED',
            'scope_warehouse_ids' => $warehouseIds,
            'available_qty' => $availableQty,
            'receipt_qty' => max(0, $sum('RECEIPT')),
            'issue_qty' => abs(min(0, $sum('ISSUE'))),
            'component_issue_qty' => abs(min(0, $sum('COMPONENT_ISSUE'))),
            'reversal_net' => $sum('REVERSAL'),
            'warranty_cases_by_status' => $warranty,
            // Tổng thật để hiển thị/đối soát; mảng bên dưới chỉ là mẫu có trần nên
            // count() của mảng KHÔNG còn là tổng số cảnh báo.
            'reconciliation_warning_count' => $warnings->count(),
            'reconciliation_warnings' => $warnings->take(self::DASHBOARD_WARNING_LIMIT)->values()->all(),
            // GĐ1 (2026-08-27): SKU tồn dưới/vượt định mức — count() là tổng thật,
            // mảng bên dưới chỉ là mẫu có trần (cùng pattern reconciliation_warnings).
            'below_min_stock_count' => count($belowMinStock),
            'below_min_stock_skus' => array_slice($belowMinStock, 0, self::DASHBOARD_WARNING_LIMIT),
            'above_max_stock_count' => count($aboveMaxStock),
            'above_max_stock_skus' => array_slice($aboveMaxStock, 0, self::DASHBOARD_WARNING_LIMIT),
            // GĐ1 chart Dashboard "Nhập/Xuất theo ngày" + "Xu hướng bảo hành" — BA đã
            // ký bổ sung field này vào hợp đồng dashboard-quick (2026-08-27).
            'daily' => $daily,
        ];
    }

    /**
     * Dashboard tổng quan — một payload cho cả màn hình.
     *
     * Khác dashboard-quick ở hai điểm nghiệp vụ:
     *  1. Mọi KPI luồng đều tách product/component. Chiều tách của cột NHẬP phải join
     *     products.sku_type (RECEIPT không tự phân biệt loại SKU); chiều tách của cột
     *     XUẤT lấy thẳng movement_type vì ERD đã chặn chứng từ ISSUE nhận SKU
     *     COMPONENT — không cần join, và join thêm sẽ chỉ làm chậm.
     *  2. Chuỗi thời gian CHỈ phát hành theo ngày. Gộp tuần/tháng/quý/năm là việc của
     *     client: DATE_TRUNC là PostgreSQL-only trong khi repo gate cả SQLite, còn
     *     DATE() thì chạy được trên cả hai (cùng lý do đã ghi ở buildXntDaily).
     *
     * @param  list<string>|null  $warehouseIds  null = GLOBAL; [] = fail-closed (rỗng).
     * @return array<string, mixed>
     */
    private function buildDashboardOverview(string $from, string $to, ?array $warehouseIds = null): array
    {
        $fromTs = "{$from} 00:00:00";
        $toTs = "{$to} 23:59:59";
        $today = Carbon::today();

        $scoped = fn ($query) => $query->when(
            $warehouseIds !== null,
            fn ($q) => $q->whereIn('m.warehouse_id', $warehouseIds)
        );

        // Bốn chiều luồng, gộp ngay tại SQL. COALESCE cho sku_type vì `products` là
        // view trên `sku` và bản ghi master khuyết vẫn phải rơi vào một nhóm xác định
        // thay vì biến mất khỏi tổng.
        $flowSelect = fn ($query) => $query
            ->selectRaw("SUM(CASE WHEN m.movement_type = 'RECEIPT' AND COALESCE(p.sku_type, 'PRODUCT') <> 'COMPONENT' THEN m.qty_delta ELSE 0 END) AS receipt_product")
            ->selectRaw("SUM(CASE WHEN m.movement_type = 'RECEIPT' AND COALESCE(p.sku_type, 'PRODUCT') = 'COMPONENT' THEN m.qty_delta ELSE 0 END) AS receipt_component")
            ->selectRaw("SUM(CASE WHEN m.movement_type = 'ISSUE' THEN -m.qty_delta ELSE 0 END) AS issue_product")
            ->selectRaw("SUM(CASE WHEN m.movement_type = 'COMPONENT_ISSUE' THEN -m.qty_delta ELSE 0 END) AS issue_component");

        /** @return array{product: float, component: float, total: float} */
        $split = static function (?object $row, string $productKey, string $componentKey): array {
            $product = max(0, (float) ($row->{$productKey} ?? 0));
            $component = max(0, (float) ($row->{$componentKey} ?? 0));

            return [
                'product' => $product,
                'component' => $component,
                'total' => $product + $component,
            ];
        };

        /** Tổng 4 chiều luồng trong một cửa sổ thời gian bất kỳ. */
        $flowTotals = function (string $windowFrom, string $windowTo) use ($scoped, $flowSelect): ?object {
            $query = DB::table('stock_movements as m')
                ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
                ->whereBetween('m.occurred_at', [$windowFrom, $windowTo]);
            $scoped($query);

            return $flowSelect($query)->first();
        };

        $todayTotals = $flowTotals($today->toDateString().' 00:00:00', $today->toDateString().' 23:59:59');
        $monthTotals = $flowTotals(
            $today->copy()->startOfMonth()->toDateString().' 00:00:00',
            $today->copy()->endOfMonth()->toDateString().' 23:59:59'
        );

        // Tồn khả dụng hiện tại theo loại SKU — snapshot, KHÔNG lọc theo period.
        $onHandRows = DB::table('inventory_balances as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.stock_status', 'AVAILABLE')
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('m.warehouse_id', $warehouseIds))
            ->select('p.sku_type')
            ->selectRaw('SUM(m.available_qty) AS total')
            ->groupBy('p.sku_type')
            ->get();
        $onHandProduct = 0.0;
        $onHandComponent = 0.0;
        foreach ($onHandRows as $row) {
            if ((string) ($row->sku_type ?? 'PRODUCT') === 'COMPONENT') {
                $onHandComponent += (float) $row->total;
            } else {
                $onHandProduct += (float) $row->total;
            }
        }

        // Cơ cấu tồn: on_hand_qty chứ không phải available_qty — available_qty bằng 0
        // ở mọi status khác AVAILABLE nên biểu đồ cơ cấu sẽ chỉ còn một cột.
        $structureRows = DB::table('inventory_balances as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('m.warehouse_id', $warehouseIds))
            ->select('p.sku_type', 'm.stock_status')
            ->selectRaw('SUM(m.on_hand_qty) AS total')
            ->groupBy('p.sku_type', 'm.stock_status')
            ->get();
        $emptyStatuses = ['AVAILABLE' => 0.0, 'NON_SELLABLE' => 0.0, 'DEFECTIVE' => 0.0, 'QUARANTINE' => 0.0];
        $stockStructure = ['PRODUCT' => $emptyStatuses, 'COMPONENT' => $emptyStatuses];
        foreach ($structureRows as $row) {
            $type = (string) ($row->sku_type ?? 'PRODUCT') === 'COMPONENT' ? 'COMPONENT' : 'PRODUCT';
            $status = (string) ($row->stock_status ?? 'AVAILABLE');
            if (! array_key_exists($status, $emptyStatuses)) {
                continue;
            }
            $stockStructure[$type][$status] += (float) $row->total;
        }

        // Chuỗi theo ngày: 4 chiều luồng + net_change để dựng tồn cuối luỹ kế.
        // net_change KHÔNG lọc movement_type (gồm cả REVERSAL/OPENING) vì tồn cuối là
        // luỹ kế của mọi biến động, không riêng 4 loại đang vẽ.
        $dailyQuery = DB::table('stock_movements as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->whereBetween('m.occurred_at', [$fromTs, $toTs])
            ->selectRaw('DATE(m.occurred_at) AS bucket_date')
            ->selectRaw('SUM(m.qty_delta) AS net_change')
            ->groupBy(DB::raw('DATE(m.occurred_at)'));
        $scoped($dailyQuery);
        $byDate = $flowSelect($dailyQuery)->get()->keyBy(fn ($row): string => (string) $row->bucket_date);

        $openingQuery = DB::table('stock_movements as m')->where('m.occurred_at', '<', $fromTs);
        $scoped($openingQuery);
        $running = (float) $openingQuery->sum('qty_delta');

        $flowDaily = [];
        $stockTrend = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->toDateString();
            $row = $byDate->get($dateKey);
            $running += (float) ($row->net_change ?? 0);
            $flowDaily[] = [
                'date' => $dateKey,
                'receipt_product' => max(0, (float) ($row->receipt_product ?? 0)),
                'receipt_component' => max(0, (float) ($row->receipt_component ?? 0)),
                'issue_product' => max(0, (float) ($row->issue_product ?? 0)),
                'issue_component' => max(0, (float) ($row->issue_component ?? 0)),
            ];
            $stockTrend[] = ['date' => $dateKey, 'closing_qty' => $running];
            $cursor->addDay();
        }

        // SKU xuất nhiều/ít nhất: chỉ ISSUE (hàng bán ra), chỉ SKU thực sự có phát
        // sinh xuất — SKU không xuất lần nào không phải "bán ế", nó nằm ngoài câu hỏi.
        $issueBySku = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->whereBetween('m.occurred_at', [$fromTs, $toTs])
            ->where('m.movement_type', 'ISSUE')
            ->select('p.code AS sku_code', 'p.name AS sku_name')
            ->selectRaw('SUM(-m.qty_delta) AS issue_qty')
            ->groupBy('p.code', 'p.name')
            ->havingRaw('SUM(-m.qty_delta) > 0');
        $scoped($issueBySku);
        $issueRanked = $issueBySku->get()
            ->map(fn ($row): array => [
                'sku_code' => (string) $row->sku_code,
                'sku_name' => (string) $row->sku_name,
                'issue_qty' => (float) $row->issue_qty,
            ])
            ->sortByDesc('issue_qty')
            ->values();

        $warrantyByStatus = WarrantyWarehouseScope::dashboardQuery($warehouseIds)
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        // "Quá hạn" đọc từ cột hạn THẬT (estimated_completion_date), không phải ngưỡng
        // tuổi tự chế. Ca chưa điền hạn dự kiến thì không có gì để quá — không tính.
        $overdueBase = fn () => WarrantyOverdue::apply(WarrantyWarehouseScope::dashboardQuery($warehouseIds));
        $overdueCount = (int) $overdueBase()->count();

        // WWHN-SEC-06: `customer_name` KHÔNG còn là cột của bảng — chỉ còn
        // ciphertext. Đọc qua WarrantyCasePii::selectColumns() rồi decodeRows()
        // gán ngược về tên thuộc tính cũ.
        //
        // Tên khách ở đây LUÔN mask, KHÔNG có nhánh `warranty.pii.view` như màn
        // RPT-WAR-01: payload của endpoint này được cache dưới khoá KHÔNG mang
        // danh tính người gọi (`…:dashboard-overview:…:scope-global:v1`), nên chỉ
        // cần một người có quyền xem PII nạp cache là mọi vai khác đọc được tên
        // thật từ đúng cache đó. Dashboard cũng không cần tên thật: FE chỉ dùng
        // `days_overdue` của mẫu này.
        $overdueRows = $overdueBase()
            ->orderBy('estimated_completion_date')
            ->limit(self::DASHBOARD_WARNING_LIMIT)
            ->get(array_merge(
                ['warranty_case_code', 'status', 'estimated_completion_date'],
                WarrantyCasePii::selectColumns()
            ));
        WarrantyCasePii::decodeRows($overdueRows);

        $overdueSample = $overdueRows
            ->map(fn ($row): array => [
                'warranty_case_code' => (string) $row->warranty_case_code,
                'customer_name' => $row->customer_name === null
                    ? null
                    : WarrantyPiiMasker::name((string) $row->customer_name),
                'status' => (string) $row->status,
                'estimated_completion_date' => (string) $row->estimated_completion_date,
                'days_overdue' => (int) Carbon::parse($row->estimated_completion_date, 'Asia/Ho_Chi_Minh')->diffInDays(WarrantyOverdue::today()),
            ])
            ->all();

        $warrantyToday = WarrantyReportTime::today();
        $warrantyReceived = function (string $windowFrom, string $windowTo) use ($warehouseIds): int {
            return (int) WarrantyWarehouseScope::dashboardQuery($warehouseIds)
                ->where(WarrantyReportTime::range($windowFrom, $windowTo, receivedOnly: true, alias: 'warranty_case'))
                ->count();
        };

        // §13.10 RPT-INV-01 là nguồn duy nhất của reconciliation_status. Lấy summary
        // đã tính sẵn thay vì dựng lại mảng rows rồi đếm trong PHP.
        $balance = $this->buildInventoryBalance(new Request(
            $warehouseIds !== null && count($warehouseIds) === 1 ? ['warehouse_id' => $warehouseIds[0]] : []
        ));
        $mismatchCount = (int) ($balance['summary']['mismatch_count'] ?? 0);

        $importErrors = (int) DB::table('import_reports')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->sum('error_rows');

        $printFailed = (int) DB::table('label_snapshots')
            ->where('status', 'PRINT_FAILED')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->count();

        // SKU tự tạo qua luồng scan-driven (materializeSkuIfMissing trong
        // BaM1StockService) chỉ có sku_code/sku_name(=sku_code)/sku_type/status —
        // không gán brand_id/category_id/model_id. Snapshot GLOBAL, không lọc theo
        // period (giống mismatchCount) vì đây là tình trạng dữ liệu hiện tại, không
        // phải phát sinh trong kỳ.
        $pendingSkuQuery = DB::table('sku')->where('status', 'ACTIVE');
        SkuInformationCompleteness::apply($pendingSkuQuery, 'PENDING');
        $skuPendingInfo = (int) $pendingSkuQuery->count();

        return $this->withScopeMeta([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'kpi' => [
                'on_hand' => [
                    'product' => $onHandProduct,
                    'component' => $onHandComponent,
                    'total' => $onHandProduct + $onHandComponent,
                ],
                'receipt_today' => $split($todayTotals, 'receipt_product', 'receipt_component'),
                'issue_today' => $split($todayTotals, 'issue_product', 'issue_component'),
                'receipt_month' => $split($monthTotals, 'receipt_product', 'receipt_component'),
                'issue_month' => $split($monthTotals, 'issue_product', 'issue_component'),
            ],
            'warranty_received' => [
                'today' => $warrantyReceived($warrantyToday->toDateString(), $warrantyToday->toDateString()),
                'week' => $warrantyReceived(
                    $warrantyToday->startOfWeek()->toDateString(),
                    $warrantyToday->endOfWeek()->toDateString()
                ),
                'month' => $warrantyReceived(
                    $warrantyToday->startOfMonth()->toDateString(),
                    $warrantyToday->endOfMonth()->toDateString()
                ),
            ],
            'top_sku' => [
                'best' => $issueRanked->first(),
                'worst' => $issueRanked->last(),
            ],
            'flow_daily' => $flowDaily,
            'stock_trend' => $stockTrend,
            'stock_structure' => $stockStructure,
            'warranty' => [
                'by_status' => $warrantyByStatus,
                // Tổng thật để hiển thị; mảng bên dưới có trần nên count() của nó
                // KHÔNG phải tổng số ca quá hạn.
                'overdue_count' => $overdueCount,
                'overdue_sample' => $overdueSample,
            ],
            'issues' => [
                'inventory_mismatch' => $mismatchCount,
                'import_error' => $importErrors,
                'warranty_overdue' => $overdueCount,
                'print_failed' => $printFailed,
                'sku_pending_info' => $skuPendingInfo,
            ],
        ], $warehouseIds);
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
            'as_of' => array_merge(['nullable'], InputLimits::pastDate()),
            'keyword' => ['nullable', 'string', 'max:200'],
            // Dashboard/report "Top SKU tồn cao nhất" cần sort thật ở SQL/collection,
            // không được suy ra top-N từ 1 trang đã phân trang (sai khi có nhiều trang).
            'sort_by' => ['nullable', 'in:available_qty,on_hand_qty,variance,product_code,warehouse_code'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        // DEV254-P1-03: scope giải trước cache. Nếu giải sau, một cache hit của người
        // dùng GLOBAL sẽ trả nguyên payload toàn hệ thống cho người bị giới hạn kho —
        // và 403 của warehouse_id ngoài scope cũng bị cache nuốt mất.
        $scope = $this->reportWarehouseScope($request);

        // Dùng chung bộ dựng key với mọi báo cáo khác (trait CachesReportPayload)
        // thay vì tự ghép: một chỗ sửa, không có biến thể lệch nhau.
        $key = $this->reportCacheKey('warehouse:report:inventory-balance', $validated, $scope);

        // Report tồn kho — cùng strategy với InventoryBalanceController::index (short
        // TTL 'report' + version `stock:version` bump ngay sau mutation). Trả về mảng
        // thuần (không Response/Paginator) nên cache trực tiếp toàn bộ kết quả an toàn.
        return $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds('report'),
            fn (): array => $this->buildInventoryBalanceUncached($request, $validated, $scope)
        );
    }

    /**
     * Gắn nhãn scope vào payload báo cáo để FE/QA đọc được ngay bản ghi đã bị cắt theo
     * kho nào — không phải suy đoán từ số dòng.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    private function withScopeMeta(array $payload, ?array $scope): array
    {
        $payload['data_scope'] = $scope === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED';
        $payload['scope_warehouse_ids'] = $scope;

        return $payload;
    }

    /**
     * @param  list<string>|null  $scope  null = GLOBAL; [] = fail-closed.
     */
    private function buildInventoryBalanceUncached(Request $request, array $validated, ?array $scope = null): array
    {
        $asOf = ! empty($validated['as_of']) ? Carbon::parse($validated['as_of'])->toDateString() : null;

        $balanceQuery = DB::table('inventory_balances as b')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->select('b.*', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name', 'p.category_id', 'pc.category_name', 'p.sku_type');
        // WWHN-231: nhánh ledger đi qua LedgerReconciliationService (aggregate-then-join)
        // thay vì join master data TRƯỚC GROUP BY. Cùng tập dòng, nhưng aggregate chỉ chạm
        // stock_movements nên planner dùng được index dimension thay vì gom theo 8 cột
        // (4 cột text) trên toàn bộ ledger — xem docs/evidence/WWHN-231/BENCHMARK.md.
        $ledgerFilters = [];

        // DEV254-P1-03: cắt theo data-scope TRƯỚC mọi filter do người dùng gửi lên.
        if ($scope !== null) {
            $balanceQuery->whereIn('b.warehouse_id', $scope);
            $ledgerFilters['warehouse_ids'] = $scope;
        }

        foreach (['warehouse_id', 'product_id', 'stock_status'] as $filter) {
            if ($request->filled($filter)) {
                $balanceQuery->where("b.{$filter}", $request->input($filter));
                $ledgerFilters[$filter] = (string) $request->input($filter);
            }
        }
        if (! empty($validated['category_id'])) {
            $balanceQuery->where('p.category_id', $validated['category_id']);
        }
        if ($asOf !== null) {
            // DEC-INV-001: ledger là source of truth nên as_of chỉ cắt được trên
            // movement; balance projection luôn là trạng thái hiện tại.
            $ledgerFilters['occurred_to'] = "{$asOf} 23:59:59";
        }
        $keyword = null;
        if ($request->filled('keyword')) {
            $keyword = '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%';
            $balanceQuery->where(function ($q) use ($keyword): void {
                $q->whereRaw('LOWER(p.code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(p.name) LIKE ?', [$keyword]);
            });
        }

        // Filter theo thuộc tính SKU (category/keyword) đẩy thành subquery trên product_id.
        // Bản cũ lọc qua LEFT JOIN products nên dòng ledger có product_id không khớp master
        // cũng bị loại; IN (SELECT id FROM products WHERE …) cho đúng cùng tập dòng đó.
        $categoryId = $validated['category_id'] ?? null;
        if ($categoryId !== null || $keyword !== null) {
            $ledgerFilters['product_id_in'] = function ($q) use ($categoryId, $keyword): void {
                $q->select('id')->from('products');
                if ($categoryId !== null) {
                    $q->where('category_id', $categoryId);
                }
                if ($keyword !== null) {
                    $q->where(function ($inner) use ($keyword): void {
                        $inner->whereRaw('LOWER(code) LIKE ?', [$keyword])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
                    });
                }
            };
        }

        $balances = $balanceQuery->get()->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));
        $movements = app(LedgerReconciliationService::class)
            ->ledgerAggregateWithMasterQuery($ledgerFilters)
            ->get()
            ->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));
        $keys = $balances->keys()->merge($movements->keys())->unique()->values();

        // §13.10 RPT-INV-01: serial_qty/box_qty là subledger (Item_Instance /
        // container quantity mapping), location_hint non-authoritative (DR-LOC-001).
        $productIds = $balances->pluck('product_id')->merge($movements->pluck('product_id'))->filter()->unique()->values()->all();
        $serialCounts = $this->serialSubledgerCounts($productIds);
        $boxCounts = $this->boxSubledgerCounts($productIds);
        $locationHints = $this->locationHints($productIds);
        $serialTracked = $this->serialTrackedFlags($productIds);

        // §13.10 RPT-INV-01 "as_of inclusive": khi client chốt một mốc quá khứ thì
        // ledger đã bị cắt tới mốc đó còn inventory_balances luôn là hiện tại, nên
        // hiệu hai vế KHÔNG phải divergence. Ở chế độ as_of, báo cáo trở thành ảnh
        // chụp theo ledger (DEC-INV-001 — ledger là authoritative) và đối soát
        // Ledger↔Balance được đánh NOT_APPLICABLE thay vì báo MISMATCH giả.
        $isAsOfSnapshot = $asOf !== null && $asOf < now()->toDateString();

        $rows = $keys->map(function (string $key) use ($balances, $movements, $serialCounts, $boxCounts, $locationHints, $serialTracked, $isAsOfSnapshot): array {
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
                // GĐ1: donut "Cơ cấu tồn theo loại" ở FE cần lát cắt PRODUCT/COMPONENT
                // thô (khác `category` là nhóm hàng nghiệp vụ chi tiết).
                'sku_type' => (string) ($balance->sku_type ?? $movement->sku_type ?? ''),
                'stock_status' => $status,
                'serial_qty' => (float) ($serialCounts[$productId] ?? 0),
                // Quyết định check serial subledger có áp dụng hay không (ERD bảng 16).
                'is_serial_tracked' => (bool) ($serialTracked[$productId] ?? false),
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
        });

        // "Cơ cấu tồn theo loại" (Dashboard/report): tính trên TOÀN BỘ tập đã lọc,
        // trước khi áp sort_by/sort_dir bên dưới — không được lệch theo trang.
        $byCategory = $rows
            ->groupBy(fn (array $r): string => $r['category'] !== '' ? $r['category'] : 'Chưa phân loại')
            ->map(fn ($items, string $category): array => [
                'category' => $category,
                'available_qty' => (float) $items->sum('available_qty'),
                'on_hand_qty' => (float) $items->sum('on_hand_qty'),
            ])->values()->all();

        $sortBy = $validated['sort_by'] ?? null;
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $rows = $sortBy !== null
            ? $rows->sortBy($sortBy, SORT_REGULAR, $sortDir === 'desc')
            : $rows->sortBy(['warehouse_code', 'product_code', 'stock_status']);
        $rows = $rows->values();

        return $this->withScopeMeta([
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
            'by_category' => $byCategory,
            'rows' => $rows->all(),
        ], $scope);
    }

    /**
     * §7.1 subledger serial: số Item_Instance đang nằm trong kho theo SKU.
     *
     * @param  list<string>  $productIds
     * @return array<string, int>
     */
    private function serialSubledgerCounts(array $productIds): array
    {
        if ($productIds === [] || ! SchemaCache::hasTable('item_instances')) {
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
     * SRS §7.5: bổ sung 3 check sổ phụ vào các dòng RPT-INV-01 rồi chốt
     * `reconciliation_overall` + `mismatch_checks`.
     *
     * Dùng CHUNG cho /reports/inventory-reconciliation và file xuất tồn kho, vì
     * màn hình đọc verdict tổng hợp còn file xuất trước đây in mỗi
     * reconciliation_status (chỉ Ledger↔Balance) — cùng một dòng, hai kết luận
     * ngược nhau giữa màn hình và file tải về.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSubledgerChecks(Request $request, array $rows): array
    {
        // Hai check sổ phụ là chỉ số Ở CẤP SKU: item_instances và container_items
        // KHÔNG có warehouse_id/stock_status nên không cắt được theo chiều
        // (kho, SKU, trạng thái) của dòng báo cáo. Bản cũ đem tổng-toàn-SKU so với
        // available_qty/on_hand_qty của MỘT dòng, nên SKU nằm ở nhiều kho hoặc
        // nhiều stock_status luôn bị kết luận lệch giả.
        //
        // Tổng phải đọc thẳng từ inventory_balances chứ KHÔNG cộng dồn $rows: $rows
        // đã bị bộ lọc của người dùng cắt bớt (vd stock_status=AVAILABLE bỏ hết bucket
        // DEFECTIVE), nên cộng từ đó sẽ ra một verdict đổi theo bộ lọc đang xem.
        $productIds = collect($rows)->pluck('product_id')->filter()->unique()->values()->all();
        $skuTotals = $this->skuStockTotals($productIds, $this->reportWarehouseScope($request));

        return array_map(function (array $row) use ($skuTotals): array {
            $productId = (string) $row['product_id'];
            $skuAvailable = (float) ($skuTotals['available'][$productId] ?? 0);
            $skuOnHand = (float) ($skuTotals['on_hand'][$productId] ?? 0);
            $serialQty = (float) $row['serial_qty'];
            $boxQty = (float) $row['box_qty'];

            // Sổ phụ serial chỉ có nghĩa với SKU quản lý theo serial (ERD bảng 16,
            // skus.tracking_mode). Điều kiện cũ suy ra "SKU serial" từ việc CÓ dòng
            // item_instances (serial_qty > 0): một SKU quản lý theo SỐ LƯỢNG chỉ cần
            // lỡ có 1 item_instance là bị kết luận lệch (available 25 vs serial 1) dù
            // sổ cái và tồn kho khớp nhau — đó chính là nguồn "Chênh lệch 0 nhưng
            // trạng thái vẫn Có chênh lệch" người dùng thấy trên RPT-INV-01.
            $row['serial_subledger_status'] = ! (bool) ($row['is_serial_tracked'] ?? false) || $row['stock_status'] !== 'AVAILABLE'
                ? 'NOT_APPLICABLE'
                : (abs($skuAvailable - $serialQty) < 0.001 ? 'MATCH' : 'MISMATCH');
            // Quantity subledger: tổng quantity trong container mapping không được
            // vượt tồn thật; vượt nghĩa là container đang tạo thêm một bucket tồn.
            $row['quantity_subledger_status'] = $boxQty <= 0
                ? 'NOT_APPLICABLE'
                : ($boxQty <= $skuOnHand + 0.001 ? 'MATCH' : 'MISMATCH');
            $row['container_integrity_status'] = $boxQty > $skuOnHand + 0.001
                ? 'MISMATCH'
                : 'MATCH';

            // Badge trạng thái là TỔNG HỢP 4 check, còn cột "Chênh lệch" chỉ là
            // Ledger↔Balance. Không trả kèm danh sách check hỏng thì hai cột đứng
            // cạnh nhau đọc như dữ liệu tự mâu thuẫn.
            $row['mismatch_checks'] = array_values(array_keys(array_filter([
                'ledger_to_balance' => $row['reconciliation_status'] === 'MISMATCH',
                'serial_subledger' => $row['serial_subledger_status'] === 'MISMATCH',
                'quantity_subledger' => $row['quantity_subledger_status'] === 'MISMATCH',
                'container_integrity' => $row['container_integrity_status'] === 'MISMATCH',
            ])));
            $row['reconciliation_overall'] = $row['mismatch_checks'] === [] ? 'MATCH' : 'MISMATCH';

            return $row;
        }, $rows);
    }

    /**
     * Nhãn tiếng Việt của các check đang lệch, dùng cho cột "Lệch ở" trong file xuất.
     *
     * @param  array<string, mixed>  $row
     */
    private function mismatchChecksLabel(array $row): string
    {
        $labels = [
            'ledger_to_balance' => 'Sổ cái ↔ số dư',
            'serial_subledger' => 'Sổ serial',
            'quantity_subledger' => 'Số lượng trong thùng',
            'container_integrity' => 'Toàn vẹn thùng chứa',
        ];

        return implode(', ', array_map(
            fn (string $check): string => $labels[$check] ?? $check,
            (array) ($row['mismatch_checks'] ?? [])
        ));
    }

    /**
     * Tổng tồn của từng SKU trên toàn bộ bucket (kho × stock_status) trong data-scope
     * của người gọi — vế đối chiếu cho hai check sổ phụ, vốn chỉ có chiều SKU.
     *
     * Đọc trực tiếp inventory_balances để verdict KHÔNG đổi theo bộ lọc đang xem
     * trên màn báo cáo.
     *
     * @param  list<string>  $productIds
     * @param  list<string>|null  $scope  null = GLOBAL
     * @return array{available: array<string, float>, on_hand: array<string, float>}
     */
    private function skuStockTotals(array $productIds, ?array $scope): array
    {
        if ($productIds === []) {
            return ['available' => [], 'on_hand' => []];
        }

        $totals = DB::table('inventory_balances')
            ->whereIn('product_id', $productIds)
            ->when($scope !== null, fn ($query) => $query->whereIn('warehouse_id', $scope))
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(available_qty), 0) AS available_total, COALESCE(SUM(on_hand_qty), 0) AS on_hand_total')
            ->get();

        return [
            'available' => $totals->pluck('available_total', 'product_id')->map(fn ($value): float => (float) $value)->all(),
            'on_hand' => $totals->pluck('on_hand_total', 'product_id')->map(fn ($value): float => (float) $value)->all(),
        ];
    }

    /**
     * ERD bảng 16 skus.tracking_mode = 'SERIAL'. Đối soát sổ phụ serial chỉ áp dụng
     * cho SKU quản lý theo serial; với SKU quản lý theo SỐ LƯỢNG thì số dòng
     * item_instances không phải là sổ phụ của tồn kho, đem so sẽ ra lệch giả.
     *
     * @param  list<string>  $productIds
     * @return array<string, bool>
     */
    private function serialTrackedFlags(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('products')
            ->whereIn('id', $productIds)
            ->pluck('is_serial_tracked', 'id')
            ->map(fn ($value): bool => (bool) $value)
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
        if ($productIds === [] || ! SchemaCache::hasTable('container_items')) {
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
        if ($productIds === [] || ! SchemaCache::hasTable('containers')) {
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

    /**
     * RPT-XNT-01: quét movement cả kỳ rồi tổng hợp theo SKU x kho — đây là báo cáo tốn SQL nhất của hệ thống.
     *
     * Đi qua cache theo ĐÚNG khuôn của buildInventoryBalance():
     *  - data-scope giải TRƯỚC cache, nếu không một cache hit sẽ nuốt mất 403 của
     *    warehouse_id ngoài scope, và người bị giới hạn kho có thể nhận payload
     *    toàn hệ thống do người dùng GLOBAL vừa dựng;
     *  - scope nằm TRONG key nên hai người khác data-scope không dùng chung entry;
     *  - version namespace `stock:version` (bump ngay sau mutation tồn kho) khiến
     *    entry cũ mồ côi tức thì, TTL 'report' chỉ là lưới an toàn bổ sung.
     */
    private function buildXnt(Request $request): array
    {
        return $this->cachedReportPayload(
            'warehouse:report:xnt',
            // Phân trang cắt TRONG BỘ NHỚ ở paginateReport() nên một entry phục vụ
            // được mọi trang -> bỏ page/per_page ra khỏi key.
            $this->reportFiltersWithoutPaging($request),
            // Giải scope Ở ĐÂY, trước khi vào cache: đối số được tính ngay, nên 403
            // của warehouse_id ngoài scope vẫn bắn ra kể cả khi sau đó là cache hit.
            $this->reportWarehouseScope($request),
            fn (): array => $this->buildXntUncached($request)
        );
    }

    private function buildXntUncached(Request $request): array
    {
        [$from, $to] = $this->period($request, true);
        // §13.10 RPT-XNT-01: filter date period; SKU/category.
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        $scope = $this->reportWarehouseScope($request);
        $fromTs = "{$from} 00:00:00";
        $toTs = "{$to} 23:59:59";

        // WWHN-231: cùng kỹ thuật aggregate-then-join như báo cáo tồn. XNT quét gần như toàn
        // bộ ledger (điều kiện duy nhất là `occurred_at <= to`), nên gom theo 6 cột — trong
        // đó 3 cột text lấy từ master data — chính là chỗ đắt nhất của báo cáo này.
        $aggregate = DB::table('stock_movements as m')
            ->where('m.occurred_at', '<=', $toTs)
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status')
            ->selectRaw("SUM(CASE WHEN m.occurred_at < ? OR (m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'OPENING') THEN m.qty_delta ELSE 0 END) AS opening", [$fromTs, $fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'RECEIPT' THEN m.qty_delta ELSE 0 END) AS receipt", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'ISSUE' THEN -m.qty_delta ELSE 0 END) AS issue", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'COMPONENT_ISSUE' THEN -m.qty_delta ELSE 0 END) AS component_issue", [$fromTs, $toTs])
            ->selectRaw("SUM(CASE WHEN m.occurred_at BETWEEN ? AND ? AND m.movement_type = 'REVERSAL' THEN m.qty_delta ELSE 0 END) AS reversal_net", [$fromTs, $toTs])
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status');
        if ($scope !== null) {
            $aggregate->whereIn('m.warehouse_id', $scope);
        }
        if ($request->filled('warehouse_id')) {
            $aggregate->where('m.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $aggregate->where('m.product_id', $request->input('product_id'));
        }
        $xntCategoryId = $validated['category_id'] ?? null;
        $xntKeyword = $request->filled('keyword')
            ? '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%'
            : null;
        if (! empty($xntCategoryId) || $xntKeyword !== null) {
            // Bản cũ lọc qua LEFT JOIN products nên dòng ledger không khớp master bị loại;
            // IN (SELECT id FROM products WHERE …) giữ đúng cùng tập dòng đó.
            $aggregate->whereIn('m.product_id', function ($q) use ($xntCategoryId, $xntKeyword): void {
                $q->select('id')->from('products');
                if (! empty($xntCategoryId)) {
                    $q->where('category_id', $xntCategoryId);
                }
                if ($xntKeyword !== null) {
                    $q->where(function ($inner) use ($xntKeyword): void {
                        $inner->whereRaw('LOWER(code) LIKE ?', [$xntKeyword])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$xntKeyword]);
                    });
                }
            });
        }

        $query = DB::query()
            ->fromSub($aggregate, 'agg')
            ->leftJoin('warehouses as w', 'w.id', '=', 'agg.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'agg.product_id')
            ->select(
                'agg.warehouse_id',
                'agg.product_id',
                'agg.stock_status',
                'agg.opening',
                'agg.receipt',
                'agg.issue',
                'agg.component_issue',
                'agg.reversal_net',
                'w.code as warehouse_code',
                'p.code as product_code',
                'p.name as product_name',
            );

        // WWHN-240 DEV240-P1-02A: một cột `reversal_net` gộp chung đảo-nhập (qty_delta < 0)
        // và đảo-xuất (qty_delta > 0) thì hai chiều triệt tiêu lẫn nhau nên không đọc được
        // nhập/xuất THỰC của kỳ. BR-RPT-01..07 yêu cầu "hiển thị gross/net" và BR-REV-11 yêu
        // cầu net effect, nên tách lượng đã đảo theo movement_type của giao dịch GỐC.
        $reversalBreakdown = $this->xntReversalBreakdown($request, $validated, $fromTs, $toTs, $scope);

        $lines = $query->get()->map(function ($row) use ($reversalBreakdown): array {
            $breakdown = $reversalBreakdown[$this->dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status)]
                ?? self::EMPTY_XNT_REVERSAL;

            $opening = (float) $row->opening;
            $receiptGross = (float) $row->receipt;
            $issueGross = (float) $row->issue;
            $componentGross = (float) $row->component_issue;
            $reversalNet = (float) $row->reversal_net;

            $receiptReversal = $breakdown['RECEIPT'];
            $issueReversal = $breakdown['ISSUE'];
            $componentReversal = $breakdown['COMPONENT_ISSUE'];
            $unlinkedReversalNet = $breakdown['UNLINKED'];

            $receiptNet = $receiptGross - $receiptReversal;
            $issueNet = $issueGross - $issueReversal;
            $componentNet = $componentGross - $componentReversal;

            return [
                'warehouse_code' => (string) $row->warehouse_code,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'stock_status' => (string) $row->stock_status,
                'opening' => $opening,
                // GROSS — giữ nguyên tên cột cũ làm bí danh để client hiện hành không vỡ.
                'receipt' => $receiptGross,
                'receipt_gross' => $receiptGross,
                'issue' => $issueGross,
                'issue_gross' => $issueGross,
                'component_issue' => $componentGross,
                'component_issue_gross' => $componentGross,
                // REVERSAL — quy về cùng chiều dương với cột gross tương ứng.
                'receipt_reversal' => $receiptReversal,
                'issue_reversal' => $issueReversal,
                'component_issue_reversal' => $componentReversal,
                'reversal_unlinked_net' => $unlinkedReversalNet,
                'reversal_net' => $reversalNet,
                // NET — con số nghiệp vụ dùng để đối soát kỳ.
                'receipt_net' => $receiptNet,
                'issue_net' => $issueNet,
                'component_issue_net' => $componentNet,
                // Bằng đúng công thức gross cũ: opening + receipt - issue - component + reversal_net.
                'closing' => $opening + $receiptNet - $issueNet - $componentNet + $unlinkedReversalNet,
            ];
        })->sortBy(['warehouse_code', 'product_code', 'stock_status'])->values()->all();

        // GĐ1 Dashboard/Report "Top sản phẩm nhập/xuất": gộp $lines (đã tính
        // receipt_net/issue_net) theo SKU, bỏ chiều kho/stock_status — cùng bộ
        // filter đã áp cho $lines ở trên. FE tự suy "top xuất nhiều" (đầu mảng),
        // "top xuất ít" (cuối mảng, lọc issue_qty>0), "top nhập nhiều" (tự sort lại
        // theo receipt_qty) từ đúng 1 mảng này, không cần 3 field riêng.
        $byProduct = collect($lines)
            ->groupBy('product_code')
            ->map(fn ($rows) => [
                'product_code' => $rows->first()['product_code'],
                'product_name' => $rows->first()['product_name'],
                'receipt_qty' => (float) $rows->sum('receipt_net'),
                'issue_qty' => (float) $rows->sum('issue_net'),
            ])
            ->sortByDesc('issue_qty')
            ->values()
            ->all();

        return $this->withScopeMeta([
            'from_date' => $from,
            'to_date' => $to,
            'formula' => 'Closing = Opening + Receipt - Issue - Component Issue + Reversal',
            'formula_net' => 'Closing = Opening + Receipt Net - Issue Net - Component Issue Net + Unlinked Reversal Net; Receipt Net = Receipt Gross - Receipt Reversal; Issue Net = Issue Gross - Issue Reversal; Component Issue Net = Component Issue Gross - Component Issue Reversal',
            'column_semantics' => self::XNT_COLUMN_SEMANTICS,
            'lines' => $lines,
            'by_product' => $byProduct,
        ], $scope);
    }

    /**
     * RPT-XNT-02 — chuỗi Nhập/Xuất/Tồn cuối theo NGÀY, gộp trên toàn bộ tập lọc
     * (không tách theo SKU/kho) để phục vụ chart xu hướng ở FE. Không có sẵn
     * time-series nào khác trong hệ thống nên đây là nguồn duy nhất cho chart
     * "Nhập/Xuất theo ngày" + "Tồn cuối theo ngày" (tab Nhập-Xuất-Tồn).
     *
     * `closing_qty` là số dư luỹ kế: opening_before_period cộng dồn qty_delta
     * thô của TẤT CẢ movement_type (kể cả OPENING hiệu chỉnh nằm trong kỳ) —
     * khác với "opening" của buildXnt() vốn tách riêng OPENING trong kỳ cho một
     * bảng tổng cả kỳ; ở đây mỗi ngày là một điểm trên chuỗi nên mọi movement
     * trong ngày đó đều làm thay đổi số dư của đúng ngày đó, không cần tách.
     */
    /**
     * Chuỗi tồn theo NGÀY: gap-fill vòng lặp PHP trên toàn kỳ, chi phí tăng tuyến tính theo số ngày người dùng chọn.
     *
     * Đi qua cache theo ĐÚNG khuôn của buildInventoryBalance():
     *  - data-scope giải TRƯỚC cache, nếu không một cache hit sẽ nuốt mất 403 của
     *    warehouse_id ngoài scope, và người bị giới hạn kho có thể nhận payload
     *    toàn hệ thống do người dùng GLOBAL vừa dựng;
     *  - scope nằm TRONG key nên hai người khác data-scope không dùng chung entry;
     *  - version namespace `stock:version` (bump ngay sau mutation tồn kho) khiến
     *    entry cũ mồ côi tức thì, TTL 'report' chỉ là lưới an toàn bổ sung.
     */
    private function buildXntDaily(Request $request): array
    {
        return $this->cachedReportPayload(
            'warehouse:report:xnt-daily',
            // Phân trang cắt TRONG BỘ NHỚ ở paginateReport() nên một entry phục vụ
            // được mọi trang -> bỏ page/per_page ra khỏi key.
            $this->reportFiltersWithoutPaging($request),
            // Giải scope Ở ĐÂY, trước khi vào cache: đối số được tính ngay, nên 403
            // của warehouse_id ngoài scope vẫn bắn ra kể cả khi sau đó là cache hit.
            $this->reportWarehouseScope($request),
            fn (): array => $this->buildXntDailyUncached($request)
        );
    }

    private function buildXntDailyUncached(Request $request): array
    {
        [$from, $to] = $this->period($request, true);
        // Chart theo ngày — chặn range quá lớn để tránh vòng lặp dựng hàng nghìn
        // điểm dữ liệu vô ích (không có ca nghiệp vụ nào cần xem quá 1 năm).
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            ApiError::abort(422, 'BUSINESS_RULE_ERROR', 'Khoảng ngày cho chart theo ngày tối đa 366 ngày.', [
                'to_date' => ['Khoảng from_date..to_date vượt quá 366 ngày.'],
            ]);
        }
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        $scope = $this->reportWarehouseScope($request);
        $fromTs = "{$from} 00:00:00";
        $toTs = "{$to} 23:59:59";

        $categoryId = $validated['category_id'] ?? null;
        $keyword = $request->filled('keyword')
            ? '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%'
            : null;

        // Cùng filter/scope với buildXnt() (đoạn 1018-1046) để hai chart trên
        // cùng tab luôn khớp nhau khi người dùng đổi bộ lọc.
        $applyFilters = function ($query) use ($request, $scope, $categoryId, $keyword): void {
            if ($scope !== null) {
                $query->whereIn('m.warehouse_id', $scope);
            }
            if ($request->filled('warehouse_id')) {
                $query->where('m.warehouse_id', $request->input('warehouse_id'));
            }
            if ($categoryId !== null || $keyword !== null) {
                $query->whereIn('m.product_id', function ($q) use ($categoryId, $keyword): void {
                    $q->select('id')->from('products');
                    if ($categoryId !== null) {
                        $q->where('category_id', $categoryId);
                    }
                    if ($keyword !== null) {
                        $q->where(function ($inner) use ($keyword): void {
                            $inner->whereRaw('LOWER(code) LIKE ?', [$keyword])
                                ->orWhereRaw('LOWER(name) LIKE ?', [$keyword]);
                        });
                    }
                });
            }
        };

        $openingQuery = DB::table('stock_movements as m')->where('m.occurred_at', '<', $fromTs);
        $applyFilters($openingQuery);
        $openingBeforePeriod = (float) $openingQuery->sum('qty_delta');

        // DATE(...) chạy giống nhau trên cả PostgreSQL và SQLite (2 driver gate
        // của repo này) nên không cần branch theo driver như TO_CHAR/ILIKE.
        $dailyQuery = DB::table('stock_movements as m')
            ->whereBetween('m.occurred_at', [$fromTs, $toTs])
            ->selectRaw('DATE(m.occurred_at) AS bucket_date')
            ->selectRaw("SUM(CASE WHEN m.movement_type = 'RECEIPT' THEN m.qty_delta ELSE 0 END) AS inbound_qty")
            ->selectRaw("SUM(CASE WHEN m.movement_type IN ('ISSUE', 'COMPONENT_ISSUE') THEN -m.qty_delta ELSE 0 END) AS outbound_qty")
            ->selectRaw("SUM(CASE WHEN m.movement_type = 'REVERSAL' THEN m.qty_delta ELSE 0 END) AS reversal_net")
            ->selectRaw('SUM(m.qty_delta) AS net_change')
            ->groupBy(DB::raw('DATE(m.occurred_at)'));
        $applyFilters($dailyQuery);
        $byDate = $dailyQuery->get()->keyBy(fn ($row): string => (string) $row->bucket_date);

        $days = [];
        $running = $openingBeforePeriod;
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->toDateString();
            $row = $byDate->get($dateKey);
            $running += (float) ($row->net_change ?? 0);
            $days[] = [
                'date' => $dateKey,
                'inbound_qty' => (float) ($row->inbound_qty ?? 0),
                'outbound_qty' => (float) ($row->outbound_qty ?? 0),
                'reversal_net' => (float) ($row->reversal_net ?? 0),
                'closing_qty' => $running,
            ];
            $cursor->addDay();
        }

        return $this->withScopeMeta([
            'from_date' => $from,
            'to_date' => $to,
            'opening_before_period' => $openingBeforePeriod,
            'formula' => 'closing_qty[ngày D] = opening_before_period + tổng qty_delta của mọi movement từ đầu kỳ đến hết ngày D',
            'days' => $days,
        ], $scope);
    }

    /**
     * WWHN-240 DEV240-P1-02A: tổng lượng đã đảo trong kỳ, tách theo movement_type của
     * giao dịch GỐC trong correction chain (RECEIPT / ISSUE / COMPONENT_ISSUE).
     *
     * Bắt buộc đi theo `original_movement_id` chứ không suy từ dấu của `qty_delta`: đảo một
     * ISSUE và đảo một COMPONENT_ISSUE đều cho qty_delta dương, đoán theo dấu thì hai cột
     * nghiệp vụ khác nhau sẽ bị gộp làm một.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>|null  $scope  null = GLOBAL; phải cắt cùng scope với query chính.
     * @return array<string, array{RECEIPT: float, ISSUE: float, COMPONENT_ISSUE: float, UNLINKED: float}>
     */
    private function xntReversalBreakdown(Request $request, array $validated, string $fromTs, string $toTs, ?array $scope): array
    {
        $query = DB::table('stock_movements as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.movement_type', 'REVERSAL')
            ->whereBetween('m.occurred_at', [$fromTs, $toTs])
            ->select('m.warehouse_id', 'm.product_id', 'm.stock_status', 'm.original_movement_id')
            ->selectRaw('SUM(m.qty_delta) AS reversal_delta')
            ->groupBy('m.warehouse_id', 'm.product_id', 'm.stock_status', 'm.original_movement_id');
        if ($scope !== null) {
            $query->whereIn('m.warehouse_id', $scope);
        }
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

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $rootTypes = $this->resolveCorrectionRootTypes(
            $rows->pluck('original_movement_id')->filter()->map(fn ($id): string => (string) $id)->unique()->values()->all()
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $key = $this->dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status);
            if (! isset($breakdown[$key])) {
                $breakdown[$key] = self::EMPTY_XNT_REVERSAL;
            }
            $delta = (float) $row->reversal_delta;
            $rootType = $row->original_movement_id === null
                ? null
                : ($rootTypes[(string) $row->original_movement_id] ?? null);

            if ($rootType === 'RECEIPT') {
                // Đảo một RECEIPT mang qty_delta âm; đổi dấu để cùng chiều với cột "Nhập".
                $breakdown[$key]['RECEIPT'] += -$delta;
            } elseif ($rootType === 'ISSUE') {
                $breakdown[$key]['ISSUE'] += $delta;
            } elseif ($rootType === 'COMPONENT_ISSUE') {
                $breakdown[$key]['COMPONENT_ISSUE'] += $delta;
            } else {
                // Không truy được gốc (dữ liệu migrate, gốc là OPENING, hoặc chain đứt):
                // vẫn phải cộng vào closing, nếu không báo cáo sẽ lệch với ledger.
                $breakdown[$key]['UNLINKED'] += $delta;
            }
        }

        return $breakdown;
    }

    /**
     * WWHN-240 / BR-REV-08: "không reverse trực tiếp một Reversal POSTED... tạo một Reversal
     * mới tham chiếu movement gần nhất trong chain". Vì vậy `original_movement_id` của một
     * REVERSAL có thể lại trỏ tới một REVERSAL khác. Hàm này đi ngược tới movement gốc để
     * biết lượng đảo thuộc cột Nhập hay cột Xuất.
     *
     * @param  list<string>  $movementIds
     * @return array<string, string> movement_id => movement_type của giao dịch gốc
     */
    private function resolveCorrectionRootTypes(array $movementIds): array
    {
        $rootType = [];
        $parentOf = [];
        $visited = [];
        $pending = $movementIds;
        $hops = 0;

        while ($pending !== [] && $hops++ < self::MAX_CORRECTION_CHAIN_DEPTH) {
            $rows = DB::table('stock_movements')
                ->whereIn('id', $pending)
                ->get(['id', 'movement_type', 'original_movement_id']);
            $next = [];
            foreach ($rows as $row) {
                $id = (string) $row->id;
                $visited[$id] = true;
                if ((string) $row->movement_type !== 'REVERSAL') {
                    $rootType[$id] = (string) $row->movement_type;

                    continue;
                }
                if ($row->original_movement_id === null) {
                    continue;
                }
                $parent = (string) $row->original_movement_id;
                $parentOf[$id] = $parent;
                if (! isset($visited[$parent])) {
                    $next[] = $parent;
                }
            }
            $pending = array_values(array_unique($next));
        }

        foreach (array_keys($parentOf) as $id) {
            $cursor = $id;
            $depth = 0;
            while (isset($parentOf[$cursor]) && $depth++ < self::MAX_CORRECTION_CHAIN_DEPTH) {
                $cursor = $parentOf[$cursor];
            }
            if (isset($rootType[$cursor])) {
                $rootType[$id] = $rootType[$cursor];
            }
        }

        return $rootType;
    }

    private function exportXnt(Request $request, string $prefix)
    {
        $report = $this->buildXnt($request);
        // BR-EXP-01..05: file xuất phải cùng bộ cột với màn hình, nên mang đủ ba tầng
        // gross / reversal / net thay vì một cột "Hoàn đảo" gộp hai chiều.
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['stock_status'], $row['opening'],
            $row['receipt_gross'], $row['receipt_reversal'], $row['receipt_net'],
            $row['issue_gross'], $row['issue_reversal'], $row['issue_net'],
            $row['component_issue_gross'], $row['component_issue_reversal'], $row['component_issue_net'],
            $row['reversal_net'], $row['closing'],
        ], $report['lines']);
        $headers = [
            'Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Đầu kỳ',
            'Nhập (gross)', 'Đảo nhập', 'Nhập thuần',
            'Xuất (gross)', 'Đảo xuất', 'Xuất thuần',
            'Xuất linh kiện (gross)', 'Đảo xuất linh kiện', 'Xuất linh kiện thuần',
            'Reversal net', 'Cuối kỳ',
        ];

        if ($request->query('format') === 'pdf') {
            return $this->exportPdf('Nhập-Xuất-Tồn', $headers, $rows, "{$prefix}-{$report['from_date']}-{$report['to_date']}", 'inventory-xnt', $request);
        }

        return $this->exportRows($request, 'XNT', $headers, $rows, "{$prefix}-{$report['from_date']}-{$report['to_date']}.xlsx", 'inventory-xnt');
    }

    /**
     * Lịch sử chứng từ nhập/xuất toàn kỳ; là nguồn của 3 màn báo cáo khác nhau ở FE.
     *
     * Đi qua cache theo ĐÚNG khuôn của buildInventoryBalance():
     *  - data-scope giải TRƯỚC cache, nếu không một cache hit sẽ nuốt mất 403 của
     *    warehouse_id ngoài scope, và người bị giới hạn kho có thể nhận payload
     *    toàn hệ thống do người dùng GLOBAL vừa dựng;
     *  - scope nằm TRONG key nên hai người khác data-scope không dùng chung entry;
     *  - version namespace `stock:version` (bump ngay sau mutation tồn kho) khiến
     *    entry cũ mồ côi tức thì, TTL 'report' chỉ là lưới an toàn bổ sung.
     */
    private function buildDocumentHistory(Request $request): array
    {
        return $this->cachedReportPayload(
            'warehouse:report:document-history',
            // Phân trang cắt TRONG BỘ NHỚ ở paginateReport() nên một entry phục vụ
            // được mọi trang -> bỏ page/per_page ra khỏi key.
            $this->reportFiltersWithoutPaging($request),
            // Giải scope Ở ĐÂY, trước khi vào cache: đối số được tính ngay, nên 403
            // của warehouse_id ngoài scope vẫn bắn ra kể cả khi sau đó là cache hit.
            $this->reportWarehouseScope($request),
            fn (): array => $this->buildDocumentHistoryUncached($request)
        );
    }

    private function buildDocumentHistoryUncached(Request $request): array
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
        $scope = $this->reportWarehouseScope($request);
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
        // Chứng từ có hai đầu kho; chỉ cần một đầu nằm trong scope là người dùng có
        // quyền thấy dòng đó (đúng như /inbound-documents, /outbound-documents).
        if ($scope !== null) {
            $query->where(function ($q) use ($scope): void {
                $q->whereIn('sd.src_warehouse_id', $scope)
                    ->orWhereIn('sd.dst_warehouse_id', $scope);
            });
        }
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

        return $this->withScopeMeta([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'doc_type_filter' => $docType,
            'summary' => [
                'total' => count($rows), 'draft' => (int) ($statuses['DRAFT'] ?? 0), 'scanning' => (int) ($statuses['SCANNING'] ?? 0),
                'posted' => (int) ($statuses['POSTED'] ?? 0), 'cancelled' => (int) ($statuses['CANCELLED'] ?? 0),
            ],
            'rows' => $rows,
        ], $scope);
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

    /**
     * Tổng hợp xuất theo đại lý: join qua chứng từ + dòng + đại lý trên toàn kỳ.
     *
     * Đi qua cache theo ĐÚNG khuôn của buildInventoryBalance():
     *  - data-scope giải TRƯỚC cache, nếu không một cache hit sẽ nuốt mất 403 của
     *    warehouse_id ngoài scope, và người bị giới hạn kho có thể nhận payload
     *    toàn hệ thống do người dùng GLOBAL vừa dựng;
     *  - scope nằm TRONG key nên hai người khác data-scope không dùng chung entry;
     *  - version namespace `stock:version` (bump ngay sau mutation tồn kho) khiến
     *    entry cũ mồ côi tức thì, TTL 'report' chỉ là lưới an toàn bổ sung.
     */
    private function buildOutboundByAgency(Request $request): array
    {
        // WWHN-SEC-09: payload này MASK/không-mask SĐT người nhận theo quyền
        // warranty.pii.view của actor (xem buildOutboundByAgencyUncached), nên
        // quyết định mask phải nằm TRONG khoá cache — nếu không, hai người cùng
        // scope nhưng khác quyền PII sẽ dùng chung một entry và người không có
        // quyền đọc được SĐT thô do người có quyền vừa dựng (hoặc ngược lại,
        // người có quyền chỉ thấy bản đã mask). Cùng bài học với
        // StockMovementController::traceByPhysicalCode (readCacheActorId).
        $piiVariant = $this->outboundByAgencyShowsContact($request) ? 'pii:full' : 'pii:masked';

        return $this->cachedReportPayload(
            'warehouse:report:outbound-by-agency',
            // Phân trang cắt TRONG BỘ NHỚ ở paginateReport() nên một entry phục vụ
            // được mọi trang -> bỏ page/per_page ra khỏi key.
            array_merge($this->reportFiltersWithoutPaging($request), ['__pii_variant' => $piiVariant]),
            // Giải scope Ở ĐÂY, trước khi vào cache: đối số được tính ngay, nên 403
            // của warehouse_id ngoài scope vẫn bắn ra kể cả khi sau đó là cache hit.
            $this->reportWarehouseScope($request),
            fn (): array => $this->buildOutboundByAgencyUncached($request)
        );
    }

    /**
     * WWHN-SEC-09: một nguồn duy nhất cho quyết định mask contact của báo cáo
     * này — dùng ở cả khoá cache (buildOutboundByAgency) lẫn lúc dựng payload,
     * để hai chỗ không bao giờ lệch nhau.
     */
    private function outboundByAgencyShowsContact(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('warranty.pii.view');
    }

    private function buildOutboundByAgencyUncached(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'agency_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ]);
        $scope = $this->reportWarehouseScope($request);
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
        // RPT-RECIPIENT-01: đại lý chỉ là chiều người nhận, kho xuất mới là chiều
        // data-scope (src_warehouse_id của phiếu issue).
        if ($scope !== null) {
            $query->whereIn('sd.src_warehouse_id', $scope);
        }
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
        $showContact = $this->outboundByAgencyShowsContact($request);
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
        // "Top SKU xuất ròng" — cùng nguồn $collection (còn _document_id) như
        // by_agency ở trên, chỉ đổi trục gộp sang SKU.
        $bySku = $collection->groupBy(fn ($r) => $r['product_code'].'|'.$r['product_name'])->map(function (Collection $items): array {
            $first = $items->first();

            return [
                'product_code' => $first['product_code'],
                'product_name' => $first['product_name'],
                'document_count' => $items->pluck('_document_id')->unique()->count(),
                'total_issued_qty' => (float) $items->sum('scanned_qty'),
                'agency_count' => $items->pluck('agency_code')->unique()->count(),
            ];
        })->sortByDesc('total_issued_qty')->values()->all();
        $publicRows = $collection->map(function (array $row): array {
            unset($row['_document_id']);

            return $row;
        })->all();

        return $this->withScopeMeta([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'summary' => [
                'total_agencies' => count($byAgency),
                'total_documents' => $collection->pluck('_document_id')->unique()->count(),
                'total_issued_qty' => (float) $collection->sum('scanned_qty'),
            ],
            'by_agency' => $byAgency,
            'by_sku' => $bySku,
            'rows' => $publicRows,
        ], $scope);
    }

    /**
     * Sổ movement chi tiết; phục vụ CẢ /reports/movement-trace lẫn /reports/inventory-ledger.
     *
     * Đi qua cache theo ĐÚNG khuôn của buildInventoryBalance():
     *  - data-scope giải TRƯỚC cache, nếu không một cache hit sẽ nuốt mất 403 của
     *    warehouse_id ngoài scope, và người bị giới hạn kho có thể nhận payload
     *    toàn hệ thống do người dùng GLOBAL vừa dựng;
     *  - scope nằm TRONG key nên hai người khác data-scope không dùng chung entry;
     *  - version namespace `stock:version` (bump ngay sau mutation tồn kho) khiến
     *    entry cũ mồ côi tức thì, TTL 'report' chỉ là lưới an toàn bổ sung.
     */
    private function buildMovementTrace(Request $request): array
    {
        return $this->cachedReportPayload(
            'warehouse:report:movement-trace',
            // KHOÁ CACHE PHẢI MANG page/per_page KHI REQUEST CÓ PHÂN TRANG.
            //
            // Ghi chú cũ ở đây nói "phân trang cắt TRONG BỘ NHỚ ở paginateReport()
            // nên một entry phục vụ được mọi trang" — điều đó KHÔNG đúng với báo
            // cáo này: buildMovementTraceUncached() gọi $query->forPage() và cắt
            // NGAY Ở TẦNG SQL khi request có per_page, rồi tự đặt $report['pagination']
            // để paginateReport() bỏ qua. Mỗi trang vì thế là một payload KHÁC NHAU.
            //
            // Hậu quả đã tái hiện được trên stack local (2026-09-07):
            //   GET ...?from_date=2026-01-01&to_date=2026-12-31&per_page=5  -> nạp cache 5 dòng
            //   GET ...?from_date=2026-01-01&to_date=2026-12-31             -> nhận lại ĐÚNG 5 dòng
            // trong khi summary.total_movements vẫn báo 3.434. Màn Truy vết hàng hoá
            // im lặng hiện 5/3.434 biến động suốt cả TTL — sai dữ liệu trên một báo
            // cáo truy vết, không chỉ là vấn đề hiệu năng.
            //
            // reportFiltersWithPaging() có sẵn cho đúng tình huống này (xem
            // CachesReportPayload). Request KHÔNG phân trang vẫn dùng khoá cũ nên
            // entry "toàn kỳ" không bị tách làm đôi.
            $request->filled('per_page')
                ? $this->reportFiltersWithPaging($request)
                : $this->reportFiltersWithoutPaging($request),
            // Giải scope Ở ĐÂY, trước khi vào cache: đối số được tính ngay, nên 403
            // của warehouse_id ngoài scope vẫn bắn ra kể cả khi sau đó là cache hit.
            $this->reportWarehouseScope($request),
            fn (): array => $this->buildMovementTraceUncached($request)
        );
    }

    private function buildMovementTraceUncached(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'], 'item_id' => ['nullable', 'uuid'],
            'movement_type' => ['nullable', 'in:'.implode(',', self::MOVEMENT_TYPES)], 'code_value' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:80'], 'source_id' => ['nullable', 'uuid'],
        ]);
        $scope = $this->reportWarehouseScope($request);
        // §13.10 RPT-MOV-01 yêu cầu cột actor — join app_user như document-history.
        $query = DB::table('stock_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('app_user as au', 'au.user_id', '=', 'm.created_by')
            ->whereBetween('m.occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->select('m.*', 'w.code as warehouse_code', 'p.code as product_code', 'p.name as product_name', 'au.username as actor_username', 'au.display_name as actor_display_name');
        if ($scope !== null) {
            $query->whereIn('m.warehouse_id', $scope);
        }
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
        // GĐ1 chart FE: cơ cấu biến động theo movement_type — clone TRƯỚC khi
        // forPage() cắt trang bên dưới, để luôn phản ánh toàn bộ kỳ giống summary,
        // không phải chỉ trang hiện tại.
        $byMovementType = (clone $query)
            ->reorder()
            ->groupBy('m.movement_type')
            ->select('m.movement_type')
            ->selectRaw('COUNT(*) AS movement_count')
            ->get()
            ->map(fn ($row): array => [
                'movement_type' => (string) $row->movement_type,
                'movement_count' => (int) $row->movement_count,
            ])->values()->all();

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
        // Mã movement (movement_no) của movement GỐC mà một REVERSAL đang đảo: FE
        // hiển thị mã nghiệp vụ thay cho UUID, nên phải tra thêm — movement gốc
        // có thể nằm ngoài kỳ đang lọc.
        $originalIds = $movements->pluck('original_movement_id')->filter()->unique()->values()->all();
        $originalNoById = $originalIds === [] ? collect() : DB::table('stock_movements')->whereIn('id', $originalIds)->pluck('movement_no', 'id');
        /**
         * MÃ CHỨNG TỪ NGUỒN — tra NGAY TẠI ĐÂY thay vì bắt FE tự ghép.
         *
         * `stock_movements.source_id` là UUID, còn §13.10 đòi cột "Chứng từ /
         * dòng" phải truy ngược được bằng mã người đọc được. Trước đây FE tự lo:
         * màn Truy vết hàng hoá gọi THÊM `/reports/document-history` (không phân
         * trang) + `/reversal-documents` chỉ để dựng một Map<uuid, mã>. Đo được
         * trên stack local: riêng document-history tốn 328 KB nén / 1,69 MB giải
         * nén cho đúng HAI trường được dùng.
         *
         * Hai lệnh tra theo danh sách id dưới đây rẻ hơn hẳn (khoá chính, chỉ
         * lấy 2 cột) và đi đúng khuôn mẫu `original_movement_no` ngay bên trên —
         * cùng một bài toán "UUID -> mã nghiệp vụ", nay giải cùng một chỗ.
         *
         * THÊM trường, KHÔNG bỏ trường nào: `source_id` vẫn còn nguyên nên mọi
         * consumer cũ không đổi.
         */
        $sourceIds = $movements->pluck('source_id')->filter()->unique()->values()->all();
        $sourceNoById = collect();
        if ($sourceIds !== []) {
            $sourceNoById = DB::table('stock_documents')
                ->whereIn('id', $sourceIds)
                ->pluck('doc_no', 'id');
            // Ledger trỏ tới CẢ phiếu đảo; bảng stock_documents không chứa chúng.
            $reversalNoById = DB::table('reversal_documents')
                ->whereIn('id', $sourceIds)
                ->pluck('reversal_no', 'id');
            foreach ($reversalNoById as $id => $no) {
                $sourceNoById[$id] = $no;
            }
        }

        $objectIds = $movements->pluck('item_id')->merge($movements->pluck('container_id'))->filter()->unique()->values()->all();
        $codeMap = $objectIds === [] ? collect() : DB::table('physical_codes')->whereIn('assigned_object_id', $objectIds)->where('status', 'ACTIVE')->orderBy('created_at')->get(['assigned_object_id', 'code_value'])->groupBy('assigned_object_id')->map(fn ($rows) => (string) $rows->first()->code_value);
        $rows = $movements->map(fn ($m): array => [
            'id' => (string) $m->id,
            // Mã nghiệp vụ đọc được (MV-…) — FE không được in UUID thô ra màn.
            'movement_no' => (string) ($m->movement_no ?? ''),
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
            // Mã chứng từ đọc được của source_id; rỗng khi ledger trỏ tới một
            // nguồn không phải phiếu nhập/xuất/đảo (FE hiển thị "Chưa có").
            'source_no' => $m->source_id ? (string) ($sourceNoById[(string) $m->source_id] ?? '') : '',
            'actor' => (string) ($m->actor_display_name ?? $m->actor_username ?? ''),
            'original_movement_id' => $m->original_movement_id ?? null,
            'original_movement_no' => $m->original_movement_id ? (string) ($originalNoById[(string) $m->original_movement_id] ?? '') : null,
            'correction_chain_id' => $m->correction_chain_id ?? null,
        ])->all();
        $collection = collect($rows);

        $report = $this->withScopeMeta([
            'period' => ['from_date' => $from, 'to_date' => $to],
            'formula' => 'net_effect = SUM(qty_delta); reversal/correction append-only, không sửa movement gốc',
            'summary' => [
                'total_movements' => (int) ($aggregate->total_movements ?? count($rows)),
                'net_effect' => (float) ($aggregate->net_effect ?? $collection->sum('qty_delta')),
                'reversal_count' => (int) ($aggregate->reversal_count ?? $collection->where('movement_type', 'REVERSAL')->count()),
            ],
            'by_movement_type' => $byMovementType,
            'rows' => $rows,
        ], $scope);
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
            // date() chứ không pastDate(): client vẫn gửi to_date = ngày mai làm mốc chặn trên của kỳ.
            'from_date' => array_merge([$required ? 'required' : 'nullable'], InputLimits::date()),
            'to_date' => array_merge([$required ? 'required' : 'nullable'], InputLimits::date(), ['after_or_equal:from_date']),
        ];
        $validated = $request->validate($rules);
        $from = (string) ($validated['from_date'] ?? $request->input('from') ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? $request->input('to') ?? now()->toDateString());

        // `after_or_equal:from_date` chỉ so được khi CẢ HAI tham số cùng đi qua
        // validate. Hai đường vòng vẫn lọt: (1) chỉ gửi from_date → to_date rơi về
        // default hôm nay, (2) dùng alias from/to vốn không nằm trong $rules. Khi đó
        // response phát hành period đảo chiều và mọi KPI im lặng trả 0 thay vì báo
        // lỗi — sai đúng contract 422 BUSINESS_RULE_ERROR đã công bố ở OpenAPI.
        foreach (['from_date' => $from, 'to_date' => $to] as $field => $value) {
            if (strtotime($value) === false) {
                ApiError::abort(422, 'BUSINESS_RULE_ERROR', "Tham số {$field} phải là ngày hợp lệ.", [
                    $field => ['Giá trị không parse được thành ngày.'],
                ]);
            }
        }

        if (Carbon::parse($to)->lt(Carbon::parse($from))) {
            ApiError::abort(422, 'BUSINESS_RULE_ERROR', 'to_date phải lớn hơn hoặc bằng from_date.', [
                'to_date' => ['to_date phải lớn hơn hoặc bằng from_date.'],
            ]);
        }

        // Mọi consumer đều ghép "{$from} 00:00:00" / "{$to} 23:59:59" nên period phải
        // là date-only. Rule 'date' của Laravel vẫn cho qua "2026-08-01T10:00:00", khi
        // đó chuỗi ghép ra "2026-08-01T10:00:00 00:00:00" — SQL không so được và KPI
        // lặng lẽ về 0. Chuẩn hoá tại đây, một chỗ cho cả 5 báo cáo dùng period().
        return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()];
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
        $bytes = $this->buildXlsxBytes($sheetTitle, $headers, $rows, $reportKey, $request);

        return response()->streamDownload(fn () => print $bytes, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(string $title, array $headers, array $rows, string $filePrefix, string $reportKey, Request $request)
    {
        $bytes = $this->buildPdfBytes($title, $headers, $rows, $reportKey, $request);
        $fileName = ReportExportFileName::stamp("{$filePrefix}.pdf");

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * Phần dựng bytes (row-limit + audit + render) dùng chung giữa response HTTP
     * (exportRows(), 6 endpoint GET `*­/export-excel` trực tiếp) và job nền
     * (exportBytesFor(), dùng bởi GenerateReportExportJob) — xem checklist "Hiệu năng và
     * triển khai" 27/08.
     */
    private function buildXlsxBytes(string $sheetTitle, array $headers, array $rows, string $reportKey, Request $request): string
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(
                422,
                'REPORT_EXPORT_ROW_LIMIT_EXCEEDED',
                'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.',
                ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]
            );
        }

        // WWHN-347: 128M của base image không đủ cho một file XLSX cỡ thật (4.243 dòng
        // × 17 cột = 130 MB đo được 09/09/2026) — xem ReportExportMemory.
        ReportExportMemory::raise();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        $sheet->fromArray($headers, null, 'A1');
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map([$this, 'excelSafe'], $row), null, "A{$rowIndex}");
            $rowIndex++;
        }
        // WWHN-347: setAutoSize() bắt PhpSpreadsheet đo BỀ RỘNG HIỂN THỊ của từng ô
        // (mỗi ô một lần tra font metrics) — với 10.000 dòng × 17 cột là 170.000 phép đo
        // chỉ để chọn con số bề rộng. Đo 09/09/2026: bỏ autosize rút thời gian dựng file
        // 4.243 dòng từ 2.493 ms xuống 1.715 ms (-31%) mà file mở ra không khác gì.
        // Bề rộng cố định dưới đây suy từ ĐỘ DÀI TIÊU ĐỀ CỘT, kẹp trong [12, 40] ký tự:
        // đủ rộng cho tiêu đề tiếng Việt có dấu, không để cột "Ghi chú" kéo dài vô hạn.
        $columnIndex = 1;
        foreach ($headers as $header) {
            $width = max(12, min(40, mb_strlen((string) $header) + 4));
            $sheet->getColumnDimensionByColumn($columnIndex)->setWidth($width);
            $columnIndex++;
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
        // Không báo cáo nào ghi công thức vào ô (mọi giá trị đã tính sẵn ở builder), nên
        // vòng tính lại công thức trước khi lưu chỉ tốn thời gian.
        $writer->setPreCalculateFormulas(false);
        ob_start();
        $writer->save('php://output');
        $bytes = (string) ob_get_clean();
        // PhpSpreadsheet giữ tham chiếu vòng giữa Spreadsheet ↔ Worksheet ↔ Cell nên GC
        // không thu hồi được khi biến ra khỏi phạm vi. Với 170.000 ô, giữ lại đống đó
        // trong lúc job còn phải ghi file lên đĩa là chỗ dễ chạm trần bộ nhớ nhất.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);

        return $bytes;
    }

    /** Xem docblock buildXlsxBytes() — cùng vai trò, cho nhánh PDF. */
    private function buildPdfBytes(string $title, array $headers, array $rows, string $reportKey, Request $request): string
    {
        if (count($rows) > self::EXPORT_ROW_LIMIT) {
            ApiError::abort(422, 'REPORT_EXPORT_ROW_LIMIT_EXCEEDED', 'Mỗi file export tối đa 10.000 dòng; hãy thu hẹp bộ lọc.', ['limit' => self::EXPORT_ROW_LIMIT, 'row_count' => count($rows)]);
        }

        // WWHN-347: PDF phải có trần RIÊNG, thấp hơn EXPORT_ROW_LIMIT.
        //
        // Dompdf dựng một frame + một bộ style đã resolve cho MỖI Ô rồi reflow cả cây
        // frame một lần, nên bộ nhớ tăng NHANH HƠN TUYẾN TÍNH theo số dòng. Đo
        // 09/09/2026 (17 cột, đúng template rút gọn bên dưới), chạy `-d memory_limit=3G`:
        //
        //     500 dòng → 251 MB /  3,8 s
        //   1.000 dòng → 443 MB / 10,2 s
        //   2.000 dòng → KHÔNG XONG trong 3 GB
        //
        // Nghĩa là hợp đồng 10.000 dòng KHÔNG THỂ đạt được ở nhánh PDF với bất kỳ trần
        // bộ nhớ hợp lý nào. Trước bản vá này PDF không có trần riêng, nên một kỳ báo cáo
        // bình thường (4.243 dòng) làm tiến trình dựng file chết vì fatal hết bộ nhớ — mà
        // fatal KHÔNG phải Throwable nên không chỗ nào chốt được job sang FAILED, và bản
        // ghi nằm lại PENDING/PROCESSING vĩnh viễn: đúng triệu chứng WWHN-347.
        //
        // 422 do hàng rào dưới đây biến sự cố im lặng đó thành câu trả lời có mã lỗi, đến
        // được người dùng ngay ở lần poll kế tiếp và chỉ đúng lối đi tiếp (XLSX).
        //
        // Luật đặt ở ReportPdfRowLimit chứ không viết lại tại chỗ: ba controller báo cáo
        // "aligned" tự dựng bytes bằng đường riêng cũng phải chặn đúng ngưỡng đó với đúng
        // mã lỗi đó (WWHN-345), và ngân sách theo SỐ Ô cho bảng nhiều cột (WWHN-344) chỉ
        // đúng khi mọi nhánh PDF hỏi cùng một nơi.
        ReportPdfRowLimit::assert($rows);

        ReportExportMemory::raise();

        AuditLogger::record(
            action: 'REPORT_EXPORTED',
            objectType: 'report',
            objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => $reportKey, 'format' => 'pdf', 'row_count' => count($rows), 'filters' => $request->query()],
        );

        // WWHN-347: mỗi thuộc tính viền/padding khai trên `td` là một style phải resolve
        // và lưu cho TỪNG ô. Bỏ `border-collapse` + viền 4 cạnh, chỉ giữ một đường kẻ
        // ngang, rút bộ nhớ Dompdf ~44% (1.000 dòng: 1.083 MB → 443 MB) và thời gian
        // ~51% (20,7 s → 10,2 s) mà bảng vẫn đọc được. Đo 09/09/2026.
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;margin-top:8px}th,td{border-bottom:0.5px solid #999;padding:2px 4px;text-align:left}th{background:#e0e0e0;font-weight:bold}h1{font-size:15px;margin:0 0 4px}p{margin:2px 0;font-size:8px;color:#666}</style></head><body>';
        $html .= '<h1>'.htmlspecialchars($title).'</h1>';
        $html .= '<p>Xuất lúc: '.now()->format('j-n-Y H:i').' · Tổng dòng: '.count($rows).'</p>';
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
        // Chuỗi HTML của một bảng nghìn dòng cỡ vài MB; Dompdf đã parse xong nên giữ
        // thêm bản gốc chỉ để ngồi cạnh cây frame đang phình là lãng phí đúng lúc căng
        // bộ nhớ nhất.
        unset($html);
        $dompdf->setPaper('A4', count($headers) > 8 ? 'landscape' : 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Job-facing: trả bytes + tên file đã sẵn sàng lưu đĩa cho MỘT report_code, dùng bởi
     * GenerateReportExportJob (qua đúng $request đã "đóng vai" actor — xem job). Với 3
     * report_code aligned, delegate sang exportPayload() của controller tương ứng — cùng
     * cơ chế `ALIGNED_REPORTS` mà byCode()/exportFilters() đã dùng.
     *
     * @return array{bytes: string, fileName: string, rowCount: int, contentType: string}
     */
    public function exportBytesFor(string $reportCode, Request $request, string $format): array
    {
        if (isset(self::ALIGNED_REPORTS[$reportCode])) {
            // WWHN-346: PHẢI chuyển tiếp $format, nếu không "Xuất PDF" trả về file XLSX.
            //
            // Ba controller aligned quyết định định dạng bằng `$request->query('format')`
            // — đúng cho endpoint HTTP `…/export-excel?format=pdf` của chính chúng. Nhưng
            // job nền mang định dạng ở CỘT `report_export_jobs.format`, KHÔNG nằm trong
            // `filters` (exportFilters() cố ý loại `format` ra), nên $request do
            // ReportExportJobRunner dựng lại KHÔNG hề có query đó. Nhánh này trước đây bỏ
            // rơi tham số $format, để `exportPayload()` rơi về mặc định 'xlsx': người dùng
            // bấm "Xuất PDF" ở màn Bảo hành & linh kiện (và cả Chất lượng nhập, Nhật ký
            // tem) nhận về một file Excel mang đuôi .xlsx. Job vẫn READY nên không có lỗi
            // nào lộ ra để lần theo — chỉ phát hiện được bằng cách mở file tải về.
            //
            // Bơm `format` vào một BẢN SAO của request (không đụng request của người gọi),
            // chứ không truyền thêm tham số: cách này đúng với chữ ký `exportPayload()`
            // hiện tại và vẫn đúng nếu sau này nó nhận thêm tham số format.
            $alignedRequest = $request->duplicate(
                array_merge($request->query->all(), ['format' => $format])
            );

            // WWHN-346: ba controller aligned có buildXlsxBytes/buildPdfBytes RIÊNG, nên
            // chúng KHÔNG đi qua ReportExportMemory::raise() đặt trong hai builder dùng
            // chung của controller này. Thiếu dòng dưới đây, nhánh aligned vẫn dựng file
            // dưới trần 128M của base image: đo được ngày 09/09/2026 trên chính màn Bảo
            // hành & linh kiện — 181 dòng × 14 cột đã ngốn 134,5 MB và chết fatal "Allowed
            // memory size exhausted" ngay giữa Dompdf\Cellmap. Fatal không phải Throwable
            // nên không nhánh catch nào chốt được job, bản ghi nằm lại PROCESSING —
            // đúng triệu chứng "job treo, không có error_code" của ticket.
            ReportExportMemory::raise();

            return app(self::ALIGNED_REPORTS[$reportCode])->exportPayload($alignedRequest);
        }

        return match ($reportCode) {
            'inventory-balance' => $this->exportBytesInventoryBalance($request, $format),
            'inventory-xnt' => $this->exportBytesXnt($request, $format, 'bao-cao-xnt'),
            'inventory-ledger', 'movement-trace' => $this->exportBytesMovementTrace($request, $format),
            'document-history' => $this->exportBytesDocumentHistory($request, $format),
            'outbound-by-agency' => $this->exportBytesOutboundByAgency($request, $format),
            default => ApiError::abort(404, 'RESOURCE_NOT_FOUND', "report_code không hỗ trợ export nền: {$reportCode}."),
        };
    }

    /** @return array{bytes: string, fileName: string, rowCount: int, contentType: string} */
    private function exportBytesInventoryBalance(Request $request, string $format): array
    {
        $report = $this->buildInventoryBalance($request);
        // Cột "Đối soát" phải là verdict TỔNG HỢP như trên màn hình (RPT-INV-01 §2.5).
        // Trước đây file xuất chỉ in reconciliation_status (Ledger↔Balance) nên một
        // dòng lệch ở sổ phụ hiện "Có chênh lệch" trên màn hình mà "MATCH" trong file.
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['category'], $row['stock_status'],
            $row['on_hand_qty'], $row['available_qty'], $row['serial_qty'], $row['box_qty'], $row['reserved_qty'],
            $row['location_hint'], $row['ledger_qty'], $row['variance'],
            $row['reconciliation_overall'], $this->mismatchChecksLabel($row), $row['updated_at'],
        ], $this->withSubledgerChecks($request, $report['rows']));
        $headers = ['Kho', 'Mã SKU', 'Tên SKU', 'Nhóm hàng', 'Trạng thái tồn', 'Tồn kho', 'Khả dụng', 'Serial', 'Trong khay/thùng', 'Đã giữ', 'Vị trí gợi ý', 'Ledger', 'Chênh lệch', 'Đối soát', 'Lệch ở', 'Cập nhật'];

        return $format === 'pdf'
            ? ['bytes' => $this->buildPdfBytes('Tồn kho hiện tại', $headers, $rows, 'inventory-balance', $request), 'fileName' => ReportExportFileName::stamp('bao-cao-ton-kho-hien-tai.pdf'), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->buildXlsxBytes('Ton kho', $headers, $rows, 'inventory-balance', $request), 'fileName' => ReportExportFileName::stamp('bao-cao-ton-kho-hien-tai.xlsx'), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** @return array{bytes: string, fileName: string, rowCount: int, contentType: string} */
    private function exportBytesXnt(Request $request, string $format, string $prefix): array
    {
        $report = $this->buildXnt($request);
        $rows = array_map(fn (array $row): array => [
            $row['warehouse_code'], $row['product_code'], $row['product_name'], $row['stock_status'], $row['opening'],
            $row['receipt_gross'], $row['receipt_reversal'], $row['receipt_net'],
            $row['issue_gross'], $row['issue_reversal'], $row['issue_net'],
            $row['component_issue_gross'], $row['component_issue_reversal'], $row['component_issue_net'],
            $row['reversal_net'], $row['closing'],
        ], $report['lines']);
        $headers = [
            'Kho', 'Mã SKU', 'Tên SKU', 'Trạng thái tồn', 'Đầu kỳ',
            'Nhập (gross)', 'Đảo nhập', 'Nhập thuần',
            'Xuất (gross)', 'Đảo xuất', 'Xuất thuần',
            'Xuất linh kiện (gross)', 'Đảo xuất linh kiện', 'Xuất linh kiện thuần',
            'Reversal net', 'Cuối kỳ',
        ];
        $stem = "{$prefix}-{$report['from_date']}-{$report['to_date']}";

        return $format === 'pdf'
            ? ['bytes' => $this->buildPdfBytes('Nhập-Xuất-Tồn', $headers, $rows, 'inventory-xnt', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.pdf"), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->buildXlsxBytes('XNT', $headers, $rows, 'inventory-xnt', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.xlsx"), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** @return array{bytes: string, fileName: string, rowCount: int, contentType: string} */
    private function exportBytesDocumentHistory(Request $request, string $format): array
    {
        $report = $this->buildDocumentHistory($request);
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
        $stem = "lich-su-phieu-{$report['period']['from_date']}-{$report['period']['to_date']}";

        return $format === 'pdf'
            ? ['bytes' => $this->buildPdfBytes('Lịch sử phiếu', $headers, $rows, 'document-history', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.pdf"), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->buildXlsxBytes('Lich su phieu', $headers, $rows, 'document-history', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.xlsx"), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** @return array{bytes: string, fileName: string, rowCount: int, contentType: string} */
    private function exportBytesOutboundByAgency(Request $request, string $format): array
    {
        $report = $this->buildOutboundByAgency($request);
        $rows = array_map(fn (array $row): array => [
            $row['source_request_no'], $row['agency_code'], $row['agency_name'], $row['address'], $row['contact_masked'],
            $row['product_code'], $row['product_name'], $row['outbound_no'],
            $row['document_date'], $row['posted_at'], $row['required_qty'], $row['scanned_qty'],
        ], $report['rows']);
        $headers = ['Phiếu toa', 'Mã nơi nhận', 'Nơi nhận', 'Địa chỉ', 'Liên hệ', 'Mã SKU', 'Tên SKU', 'Phiếu xuất', 'Ngày phiếu', 'Posted at', 'Yêu cầu', 'Đã xuất'];
        $stem = "xuat-theo-noi-nhan-{$report['period']['from_date']}-{$report['period']['to_date']}";

        return $format === 'pdf'
            ? ['bytes' => $this->buildPdfBytes('Xuất theo nơi nhận', $headers, $rows, 'outbound-by-agency', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.pdf"), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->buildXlsxBytes('Xuat noi nhan', $headers, $rows, 'outbound-by-agency', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.xlsx"), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** @return array{bytes: string, fileName: string, rowCount: int, contentType: string} */
    private function exportBytesMovementTrace(Request $request, string $format): array
    {
        $report = $this->buildMovementTrace($request);
        $rows = array_map(fn (array $row): array => [
            $row['id'], $row['occurred_at'], $row['movement_type'], $row['warehouse_code'], $row['product_code'], $row['product_name'],
            $row['stock_status'], $row['qty_delta'], $row['item_id'], $row['container_id'], $row['physical_code'],
            $row['actor'], $row['source_type'], $row['source_id'], $row['original_movement_id'], $row['correction_chain_id'],
        ], $report['rows']);
        $headers = ['Movement ID', 'Thời gian', 'Movement', 'Kho', 'Mã SKU', 'Tên SKU', 'Stock status', 'Qty delta', 'Item', 'Container', 'Physical code', 'Người thực hiện', 'Source type', 'Source id', 'Original movement', 'Correction chain'];
        $stem = "movement-trace-{$report['period']['from_date']}-{$report['period']['to_date']}";

        return $format === 'pdf'
            ? ['bytes' => $this->buildPdfBytes('Movement Trace', $headers, $rows, 'movement-trace', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.pdf"), 'rowCount' => count($rows), 'contentType' => 'application/pdf']
            : ['bytes' => $this->buildXlsxBytes('Movement Trace', $headers, $rows, 'movement-trace', $request), 'fileName' => ReportExportFileName::stamp("{$stem}.xlsx"), 'rowCount' => count($rows), 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
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
