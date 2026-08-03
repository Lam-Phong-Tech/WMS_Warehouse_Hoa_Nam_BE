<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/warranty-cases/{id}/temp-only',
    operationId: 'warranty_temp_only',
    summary: 'Tiếp nhận bảo hành chưa định danh, không tác động tồn kho',
    description: 'TEMP_ONLY chỉ cập nhật trạng thái phiếu và tạo warranty_event. Không liên kết inventory_serial, không tạo stock_movement và không cập nhật inventory_balance.',
    security: [['bearerAuth' => []]],
    tags: ['13. Warranty'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 128)),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['temporary_item_code', 'missing_code_reason', 'condition_status'],
            properties: [
                new OA\Property(property: 'temporary_item_code', type: 'string', maxLength: 150),
                new OA\Property(property: 'missing_code_reason', type: 'string', maxLength: 500),
                new OA\Property(property: 'manual_product_description', type: 'string', nullable: true),
                new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'condition_status', type: 'string', enum: ['WAITING_CHECK', 'INSPECTING']),
                new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Tiếp nhận TEMP_ONLY thành công, stock_effect=NONE'),
        new OA\Response(response: 409, description: 'Sai trạng thái hoặc khóa idempotency xung đột payload'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 428, description: 'Thiếu Idempotency-Key'),
    ]
)]
class WarrantyController extends Controller
{
    use ApiResponse;

    public function tempOnly(Request $request, string $id)
    {
        $idemKey = trim((string) $request->header('Idempotency-Key'));
        if ($idemKey === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }

        $validated = $request->validate([
            'temporary_item_code' => ['required', 'string', 'max:150'],
            'missing_code_reason' => ['required', 'string', 'max:500'],
            'manual_product_description' => ['nullable', 'string', 'max:2000'],
            'defect_id' => ['nullable', 'uuid', 'exists:defect_catalog,defect_id'],
            'condition_status' => ['required', 'string', 'in:WAITING_CHECK,INSPECTING'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = (string) auth('api')->id();
        $operation = "warranty_temp_only_{$id}";
        $requestHash = IdempotencyStore::requestHash($request);

        [$code, $body] = IdempotencyStore::handle(
            $idemKey,
            $operation,
            $userId,
            $requestHash,
            function () use ($id, $validated, $userId): array {
                $data = DB::transaction(function () use ($id, $validated, $userId): array {
                    /** @var WarrantyCase $case */
                    $case = WarrantyCase::query()->lockForUpdate()->findOrFail($id);

                    if (! in_array($case->status, [WarrantyCase::STATUS_RECEIVED, WarrantyCase::STATUS_WAITING_CHECK], true)) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Phiếu phải ở trạng thái RECEIVED hoặc WAITING_CHECK để chuyển TEMP_ONLY.');
                    }

                    $fromStatus = $case->status;
                    $case->update([
                        'inventory_serial_id' => null,
                        'product_id' => null,
                        'temporary_item_code' => $validated['temporary_item_code'],
                        'identification_status' => 'TEMP_ONLY',
                        'missing_code_reason' => $validated['missing_code_reason'],
                        'manual_product_description' => $validated['manual_product_description'] ?? null,
                        'defect_id' => $validated['defect_id'] ?? null,
                        'condition_status' => $validated['condition_status'],
                        'status' => WarrantyCase::STATUS_TEMP_ONLY,
                        'version' => ((int) $case->version) + 1,
                    ]);

                    WarrantyEvent::create([
                        'warranty_event_id' => (string) Str::uuid(),
                        'warranty_case_id' => $case->warranty_case_id,
                        'event_type' => WarrantyEvent::EVENT_TEMP_ONLY,
                        'from_status' => $fromStatus,
                        'to_status' => WarrantyCase::STATUS_TEMP_ONLY,
                        'note' => $validated['note'] ?? 'Tiếp nhận bảo hành chưa định danh',
                        'created_by' => $userId,
                        'event_at' => now(),
                    ]);

                    return [
                        'warranty_case_id' => $case->warranty_case_id,
                        'status' => WarrantyCase::STATUS_TEMP_ONLY,
                        'identification_status' => 'TEMP_ONLY',
                        'temporary_item_code' => $validated['temporary_item_code'],
                        'condition_status' => $validated['condition_status'],
                        'stock_effect' => 'NONE',
                        'movement_created' => false,
                    ];
                });

                return [200, $this->envelope('Tiếp nhận TEMP_ONLY thành công', $data)];
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
