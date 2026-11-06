<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\ReportExportFileName;
use App\Support\ReportExportMemory;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;
use Modules\Warehouse\Support\SkuImportContract;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportReportController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    /**
     * Import báo cáo SKU không có đường GHI nào trong controller này — bản ghi do
     * ImportController tạo ra. Vì vậy namespace phiên bản `master:version` chỉ được
     * bump bởi các danh mục khác, và lưới an toàn thật sự ở đây là TTL: dùng 'search'
     * (120s) thay vì 'master' (1800s) để một lần import vừa chạy xong không bị khuất
     * khỏi danh sách quá lâu.
     */
    private const CACHE_VERSION_NS = 'master:version';

    /**
     * WWHN-331 FE331-P1-06R3 — data-scope của Import báo cáo SKU, áp cho CẢ BA route
     * (list, detail, tải file lỗi) qua đúng một query duy nhất.
     *
     * Permission `sku.manage` chỉ trả lời "được dùng chức năng import hay không"; nó
     * không trả lời "được đọc lần import của ai". Trước thay đổi này, bất kỳ ai có
     * `sku.manage` cũng liệt kê được mọi Import báo cáo và tải được file lỗi CSV của
     * người khác — mà file lỗi chứa nguyên nội dung file người đó nộp (mã SKU, tên
     * hàng, giá trị từng ô sai).
     *
     * Quy tắc đã chốt (DEC-IMP-001, mục Data scope):
     * - GLOBAL: người gọi không bị giới hạn kho (`User::warehouseScopeIds() === null`,
     *   tức SUPER_ADMIN hoặc vai trò hệ thống không gắn scope_ref) → đọc được mọi báo
     *   cáo, vì đây chính là vai trò cần đối soát chéo toàn bộ master data.
     * - ACTOR_SCOPED: người gọi bị giới hạn kho → chỉ đọc được báo cáo do chính mình
     *   tạo (`actor_id`).
     *
     * Lấy đúng nguồn scope (`scope_ref`) mà EnforceInventoryWarehouseScope và
     * /reports/dashboard-quick đang dùng nên không phát sinh permission mới và không có
     * nguồn scope thứ hai để lệch nhau. SKU là master data toàn cục (không có
     * warehouse_id) nên không thể áp scope theo kho lên chính báo cáo — thứ cần bảo vệ
     * là nội dung file của người nộp.
     */
    private function scopedQuery(): Builder
    {
        $user = auth('api')->user();
        $query = ImportReport::query()->where('import_type', 'SKU');
        $actorScope = SkuImportContract::reportActorScope($user instanceof User ? $user : null);

        return $actorScope === null ? $query : $query->where('actor_id', $actorScope);
    }

    /**
     * {id} tới từ route path, không đi qua $request->validate() như các query param
     * khác của controller này. Chặn ở đây để chuỗi rác trả 422 rõ ràng thay vì rơi
     * xuống DB driver (PostgreSQL ném lỗi kiểu uuid, sqlite lại âm thầm trả rỗng).
     */
    private function assertValidId(string $id): void
    {
        if (! Str::isUuid($id)) {
            ApiError::abort(422, 'INVALID_UUID', 'id không phải UUID hợp lệ.', ['field' => 'id']);
        }
    }

    /** Nhãn phạm vi đang áp dụng, trả trong meta để QA/BA có bằng chứng đọc được ngay. */
    private function dataScopeLabel(): string
    {
        $user = auth('api')->user();

        return SkuImportContract::reportActorScope($user instanceof User ? $user : null) === null
            ? 'GLOBAL'
            : 'ACTOR_SCOPED';
    }

    #[OA\Get(
        path: '/api/v1/skus/import-reports',
        operationId: 'sku_import_report_list',
        summary: 'Danh sách Import báo cáo SKU (dry-run và commit) — WWHN-224',
        description: 'Mỗi phần tử có `error_file_available` (boolean, WWHN-330): true khi báo cáo đó còn file lỗi CSV '
            .'tải được ngay lúc gọi (đã sinh file và chưa quá `error_file_expires_at`). Client dùng đúng field này để '
            .'bật/tắt nút tải, KHÔNG tự so `error_file_expires_at` với đồng hồ máy client. Đường dẫn lưu trữ nội bộ '
            .'`error_file_path` không được trả ra ngoài. `duplicate_of_replay` = true nghĩa là báo cáo này đã từng '
            .'được trả lại cho một lần import trùng ĐỦ định danh (idempotent replay); `options_fingerprint` là vân tay '
            .'tùy chọn của lần import đó (sha256 của `update_existing=0|1`). '
            .'WWHN-331 FE331-P1-03R3: `meta.constraints` mang hạn mức import máy chủ đang áp dụng (DEC-IMP-001) để '
            .'client hiển thị mà không hard-code lại con số. '
            .'WWHN-331 FE331-P1-06R3 DATA-SCOPE: `meta.data_scope` = GLOBAL khi người gọi không bị giới hạn kho '
            .'(xem được mọi Import báo cáo SKU), = ACTOR_SCOPED khi người gọi bị giới hạn kho (chỉ thấy báo cáo do '
            .'chính mình tạo). Cùng nguồn scope_ref với /inventory/balances và /reports/dashboard-quick.',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'mode', in: 'query', description: 'DRY_RUN hoặc COMMIT', schema: new OA\Schema(type: 'string', enum: ['DRY_RUN', 'COMMIT'])),
            new OA\Parameter(name: 'status', in: 'query', description: 'VALID | REJECTED | COMMITTED', schema: new OA\Schema(type: 'string', enum: ['VALID', 'REJECTED', 'COMMITTED'])),
            new OA\Parameter(name: 'file_sha256', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách Import báo cáo, phân trang'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing sku.manage permission'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'mode' => ['nullable', 'string', 'in:DRY_RUN,COMMIT'],
            'status' => ['nullable', 'string', 'in:VALID,REJECTED,COMMITTED'],
            // import_reports.file_sha256 là varchar(64) — hex sha256 luôn đúng 64 ký tự.
            'file_sha256' => ['nullable', 'string', 'max:64'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // actorId BẮT BUỘC trong khoá: scopedQuery() lọc theo `actor_id` khi người gọi bị
        // giới hạn kho (FE331-P1-06R3), nên hai người CÙNG warehouse-scope vẫn phải thấy
        // hai danh sách khác nhau — thiếu actorId là để người này đọc báo cáo import của
        // người kia. TTL 'search' (120s): xem ghi chú ở CACHE_VERSION_NS.
        $cached = $this->cachedReadPayload(
            'warehouse:sku-import-report:list',
            $this->readFiltersWithPaging($request),
            function () use ($validated, $perPage): array {
                $query = $this->scopedQuery();

                if (isset($validated['mode'])) {
                    $query->where('mode', $validated['mode']);
                }
                if (isset($validated['status'])) {
                    $query->where('status', $validated['status']);
                }
                if (isset($validated['file_sha256'])) {
                    $query->where('file_sha256', $validated['file_sha256']);
                }

                $page = $query->orderByDesc('created_at')->paginate($perPage);

                return ['items' => $page->items(), 'total' => $page->total()];
            },
            'search',
            self::CACHE_VERSION_NS,
            $this->readCacheActorId(),
        );

        $response = $this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ));

        // WWHN-331 FE331-P1-03R3: đây là endpoint đọc duy nhất mà màn hình import gọi
        // được TRƯỚC khi người dùng chọn file, nên hợp đồng hạn mức đi kèm ngay trong
        // meta — UI hiển thị hạn mức máy chủ đang áp dụng thay vì tự viết lại con số.
        // FE331-P1-06R3: kèm luôn nhãn data-scope để QA có bằng chứng đọc được ngay
        // trên response, không phải suy từ số dòng trả về.
        $payload = $response->getData(true);
        $payload['meta']['constraints'] = SkuImportContract::toArray();
        $payload['meta']['data_scope'] = $this->dataScopeLabel();
        $response->setData($payload);

        return $response;
    }

    #[OA\Get(
        path: '/api/v1/skus/import-reports/{id}',
        operationId: 'sku_import_report_detail',
        summary: 'Chi tiết một Import báo cáo SKU — WWHN-224',
        description: 'Trả về `error_file_available` (boolean, WWHN-330) — nguồn sự thật duy nhất để client quyết định '
            .'hiển thị nút tải file lỗi; đã tính cả điều kiện hết hạn tại server. `error_file_expires_at` chỉ dùng để '
            .'hiển thị mốc thời gian cho người dùng. `error_file_path` (đường dẫn nội bộ) không được trả ra ngoài. '
            .'WWHN-331 FE331-P1-06R3 DATA-SCOPE: người gọi bị giới hạn kho chỉ đọc được báo cáo do chính mình tạo; '
            .'báo cáo của người khác trả 404 y hệt báo cáo không tồn tại (fail-closed, không xác nhận sự tồn tại).',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết Import báo cáo'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing sku.manage permission'),
            new OA\Response(response: 404, description: 'Không tìm thấy Import báo cáo'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        // {id} không có route constraint uuid nên chuỗi rác lọt thẳng xuống Eloquent
        // find(); trên PostgreSQL, so sánh cột kiểu uuid với chuỗi không hợp lệ ném
        // QueryException (22P02) → 500 thay vì 422 rõ ràng. Chặn sớm tại đây.
        $this->assertValidId($id);

        // FE331-P1-06R3: bản ghi ngoài data-scope trả 404 y hệt bản ghi không tồn tại —
        // fail-closed và không lộ việc "có tồn tại một báo cáo id này của người khác".
        // Vì thế actorId BẮT BUỘC nằm trong khoá: một cache hit dùng chung giữa hai
        // người sẽ biến 404 fail-closed thành 200 lộ dữ liệu. TTL 'search' (120s).
        $cached = $this->cachedReadPayload(
            'warehouse:sku-import-report:detail',
            ['import_report_id' => $id],
            fn (): array => ['report' => $this->scopedQuery()->find($id)],
            'search',
            self::CACHE_VERSION_NS,
            $this->readCacheActorId(),
        );

        return ($cached['report'] ?? null) !== null
            ? $this->respondSuccess($cached['report'])
            : $this->respondNotFound('Không tìm thấy Import báo cáo.');
    }

    #[OA\Get(path: '/api/v1/skus/import-reports/{id}/rows', operationId: 'sku_import_report_rows', summary: 'Các dòng lỗi đã lưu của một lần nhập SKU', description: 'Chỉ trả các dòng lỗi trong import_reports.row_errors. Dòng hợp lệ của tệp nguồn không được lưu. Cùng actor scope với chi tiết báo cáo; raw_value được che PII và escape công thức.', security: [['bearerAuth' => []]], tags: ['05. Product'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 403, description: 'Thiếu quyền sku.manage'), new OA\Response(response: 200, description: 'Trang các dòng lỗi đã lưu'), new OA\Response(response: 404, description: 'Báo cáo ngoài phạm vi hoặc không tồn tại')])]
    public function rows(Request $request, string $id, ImportErrorFileService $errorFiles): JsonResponse
    {
        $this->assertValidId($id);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $report = $this->scopedQuery()->find($id);
        if (! $report) {
            return $this->respondNotFound('Không tìm thấy Import báo cáo.');
        }

        $all = $errorFiles->previewRows(is_array($report->row_errors) ? $report->row_errors : []);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        $response = $this->respondPaginated(new LengthAwarePaginator($slice, count($all), $perPage, $page, ['path' => $request->url(), 'pageName' => 'page']));
        $payload = $response->getData(true);
        $payload['meta']['data_scope'] = $this->dataScopeLabel();
        $payload['meta']['row_source'] = 'PERSISTED_ERRORS_ONLY';
        $response->setData($payload);

        return $response;
    }

    #[OA\Get(path: '/api/v1/skus/import-reports/export-excel', operationId: 'sku_import_quality_export', summary: 'Xuất đúng báo cáo lần nhập SKU và các dòng lỗi đã lưu', description: 'Nguồn import_reports import_type=SKU, không phải reports/import-match (đối soát toa xuất). Áp cùng actor scope với list/detail. Tối đa 10.000 dòng, che PII, escape công thức Excel.', security: [['bearerAuth' => []]], tags: ['05. Product'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'result', in: 'query', schema: new OA\Schema(type: 'string', enum: ['SUCCESS', 'FAILED', 'DUPLICATE'])), new OA\Parameter(name: 'issues_only', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'XLSX đúng nguồn SKU import'), new OA\Response(response: 403, description: 'Thiếu quyền sku.manage'), new OA\Response(response: 422, description: 'Bộ lọc sai hoặc vượt trần xuất')])]
    public function exportExcel(Request $request, ImportErrorFileService $errorFiles): StreamedResponse
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'result' => ['nullable', 'in:SUCCESS,FAILED,DUPLICATE'],
            'issues_only' => ['nullable', 'boolean'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'mode' => ['nullable', 'in:DRY_RUN,COMMIT'],
            'status' => ['nullable', 'in:VALID,REJECTED,COMMITTED'],
        ]);

        $query = $this->scopedQuery();
        if (isset($validated['from_date'])) $query->whereDate('created_at', '>=', $validated['from_date']);
        if (isset($validated['to_date'])) $query->whereDate('created_at', '<=', $validated['to_date']);
        if (isset($validated['mode'])) $query->where('mode', $validated['mode']);
        if (isset($validated['status'])) $query->where('status', $validated['status']);

        $rows = [];
        $keyword = mb_strtolower(trim((string) ($validated['keyword'] ?? '')));
        foreach ($query->orderByDesc('created_at')->cursor() as $report) {
            $errors = $errorFiles->previewRows(is_array($report->row_errors) ? $report->row_errors : []);
            if ($keyword !== '' && ! str_contains(mb_strtolower($report->getKey().' '.($report->file_name ?? '')), $keyword)) continue;
            if (($validated['result'] ?? '') === 'SUCCESS' && $report->valid_rows < 1) continue;
            $duplicateCount = collect($errors)->filter(fn (array $row): bool => $this->isDuplicateImportError($row['error_code']))->count();
            if (($validated['result'] ?? '') === 'FAILED' && $report->error_rows - $duplicateCount < 1) continue;
            if (($validated['result'] ?? '') === 'DUPLICATE' && $duplicateCount < 1) continue;
            if (($validated['issues_only'] ?? false) && $report->error_rows < 1) continue;

            foreach ($errors === [] ? [null] : $errors as $error) {
                $rows[] = [
                    $report->getKey(), $report->file_name, $report->created_at?->toIso8601String(),
                    $report->mode, $report->status, $report->total_rows, $report->valid_rows, $report->error_rows,
                    $error['row_no'] ?? '', $error['field'] ?? '', $error['error_code'] ?? '',
                    $error['message'] ?? '', $error['raw_value'] ?? '', $error['severity'] ?? '',
                    $error['suggested_action'] ?? '',
                ];
                if (count($rows) > 10000) ApiError::abort(422, 'EXPORT_ROW_LIMIT', 'Vượt giới hạn 10.000 dòng xuất.');
            }
        }

        AuditLogger::record(
            action: 'REPORT_EXPORTED', objectType: 'report', objectId: null,
            actorId: auth('api')->id() !== null ? (string) auth('api')->id() : null,
            requestId: $request->attributes->get('request_id'),
            afterData: ['report' => 'sku-import-quality', 'format' => 'xlsx', 'row_count' => count($rows), 'filters' => $validated],
        );

        $name = ReportExportFileName::stamp('sku-import-quality.xlsx');
        return response()->streamDownload(function () use ($rows): void {
            ReportExportMemory::raise();
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('SKU Import Quality');
            $sheet->fromArray(['Import ID', 'Tên tệp', 'Thời gian', 'Chế độ', 'Trạng thái', 'Tổng dòng', 'Dòng hợp lệ', 'Dòng lỗi', 'Dòng nguồn', 'Trường', 'Mã lỗi', 'Mô tả', 'Giá trị nguồn', 'Mức độ', 'Hướng xử lý'], null, 'A1');
            foreach ($rows as $index => $row) {
                $sheet->fromArray(array_map(static function (mixed $value): mixed {
                    if (! is_string($value)) return $value;
                    $trimmed = ltrim($value);
                    return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true) && ! str_starts_with($value, "'") ? "'".$value : $value;
                }, $row), null, 'A'.($index + 2));
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function isDuplicateImportError(string $code): bool
    {
        $upper = strtoupper($code);

        return str_contains($upper, 'DUPLICATE')
            || str_contains($upper, 'ALREADY_EXISTS')
            || str_contains($upper, 'ALREADY_MATCHED');
    }

    #[OA\Get(
        path: '/api/v1/skus/import-reports/{id}/error-file',
        operationId: 'sku_import_report_error_file',
        summary: 'Tải file lỗi (CSV) của một Import báo cáo SKU — WWHN-330',
        description: 'Trả về file CSV (đã sinh sẵn khi import, xem ImportErrorFileService) theo ĐÚNG schema error-file '
            .'của SRS §13.12: `source_file, sheet, row_no, field, error_code, message, raw_value, severity, '
            .'suggested_action` — một dòng cho mỗi dòng bị reject. `severity` ∈ CRITICAL (xung đột định danh/tham '
            .'chiếu) | ERROR (sai định dạng ô); trong luồng all-or-nothing mọi dòng lỗi đều chặn commit nên không có '
            .'giá trị WARNING. `raw_value` được che nếu khớp dạng email/số điện thoại (§13.12 "Masked if PII"). '
            .'RETENTION (DEV330-P1-01R3, DEC-IMP-001 §5): hạn dùng LOGIC = `constraints.error_file_expiry_hours` — quá '
            .'hạn thì endpoint này trả 404 nhưng file vẫn còn trên đĩa; RETENTION VẬT LÝ do job `import:purge-error-files` '
            .'thực thi hằng ngày, nên thời gian tồn tại tối đa trên đĩa = `constraints.error_file_max_on_disk_hours`. '
            .'Sau khi bị purge, endpoint vẫn trả 404 (bản ghi Import báo cáo và row_errors KHÔNG bị xoá — audit trail '
            .'import giữ nguyên theo NFR-SRS-014). '
            .'Yêu cầu quyền sku.manage (cùng permission với các endpoint import khác) VÀ báo cáo phải nằm trong '
            .'data-scope của người gọi (FE331-P1-06R3): người gọi bị giới hạn kho chỉ tải được file lỗi của chính '
            .'lần import mình đã chạy — file lỗi chứa nguyên nội dung file người nộp nên báo cáo ngoài scope trả 404 '
            .'y hệt báo cáo không tồn tại. '
            .'Hạn dùng file lỗi lấy từ hợp đồng đã duyệt `warehouse.sku_import.error_file_expiry_hours` (DEC-IMP-001) '
            .'và được trả ra trong `constraints` của response import / `meta.constraints` của danh sách báo cáo; '
            .'mốc hết hạn cụ thể của từng báo cáo nằm ở `error_file_expires_at`. Giá trị trong CSV đã được sanitize khi sinh file '
            .'(bỏ ký tự điều khiển, chặn CSV/formula injection khi mở bằng Excel) và chỉ gồm dữ liệu đã nằm trong '
            .'row_errors của chính báo cáo đó — không lộ thêm thông tin ngoài phạm vi request gốc.',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File CSV lỗi import (text/csv)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing sku.manage permission'),
            new OA\Response(response: 404, description: 'Không tìm thấy Import báo cáo, không có dòng lỗi, hoặc file lỗi đã hết hạn'),
        ]
    )]
    public function downloadErrorFile(string $id): JsonResponse|StreamedResponse
    {
        // KHÔNG cache: endpoint này trả nội dung tệp (StreamedResponse) chứ không phải
        // payload JSON, và hạn dùng file lỗi phải được đánh giá tại đúng thời điểm gọi.
        // Cùng lý do với show(): chặn chuỗi rác trước khi chạm Eloquent find() để
        // không lộ QueryException/500 khi cột id là uuid trên PostgreSQL.
        $this->assertValidId($id);

        /** @var ImportReport|null $report */
        $report = $this->scopedQuery()->find($id);
        if (! $report) {
            // FE331-P1-06R3: gồm cả ca "báo cáo của người khác" — file lỗi CSV chứa
            // nguyên nội dung file người đó nộp nên không được phục vụ ra ngoài scope,
            // và cũng không được xác nhận sự tồn tại của nó.
            return $this->respondNotFound('Không tìm thấy Import báo cáo.');
        }

        // WWHN-330 P1-01: file lỗi được sinh sẵn lúc import (ImportController::attachErrorFile
        // → ImportErrorFileService::generate), path + expiry lưu trên chính report. Không có
        // path nghĩa là báo cáo này không có dòng lỗi (hoặc sinh file thất bại, đã log riêng).
        if ($report->error_file_path === null || $report->error_file_expires_at === null) {
            return $this->respondNotFound('Import báo cáo này không có file lỗi để tải.');
        }
        if (now()->greaterThan($report->error_file_expires_at)) {
            return $this->respondNotFound('File lỗi của Import báo cáo này đã hết hạn.');
        }

        $disk = Storage::disk(ImportErrorFileService::diskName());
        if (! $disk->exists($report->error_file_path)) {
            // UAT TC_FUNC_05: path còn hạn nhưng file không có trên đĩa (seed
            // backfill path "chay", đổi disk giữa hai môi trường, file bị dọn
            // tay…). row_errors vẫn nằm nguyên trong DB (purge không bao giờ
            // xoá cột này) nên tái tạo được file y nguyên thay vì 404.
            $rowErrors = is_array($report->row_errors) ? $report->row_errors : [];
            $generated = $rowErrors === []
                ? null
                : app(ImportErrorFileService::class)->generate(
                    (string) $report->import_report_id,
                    $rowErrors,
                    (string) $report->file_name,
                    null,
                );
            if ($generated === null) {
                return $this->respondNotFound('Không tìm thấy file lỗi (có thể đã bị dọn dẹp).');
            }
            // Ghi lại path THẬT vừa sinh (path cũ có thể trỏ thư mục khác);
            // giữ nguyên hạn dùng cũ — tái tạo không phải là gia hạn.
            $report->forceFill(['error_file_path' => $generated['path']])->save();
        }

        return $disk->download(
            $report->error_file_path,
            'sku-import-errors-'.$report->import_report_id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
