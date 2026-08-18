<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;
use Modules\Warehouse\Support\SkuImportContract;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportReportController extends Controller
{
    use ApiResponse;

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
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
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
            'file_sha256' => ['nullable', 'string'],
        ]);

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

        $reports = $query->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 15));

        $response = $this->respondPaginated($reports);

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
        // FE331-P1-06R3: bản ghi ngoài data-scope trả 404 y hệt bản ghi không tồn tại —
        // fail-closed và không lộ việc "có tồn tại một báo cáo id này của người khác".
        $report = $this->scopedQuery()->find($id);

        return $report
            ? $this->respondSuccess($report)
            : $this->respondNotFound('Không tìm thấy Import báo cáo.');
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

        $disk = Storage::disk(ImportErrorFileService::DISK);
        if (! $disk->exists($report->error_file_path)) {
            return $this->respondNotFound('Không tìm thấy file lỗi (có thể đã bị dọn dẹp).');
        }

        return $disk->download(
            $report->error_file_path,
            'sku-import-errors-'.$report->import_report_id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
