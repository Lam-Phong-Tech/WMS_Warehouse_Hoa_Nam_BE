<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\StocktakeSession;
use Modules\Warehouse\Models\StocktakeVariance;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockMovement;
use OpenApi\Attributes as OA;

/**
 * Stocktake - Kiểm kê kho.
 */
#[OA\Post(
    path: '/api/v1/stocktake-sessions/{id}/apply-adjustment',
    operationId: 'stocktake_apply_adjustment',
    summary: 'Áp dụng điều chỉnh kiểm kê (apply adjustment)',
    description: 'Áp dụng điều chỉnh tồn kho sau khi đã kiểm kê và review. Tạo movement điều chỉnh và cập nhật inventory_balance. Yêu cầu session đã ở trạng thái REVIEWED.',
    security: [['bearerAuth' => []]],
    tags: ['12. Stocktake'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['reason', 'lines'],
            properties: [
                new OA\Property(property: 'reason', type: 'string', maxLength: 500, description: 'Lý do điều chỉnh'),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'sku_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'stock_status', type: 'string', enum: ['AVAILABLE', 'DAMAGED', 'RESERVED']),
                        new OA\Property(property: 'expected_qty', type: 'number', format: 'float'),
                        new OA\Property(property: 'actual_qty', type: 'number', format: 'float'),
                        new OA\Property(property: 'adjustment_qty', type: 'number', format: 'float', description: 'actual_qty - expected_qty'),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Áp dụng điều chỉnh thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stocktake.adjust'),
        new OA\Response(response: 404, description: 'Không tìm thấy session'),
        new OA\Response(response: 409, description: 'Session chưa sẵn sàng hoặc đã áp dụng'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc điều chỉnh âm vượt tồn'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class StocktakeController extends Controller
{
    use ApiResponse;

    /**
     * Áp dụng điều chỉnh kiểm kê.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function applyAdjustment(Request $request, string $id)
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.sku_id' => 'required|uuid|exists:products,id',
            'lines.*.stock_status' => 'required|string|in:AVAILABLE,DAMAGED,RESERVED',
            'lines.*.expected_qty' => 'required|numeric|min:0',
            'lines.*.actual_qty' => 'required|numeric|min:0',
            'lines.*.adjustment_qty' => 'required|numeric',
        ]);

        $session = StocktakeSession::findOrFail($id);

        // Validate session status
        if (! in_array($session->status, [StocktakeSession::STATUS_REVIEWED, StocktakeSession::STATUS_COUNTED], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Session kiểm kê chưa ở trạng thái REVIEWED hoặc COUNTED.');
        }

        if ($session->status === StocktakeSession::STATUS_APPLIED) {
            ApiError::abort(409, 'STATE_CONFLICT', 'Session đã được áp dụng điều chỉnh rồi.');
        }

        $userId = (string) auth('api')->id();

        return DB::transaction(function () use ($validated, $session, $userId, $idemKey) {
            foreach ($validated['lines'] as $line) {
                // Validate adjustment_qty = actual_qty - expected_qty
                $expectedAdjustment = bcsub((string) $line['actual_qty'], (string) $line['expected_qty'], 3);
                if (bccomp((string) $line['adjustment_qty'], $expectedAdjustment, 3) !== 0) {
                    ApiError::abort(422, 'VALIDATION_ERROR', 'adjustment_qty phải bằng actual_qty - expected_qty.');
                }

                // For negative adjustment, check sufficient stock
                if (bccomp((string) $line['adjustment_qty'], '0', 3) < 0) {
                    $balance = InventoryBalance::where('product_id', $line['sku_id'])
                        ->where('stock_status', $line['stock_status'])
                        ->where('warehouse_id', $session->warehouse_id)
                        ->first();

                    $available = $balance ? (string) $balance->available_qty : '0';
                    $absAdjustment = abs($line['adjustment_qty']);

                    if (bccomp($available, $absAdjustment, 3) < 0) {
                        ApiError::abort(422, 'INSUFFICIENT_STOCK', "Không đủ tồn để điều chỉnh âm. Tồn hiện tại: {$available}, cần điều chỉnh: {$absAdjustment}");
                    }
                }

                // Create adjustment movement
                $movementType = bccomp((string) $line['adjustment_qty'], '0', 3) >= 0
                    ? StockMovement::ADJUST_IN
                    : StockMovement::ADJUST_OUT;

                StockMovement::create([
                    'movement_no' => 'ADJ-' . now()->format('Ymd-His') . '-' . Str::random(4),
                    'business_key' => 'stocktake-' . $session->session_id,
                    'movement_type' => $movementType,
                    'warehouse_id' => $session->warehouse_id,
                    'product_id' =>line['sku_id'],
                    'stock_status' => $line['stock_status'],
                    'qty_before' => $line['expected_qty'],
                    'qty_delta' => $line['adjustment_qty'],
                    'qty_after' => $line['actual_qty'],
                    'stock_document_id' => null,
                    'created_by' => $userId,
                    'request_id' => $request->attributes->get('request_id'),
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);

                // Update inventory balance
                $balance = InventoryBalance::where('product_id', $line['sku_id'])
                    ->where('stock_status', $line['stock_status'])
                    ->where('warehouse_id', $session->warehouse_id)
                    ->first();

                if ($balance) {
                    $newQty = bcadd((string) $balance->on_hand_qty, (string) $line['adjustment_qty'], 3);
                    $balance->update([
                        'on_hand_qty' => $newQty,
                        'available_qty' => $newQty,
                    ]);
                }
            }

            // Update session status
            $session->update([
                'status' => StocktakeSession::STATUS_APPLIED,
                'applied_at' => now(),
            ]);

            return $this->respondSuccess([
                'session_id' => $session->session_id,
                'status' => StocktakeSession::STATUS_APPLIED,
                'reason' => $validated['reason'],
                'lines_adjusted' => count($validated['lines']),
            ], 'Áp dụng điều chỉnh kiểm kê thành công');
        });
    }
}