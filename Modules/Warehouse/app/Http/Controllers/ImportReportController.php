<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Services\ImportErrorFileService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportReportController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/skus/import-reports',
        operationId: 'sku_import_report_list',
        summary: 'Danh sách Import báo cáo SKU (dry-run và commit) — WWHN-224',
        description: 'Mỗi phần tử có `error_file_available` (boolean, WWHN-330): true khi báo cáo đó còn file lỗi CSV '
            .'tải được ngay lúc gọi (đã sinh file và chưa quá `error_file_expires_at`). Client dùng đúng field này để '
            .'bật/tắt nút tải, KHÔNG tự so `error_file_expires_at` với đồng hồ máy client. Đường dẫn lưu trữ nội bộ '
            .'`error_file_path` không được trả ra ngoài. `duplicate_of_replay` = true nghĩa là báo cáo này đã từng '
            .'được trả lại cho một lần import trùng file (idempotent replay).',
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

        $query = ImportReport::query()->where('import_type', 'SKU');

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

        return $this->respondPaginated($reports);
    }

    #[OA\Get(
        path: '/api/v1/skus/import-reports/{id}',
        operationId: 'sku_import_report_detail',
        summary: 'Chi tiết một Import báo cáo SKU — WWHN-224',
        description: 'Trả về `error_file_available` (boolean, WWHN-330) — nguồn sự thật duy nhất để client quyết định '
            .'hiển thị nút tải file lỗi; đã tính cả điều kiện hết hạn tại server. `error_file_expires_at` chỉ dùng để '
            .'hiển thị mốc thời gian cho người dùng. `error_file_path` (đường dẫn nội bộ) không được trả ra ngoài.',
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
        $report = ImportReport::query()->where('import_type', 'SKU')->find($id);

        return $report
            ? $this->respondSuccess($report)
            : $this->respondNotFound('Không tìm thấy Import báo cáo.');
    }

    #[OA\Get(
        path: '/api/v1/skus/import-reports/{id}/error-file',
        operationId: 'sku_import_report_error_file',
        summary: 'Tải file lỗi (CSV) của một Import báo cáo SKU — WWHN-330',
        description: 'Trả về file CSV (đã sinh sẵn khi import, xem ImportErrorFileService) gồm cột '
            .'row/field/error_code/message/value cho từng dòng bị reject trong lần import đó. '
            .'Yêu cầu quyền sku.manage (cùng permission với các endpoint import khác). '
            .'File lỗi hết hạn sau '.ImportErrorFileService::EXPIRY_HOURS.'h kể từ lúc sinh (error_file_expires_at) — '
            .'giá trị tạm, cần BA/Tech Lead chốt chính thức. Giá trị trong CSV đã được sanitize khi sinh file '
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
        $report = ImportReport::query()->where('import_type', 'SKU')->find($id);
        if (! $report) {
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
