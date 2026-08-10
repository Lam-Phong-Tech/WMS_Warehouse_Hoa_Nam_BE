<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use Modules\Warehouse\Models\StockMovement;
use OpenApi\Attributes as OA;

/**
 * Warranty - Bảo hành.
 */
#[OA\Post(
    path: '/api/v1/warranty-cases/{id}/temp-only',
    operationId: 'warranty_temp_only',
    summary: 'Nhận tạm bảo hành (TEMP_ONLY)',
    description: 'Nhận tạm sản phẩm vào kho để kiểm tra bảo hành. TEMP_ONLY item không được bán và phải có thể truy vết/đảo ngược. Chỉ áp dụng khi case đang ở trạng thái RECEIVED hoặc WAITING_CHECK.',
    security: [['bearerAuth' => []]],
    tags: ['13. Warranty'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['item_id', 'condition_status'],
            properties: [
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', description: 'ID item/serial number'),
                new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', nullable: true, description: 'ID defect catalog'),
                new OA\Property(property: 'condition_status', type: 'string', enum: ['WAITING_CHECK', 'INSPECTING'], description: 'Trạng thái kiểm tra'),
                new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true, description: 'Ghi chú'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Nhận tạm thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền warranty.temp_only'),
        new OA\Response(response: 404, description: 'Không tìm thấy case'),
        new OA\Response(response: 409, description: 'Case đã ở trạng thái TEMP_ONLY hoặc đã đóng'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class WarrantyController extends Controller
{
    use ApiResponse;

    /**
     * Nhận tạm bảo hành (TEMP_ONLY).
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function tempOnly(Request $request, string $id)
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }

        $validated = $request->validate([
            'item_id' => 'required|uuid|exists:inventory_serials,id',
            'defect_id' => 'nullable|uuid|exists:defect_catalog,defect_id',
            'condition_status' => 'required|string|in:WAITING_CHECK,INSPECTING',
            'note' => 'nullable|string|max:1000',
        ]);

        $case = WarrantyCase::findOrFail($id);

        // Validate case status
        if (! in_array($case->status, [WarrantyCase::STATUS_RECEIVED, WarrantyCase::STATUS_WAITING_CHECK], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Case bảo hành phải ở trạng thái RECEIVED hoặc WAITING_CHECK để nhận tạm.');
        }

        if ($case->status === WarrantyCase::STATUS_TEMP_ONLY) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Case đã ở trạng thái TEMP_ONLY rồi.');
        }

        if (in_array($case->status, [WarrantyCase::STATUS_CLOSED, WarrantyCase::STATUS_CANCELLED], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Case đã đóng hoặc đã hủy, không thể nhận tạm.');
        }

        $userId = (string) auth('api')->id();

        return DB::transaction(function () use ($validated, $case, $userId, $idemKey) {
            $fromStatus = $case->status;
            $toStatus = WarrantyCase::STATUS_TEMP_ONLY;

            // Update case
            $case->update([
                'status' => $toStatus,
                'condition_status' => $validated['condition_status'],
                'item_id' => $validated['item_id'],
                'defect_id' => $validated['defect_id'],
                'version' => ($case->version ?? 1) + 1,
            ]);

            // Create warranty event
            WarrantyEvent::create([
                'warranty_event_id' => (string) \Illuminate\Support\Str::uuid(),
                'warranty_case_id' => $case->warranty_case_id,
                'event_type' => WarrantyEvent::EVENT_TEMP_ONLY,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $validated['note'] ?? 'Nhận tạm để kiểm tra bảo hành',
                'created_by' => $userId,
                'event_at' => now(),
                'created_at' => now(),
            ]);

            // Create stock movement for traceability
            // Note: TEMP_ONLY items should not be sellable stock
            // This movement is for tracking only, not affecting available_qty
            StockMovement::create([
                'movement_no' => 'WRN-TEMP-' . now()->format('Ymd-His') . '-' . \Illuminate\Support\Str::random(4),
                'business_key' => 'warranty-' . $case->warranty_case_id,
                'movement_type' => 'WARRANTY_TEMP',
                'warehouse_id' => null, // Will be set based on item location
                'product_id' => null, // Will be set based on item
                'stock_status' => 'WARRANTY_TEMP',
                'qty_before' => 0,
                'qty_delta' => 0,
                'qty_after' => 0,
                'stock_document_id' => null,
                'created_by' => $userId,
                'request_id' => $request->attributes->get('request_id'),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return $this->respondSuccess([
                'warranty_case_id' => $case->warranty_case_id,
                'status' => $toStatus,
                'condition_status' => $validated['condition_status'],
                'item_id' => $validated['item_id'],
                'defect_id' => $validated['defect_id'],
                'note' => $validated['note'] ?? null,
            ], 'Nhận tạm bảo hành thành công');
        });
    }
}