<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\ImportReport;
use OpenApi\Attributes as OA;

class ImportReportController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/skus/import-reports',
        operationId: 'sku_import_report_list',
        summary: 'Danh sách Import báo cáo SKU (dry-run và commit) — WWHN-224',
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
}
