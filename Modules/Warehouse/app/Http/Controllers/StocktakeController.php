<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\StocktakeSession;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/stocktake-sessions/{id}/apply-adjustment',
    operationId: 'stocktake_apply_adjustment',
    summary: 'Áp dụng điều chỉnh kiểm kê theo operation-level idempotency',
    description: 'Một Idempotency-Key đại diện cho toàn bộ operation. Mỗi dòng tạo business_key riêng theo session/product/stock_status. Toàn bộ dòng commit hoặc rollback cùng nhau.',
    security: [['bearerAuth' => []]],
    tags: ['12. Stocktake'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['reason', 'lines'],
        properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 500),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'stock_status', type: 'string', enum: ['AVAILABLE', 'DAMAGED', 'RESERVED']),
                new OA\Property(property: 'expected_qty', type: 'number', format: 'float'),
                new OA\Property(property: 'actual_qty', type: 'number', format: 'float'),
                new OA\Property(property: 'adjustment_qty', type: 'number', format: 'float'),
            ])),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Điều chỉnh thành công hoặc replay kết quả 2xx đã lưu'),
        new OA\Response(response: 409, description: 'Sai trạng thái, stale expected_qty hoặc idempotency conflict'),
        new OA\Response(response: 422, description: 'Payload không hợp lệ hoặc điều chỉnh gây tồn âm'),
        new OA\Response(response: 428, description: 'Thiếu Idempotency-Key'),
    ]
)]
class StocktakeController extends Controller
{
    use ApiResponse;

    private const SCALE = 3;

    public function applyAdjustment(Request $request, string $id)
    {
        $idemKey = trim((string) $request->header('Idempotency-Key'));
        if ($idemKey === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id', 'distinct'],
            'lines.*.stock_status' => ['required', 'string', 'in:AVAILABLE,DAMAGED,RESERVED'],
            'lines.*.expected_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.actual_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.adjustment_qty' => ['required', 'numeric'],
        ]);

        $userId = (string) auth('api')->id();
        $operation = "stocktake_apply_adjustment_{$id}";
        $requestHash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle(
            $idemKey,
            $operation,
            $userId,
            $requestHash,
            function () use ($id, $validated, $userId): array {
                $data = DB::transaction(function () use ($id, $validated, $userId): array {
                    /** @var StocktakeSession $session */
                    $session = StocktakeSession::query()->lockForUpdate()->findOrFail($id);
                    if (!in_array($session->status, [StocktakeSession::STATUS_REVIEWED, StocktakeSession::STATUS_COUNTED], true)) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Session phải ở trạng thái REVIEWED hoặc COUNTED.');
                    }

                    $movementIds = [];
                    foreach ($validated['lines'] as $line) {
                        $expectedAdjustment = bcsub((string) $line['actual_qty'], (string) $line['expected_qty'], self::SCALE);
                        if (bccomp((string) $line['adjustment_qty'], $expectedAdjustment, self::SCALE) !== 0) {
                            ApiError::abort(422, 'VALIDATION_ERROR', 'adjustment_qty phải bằng actual_qty - expected_qty.');
                        }

                        $balance = InventoryBalance::query()
                            ->where('warehouse_id', $session->warehouse_id)
                            ->where('product_id', $line['product_id'])
                            ->where('stock_status', $line['stock_status'])
                            ->lockForUpdate()
                            ->first();
                        $currentQty = (string) ($balance?->on_hand_qty ?? '0');
                        if (bccomp($currentQty, (string) $line['expected_qty'], self::SCALE) !== 0) {
                            ApiError::abort(409, 'STALE_STOCKTAKE_BASELINE', 'Tồn hiện tại đã thay đổi so với expected_qty; cần review lại kiểm kê.');
                        }

                        $actualQty = (string) $line['actual_qty'];
                        if (bccomp($actualQty, '0', self::SCALE) < 0) {
                            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Điều chỉnh không được tạo tồn âm.');
                        }

                        if (!$balance) {
                            $balance = InventoryBalance::create([
                                'warehouse_id' => $session->warehouse_id,
                                'product_id' => $line['product_id'],
                                'stock_status' => $line['stock_status'],
                                'on_hand_qty' => 0,
                                'available_qty' => 0,
                                'reserved_qty' => 0,
                                'version' => 1,
                            ]);
                        }

                        $businessKey = sha1(implode('|', [
                            'stocktake-adjust', $session->session_id, $line['product_id'], $line['stock_status'],
                        ]));
                        $existing = StockMovement::where('business_key', $businessKey)->first();
                        if ($existing) {
                            $movementIds[] = $existing->id;

                            continue;
                        }

                        $movement = StockMovement::create([
                            'movement_no' => 'ADJ-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(6)),
                            'business_key' => $businessKey,
                            'movement_type' => bccomp((string) $line['adjustment_qty'], '0', self::SCALE) >= 0
                                ? StockMovement::ADJUST_IN : StockMovement::ADJUST_OUT,
                            'warehouse_id' => $session->warehouse_id,
                            'product_id' => $line['product_id'],
                            'stock_status' => $line['stock_status'],
                            'qty_before' => $currentQty,
                            'qty_delta' => (string) $line['adjustment_qty'],
                            'qty_after' => $actualQty,
                            'created_by' => $userId,
                            'request_id' => request()->attributes->get('request_id'),
                            'occurred_at' => now(),
                            'created_at' => now(),
                        ]);

                        $balance->update([
                            'on_hand_qty' => $actualQty,
                            'available_qty' => $line['stock_status'] === 'AVAILABLE' ? $actualQty : $balance->available_qty,
                            'version' => ((int) $balance->version) + 1,
                        ]);
                        $movementIds[] = $movement->id;
                    }

                    $session->update([
                        'status' => StocktakeSession::STATUS_APPLIED,
                        'applied_at' => now(),
                        'applied_by' => $userId,
                        'session_version' => ((int) $session->session_version) + 1,
                    ]);

                    return [
                        'session_id' => $session->session_id,
                        'status' => StocktakeSession::STATUS_APPLIED,
                        'reason' => $validated['reason'],
                        'lines_adjusted' => count($validated['lines']),
                        'movement_ids' => $movementIds,
                    ];
                });

                return [200, $this->envelope('Áp dụng điều chỉnh kiểm kê thành công', $data)];
            }
        );

        return response()->json($body, $code);
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
