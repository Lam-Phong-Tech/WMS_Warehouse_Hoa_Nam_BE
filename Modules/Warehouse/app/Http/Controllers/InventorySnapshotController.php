<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Warehouse\Models\InventoryReportSnapshot;
use Modules\Warehouse\Services\InventorySnapshotService;
use OpenApi\Attributes as OA;

/** Snapshot tồn kho cuối kỳ — tối ưu tính tồn đầu kỳ cho báo cáo XNT. */
class InventorySnapshotController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/inventory-snapshots',
        operationId: 'inventory_snapshot_list',
        summary: 'Danh sách snapshot tồn kho chốt cuối kỳ',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory / Stock'],
        parameters: [
            new OA\Parameter(name: 'snapshot_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'period_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['daily', 'weekly', 'monthly'])),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách snapshot tồn kho'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inventory.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = InventoryReportSnapshot::with(['warehouse', 'product']);

        if ($request->filled('snapshot_date')) {
            $query->whereDate('snapshot_date', $request->input('snapshot_date'));
        }
        if ($request->filled('period_type')) {
            $query->where('period_type', $request->input('period_type'));
        }
        if ($request->filled('period_start')) {
            $query->whereDate('period_start', '>=', $request->input('period_start'));
        }
        if ($request->filled('period_end')) {
            $query->whereDate('period_end', '<=', $request->input('period_end'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $perPage = (int) ($request->get('per_page', 50));
        $perPage = max(1, min($perPage, 100));

        return $this->respondPaginated(
            $query->orderByDesc('snapshot_date')->orderBy('warehouse_id')->paginate($perPage)
        );
    }

    #[OA\Post(
        path: '/api/v1/inventory-snapshots/generate',
        operationId: 'inventory_snapshot_generate',
        summary: 'Chốt snapshot tồn kho theo ngày/kỳ',
        description: 'Sao chụp toàn bộ tồn (đầu kỳ, nhập, xuất, điều chuyển, điều chỉnh, cuối kỳ) thành snapshot cho ngày/kỳ chỉ định bằng cách tổng hợp dữ liệu biến động kho.',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory / Stock'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['snapshot_date'],
                properties: [
                    new OA\Property(property: 'snapshot_date', type: 'string', format: 'date', example: '2026-07-30'),
                    new OA\Property(property: 'period_type', type: 'string', enum: ['daily', 'weekly', 'monthly'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Chốt snapshot thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function generate(Request $request, InventorySnapshotService $snapshotService)
    {
        $today = now()->toDateString();

        $validator = Validator::make($request->all(), [
            'snapshot_date' => 'required|date|before_or_equal:today',
            'period_type' => 'nullable|string|in:daily,weekly,monthly',
        ], [
            'snapshot_date.before_or_equal' => "Ngày chốt snapshot không được vượt quá ngày hiện tại ({$today}).",
        ]);

        if ($validator->fails()) {
            return $this->respondError('Dữ liệu đầu vào không hợp lệ', $validator->errors()->toArray(), 422, 'VALIDATION_FAILED');
        }

        $date = $request->input('snapshot_date');
        $periodType = $request->input('period_type', 'daily');

        $count = $snapshotService->generatePeriodSnapshot($periodType, $date);
        [$periodStart, $periodEnd, $snapshotDate] = $snapshotService->resolvePeriod($periodType, $date);

        return $this->respondSuccess([
            'period_type' => $periodType,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'snapshot_date' => $snapshotDate,
            'records' => $count,
        ], "Đã chốt snapshot tồn kho kỳ {$periodType}");
    }
}
