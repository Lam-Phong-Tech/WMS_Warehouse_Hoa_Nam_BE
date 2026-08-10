<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineItemIdentityController extends Controller
{
    use ApiResponse;

    public function assign(Request $request, string $skuId)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'uuid', 'exists:item_instances,item_id'],
            'code_value' => ['required', 'string', 'max:100', 'unique:physical_codes,code_value'],
            'carrier_type' => ['required', Rule::in(['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'])],
            'hardware_uid' => ['nullable', 'string', 'max:150', 'unique:physical_codes,hardware_uid'],
            'encoded_payload_version' => ['nullable', 'string', 'max:30'],
        ]);
        if ($validated['carrier_type'] === 'RFID' && empty($validated['hardware_uid'])) {
            ApiError::abort(422, 'RFID_HARDWARE_UID_REQUIRED', 'RFID bắt buộc hardware_uid duy nhất.');
        }

        $key = IdempotencyKeyGuard::require($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "item_identity_assign_{$skuId}_{$validated['item_id']}",
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            function () use ($skuId, $validated, $request): array {
                $data = DB::transaction(function () use ($skuId, $validated, $request): array {
                    $sku = DB::table('sku')->where('sku_id', $skuId)->lockForUpdate()->first();
                    if ($sku === null) {
                        ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU.');
                    }
                    $item = DB::table('item_instances')->where('item_id', $validated['item_id'])->lockForUpdate()->first();
                    if ($item === null || (string) $item->sku_id !== $skuId) {
                        ApiError::abort(422, 'ITEM_SKU_MISMATCH', 'item_id không thuộc SKU trên URL.');
                    }

                    $id = (string) Str::uuid();
                    DB::table('physical_codes')->insert([
                        'physical_code_id' => $id,
                        'code_value' => strtoupper(trim($validated['code_value'])),
                        'code_type' => 'ITEM',
                        'carrier_type' => $validated['carrier_type'],
                        'hardware_uid' => $validated['hardware_uid'] ?? null,
                        'assigned_object_type' => 'ITEM',
                        'assigned_object_id' => $item->item_id,
                        'encoded_payload_version' => $validated['encoded_payload_version'] ?? null,
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    AuditLogger::record(
                        action: 'ITEM_PHYSICAL_CODE_ASSIGN',
                        objectType: 'physical_code',
                        objectId: $id,
                        actorId: $this->actorId(),
                        requestId: $request->attributes->get('request_id'),
                        afterData: [
                            'item_id' => (string) $item->item_id,
                            'sku_id' => $skuId,
                            'carrier_type' => $validated['carrier_type'],
                        ],
                    );

                    return [
                        'physical_code_id' => $id,
                        'sku_id' => $skuId,
                        'item_id' => (string) $item->item_id,
                        'code_value' => strtoupper(trim($validated['code_value'])),
                        'carrier_type' => $validated['carrier_type'],
                        'hardware_uid' => $validated['hardware_uid'] ?? null,
                        'status' => 'ACTIVE',
                        'stock_effect' => 'NONE',
                    ];
                }, 3);

                return [201, $this->envelope('Physical identity assigned to item.', $data)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    public function revoke(Request $request, string $skuId, string $physicalCodeId)
    {
        $key = IdempotencyKeyGuard::require($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "item_identity_revoke_{$skuId}_{$physicalCodeId}",
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            function () use ($skuId, $physicalCodeId, $request): array {
                $data = DB::transaction(function () use ($skuId, $physicalCodeId, $request): array {
                    $code = DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->lockForUpdate()->first();
                    if ($code === null || $code->assigned_object_type !== 'ITEM' || $code->assigned_object_id === null) {
                        ApiError::abort(404, 'PHYSICAL_CODE_NOT_FOUND', 'Không tìm thấy item physical code.');
                    }
                    $item = DB::table('item_instances')->where('item_id', $code->assigned_object_id)->lockForUpdate()->first();
                    if ($item === null || (string) $item->sku_id !== $skuId) {
                        ApiError::abort(404, 'PHYSICAL_CODE_NOT_FOUND', 'Physical code không thuộc item của SKU này.');
                    }

                    DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->update([
                        'status' => 'INACTIVE',
                        'updated_at' => now(),
                    ]);

                    AuditLogger::record(
                        action: 'ITEM_PHYSICAL_CODE_REVOKE',
                        objectType: 'physical_code',
                        objectId: $physicalCodeId,
                        actorId: $this->actorId(),
                        requestId: $request->attributes->get('request_id'),
                        beforeData: ['status' => $code->status],
                        afterData: ['status' => 'INACTIVE', 'item_id' => (string) $item->item_id],
                    );

                    return [
                        'physical_code_id' => $physicalCodeId,
                        'sku_id' => $skuId,
                        'item_id' => (string) $item->item_id,
                        'status' => 'INACTIVE',
                        'stock_effect' => 'NONE',
                    ];
                }, 3);

                return [200, $this->envelope('Physical identity revoked.', $data)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()?->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();
        return $id === null ? null : (string) $id;
    }
}
