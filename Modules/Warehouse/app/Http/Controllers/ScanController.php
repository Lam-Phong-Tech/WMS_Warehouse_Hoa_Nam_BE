<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\Warehouse;
use OpenApi\Attributes as OA;

/**
 * Scan classify - Phân loại mã QR/Barcode/Container/Item trước khi FE áp vào inbound/outbound/stocktake/warranty.
 */
#[OA\Post(
    path: '/api/v1/scan/classify',
    operationId: 'scan_classify',
    summary: 'Phân loại mã scan (QR/Barcode/Container/Item)',
    description: 'Nhận raw_code và context, trả về classification cùng thông tin đối tượng tương ứng. Contract G1: scan chỉ classify/validate, stock_effect=NONE, không tạo stock movement.',
    security: [['bearerAuth' => []]],
    tags: ['11. Scan'],
    parameters: [
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['raw_code', 'context', 'warehouse_id'],
            properties: [
                new OA\Property(property: 'raw_code', type: 'string', maxLength: 120, description: 'Mã QR/Barcode/Container/Item cần phân loại'),
                new OA\Property(property: 'context', type: 'string', enum: ['INBOUND', 'OUTBOUND', 'STOCKTAKE', 'WARRANTY_TEMP_ONLY'], description: 'Ngữ cảnh sử dụng'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'ID kho tổng Hoa Nam'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Phân loại thành công'),
        new OA\Response(response: 400, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền scan.classify'),
        new OA\Response(response: 404, description: 'Không tìm thấy mã'),
        new OA\Response(response: 409, description: 'Trạng thái kho/mã không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class ScanController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        // Permission check is handled by middleware
    }

    /**
     * Phân loại mã scan.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function classify(Request $request)
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }

        $validated = $request->validate([
            'raw_code' => 'required|string|max:120',
            'context' => 'required|string|in:INBOUND,OUTBOUND,STOCKTAKE,WARRANTY_TEMP_ONLY',
            'warehouse_id' => 'required|uuid|exists:warehouses,id',
        ]);

        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);
        if ($warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'STATE_CONFLICT', "Kho \"{$warehouse->name}\" đang ngừng hoạt động.");
        }

        // TODO: Integrate with actual physical code lookup service
        // For now, return a mock classification
        // In production, this should query physical_codes, items, containers, etc.
        $classification = $this->classifyCode($validated['raw_code'], $validated['context']);

        if ($classification === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', "Không tìm thấy mã \"{$validated['raw_code']}\".");
        }

        return $this->respondSuccess([
            'raw_code' => $validated['raw_code'],
            'classification' => $classification['type'],
            'item_id' => $classification['item_id'] ?? null,
            'sku_id' => $classification['sku_id'] ?? null,
            'stock_status' => $classification['stock_status'] ?? 'AVAILABLE',
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'current_location' => [
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
            ],
        ], 'Scan classified');
    }

    /**
     * Classify a raw code based on context.
     *
     * TODO: Replace with actual service call to PhysicalCodeService or similar
     *
     * @return array|null
     */
    private function classifyCode(string $rawCode, string $context): ?array
    {
        // Placeholder implementation
        // In production, this should:
        // 1. Look up physical_codes table by code_value
        // 2. Resolve to item/container/sku based on context
        // 3. Return classification with current stock status and location

        // Mock response for development
        if (str_starts_with($rawCode, 'QR-HN-')) {
            return [
                'type' => 'ITEM',
                'item_id' => '00000000-0000-0000-0000-000000000001',
                'sku_id' => '00000000-0000-0000-0000-000000000001',
                'stock_status' => 'AVAILABLE',
            ];
        }

        if (str_starts_with($rawCode, 'CTN-')) {
            return [
                'type' => 'CONTAINER',
                'item_id' => null,
                'sku_id' => null,
                'stock_status' => 'AVAILABLE',
            ];
        }

        return null;
    }
}
