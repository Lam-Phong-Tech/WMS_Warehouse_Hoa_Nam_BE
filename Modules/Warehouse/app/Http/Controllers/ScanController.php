<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\ScanClassificationService;
use OpenApi\Attributes as OA;

/**
 * Scan classify is a read-only boundary. It resolves and validates a code only;
 * inventory posting is owned by the dedicated inbound/outbound/stocktake actions.
 */
#[OA\Post(
    path: '/api/v1/scan/classify',
    operationId: 'scan_classify',
    summary: 'Phân loại và validate mã scan, không thay đổi tồn kho',
    description: 'Contract WWHN-150: endpoint chỉ đọc dữ liệu để resolve ITEM/CONTAINER/SKU theo warehouse data scope. stock_effect luôn bằng NONE; không tạo inventory balance, stock movement, posting_event, scan_event hoặc idempotency cache. Header Idempotency-Key nếu client gửi lên được bỏ qua vì đây không phải posting operation.',
    security: [['bearerAuth' => []]],
    tags: ['11. Scan'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['raw_code', 'context', 'warehouse_id'],
            properties: [
                new OA\Property(property: 'raw_code', type: 'string', maxLength: 150, description: 'QR, barcode, serial, container code hoặc SKU code'),
                new OA\Property(property: 'context', type: 'string', enum: ['INBOUND', 'OUTBOUND', 'STOCKTAKE', 'WARRANTY_TEMP_ONLY']),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'Kho nằm trong data scope của người dùng'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Resolve thành công; stock_effect=NONE và toàn bộ write flags=false'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Thiếu scan.classify hoặc kho ngoài data scope'),
        new OA\Response(response: 404, description: 'Không tìm thấy mã scan'),
        new OA\Response(response: 409, description: 'Kho/mã/object/context không hợp lệ'),
        new OA\Response(response: 422, description: 'Payload validation thất bại'),
    ]
)]
class ScanController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ScanClassificationService $classifier) {}

    public function classify(Request $request)
    {
        $validated = $request->validate([
            'raw_code' => ['required', 'string', 'max:150'],
            'context' => ['required', 'string', 'in:INBOUND,OUTBOUND,STOCKTAKE,WARRANTY_TEMP_ONLY'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Người dùng chưa được xác thực.');
        }
        if (! $user->canAccessWarehouse((string) $validated['warehouse_id'])) {
            ApiError::abort(403, 'WAREHOUSE_SCOPE_DENIED', 'Kho yêu cầu nằm ngoài data scope.', [
                'warehouse_id' => (string) $validated['warehouse_id'],
            ]);
        }

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        if (strtoupper((string) $warehouse->status) !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', "Kho \"{$warehouse->name}\" đang ngừng hoạt động.");
        }

        $classification = $this->classifier->classify(
            (string) $validated['raw_code'],
            (string) $validated['context'],
            $warehouse
        );

        return $this->respondSuccess([
            'raw_code' => trim((string) $validated['raw_code']),
            'context' => (string) $validated['context'],
            ...$classification,
            'current_location' => [
                'warehouse_id' => (string) $warehouse->id,
                'warehouse_code' => (string) $warehouse->code,
                'warehouse_name' => (string) $warehouse->name,
            ],
        ], 'Scan classified without stock effect');
    }
}
