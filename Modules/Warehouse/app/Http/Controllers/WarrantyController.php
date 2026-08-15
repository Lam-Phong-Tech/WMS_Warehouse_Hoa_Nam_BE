<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreWarrantyTempOnlyRequest;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/warranty-cases/{id}/temp-only',
    operationId: 'warranty_temp_only',
    summary: 'Record a temporary warranty receipt',
    description: 'Moves a warranty case to TEMP_ONLY and records its warranty event and audit atomically without changing the stock ledger.',
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
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', description: 'inventory_serials.id'),
                new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', nullable: true, description: 'defect_catalogs.defect_id'),
                new OA\Property(property: 'condition_status', type: 'string', enum: ['WAITING_CHECK', 'INSPECTING']),
                new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Temporary warranty receipt recorded'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 403, description: 'Missing warranty.temp_only permission'),
        new OA\Response(response: 404, description: 'Warranty case not found'),
        new OA\Response(response: 409, description: 'STATE_CONFLICT or IDEMPOTENCY_ERROR'),
        new OA\Response(response: 422, description: 'Validation failed'),
        new OA\Response(response: 428, description: 'PRECONDITION_REQUIRED'),
    ]
)]
class WarrantyController extends Controller
{
    use ApiResponse;

    public function tempOnly(StoreWarrantyTempOnlyRequest $request, string $id)
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Idempotency-Key header is required.');
        }

        $userId = (string) auth('api')->id();
        $hash = IdempotencyStore::requestHash($request);
        $operation = "warranty_temp_only_{$id}";

        [$code, $body] = IdempotencyStore::handle($idemKey, $operation, $userId, $hash, function () use ($request, $id, $userId) {
            $data = DB::transaction(function () use ($request, $id, $userId) {
                $case = WarrantyCase::query()->lockForUpdate()->findOrFail($id);
                if (! in_array($case->status, [WarrantyCase::STATUS_RECEIVED, WarrantyCase::STATUS_WAITING_CHECK], true)) {
                    ApiError::abort(409, 'STATE_CONFLICT', 'Warranty case cannot be received temporarily from its current status.');
                }

                $data = $request->validated();
                $serial = InventorySerial::query()->findOrFail($data['item_id']);
                $fromStatus = $case->status;
                $case->update([
                    'status' => WarrantyCase::STATUS_TEMP_ONLY,
                    'condition_status' => $data['condition_status'],
                    'inventory_serial_id' => $serial->id,
                    'defect_id' => $data['defect_id'] ?? null,
                    'version' => $case->version + 1,
                ]);

                WarrantyEvent::create([
                    'warranty_event_id' => (string) Str::uuid(),
                    'warranty_case_id' => $case->warranty_case_id,
                    'event_type' => WarrantyEvent::EVENT_TEMP_ONLY,
                    'from_status' => $fromStatus,
                    'to_status' => WarrantyCase::STATUS_TEMP_ONLY,
                    'note' => $data['note'] ?? 'Temporary receipt for warranty inspection.',
                    'created_by' => $userId,
                    'event_at' => now(),
                ]);

                AuditLogger::record('WARRANTY_TEMP_ONLY', 'warranty_case', $case->warranty_case_id, $userId, $request->attributes->get('request_id'), afterData: [
                    'inventory_serial_id' => $serial->id,
                    'status' => WarrantyCase::STATUS_TEMP_ONLY,
                    'defect_id' => $data['defect_id'] ?? null,
                ]);

                return [
                    'warranty_case_id' => $case->warranty_case_id,
                    'status' => WarrantyCase::STATUS_TEMP_ONLY,
                    'condition_status' => $data['condition_status'],
                    'item_id' => $serial->id,
                    'defect_id' => $data['defect_id'] ?? null,
                    'note' => $data['note'] ?? null,
                ];
            });

            return [200, $this->successEnvelope('Temporary warranty receipt recorded.', $data)];
        });

        return response()->json($body, $code);
    }

    private function successEnvelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => $this->currentRequestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
