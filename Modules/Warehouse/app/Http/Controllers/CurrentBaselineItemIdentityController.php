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
            'code_value' => ['required', 'string', 'max:200', 'unique:physical_codes,code_value'],
            'carrier_type' => ['required', Rule::in(['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'])],
            'hardware_uid' => ['nullable', 'string', 'max:200', 'unique:physical_codes,hardware_uid'],
            'encoded_payload_version' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['nullable', 'boolean'],
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
                    if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
                        ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU ACTIVE.');
                    }
                    $item = DB::table('item_instances')->where('item_id', $validated['item_id'])->lockForUpdate()->first();
                    if ($item === null || (string) $item->sku_id !== $skuId) {
                        ApiError::abort(422, 'ITEM_SKU_MISMATCH', 'item_id không thuộc SKU trên URL.');
                    }

                    $id = (string) Str::uuid();
                    $codeValue = strtoupper(trim($validated['code_value']));
                    DB::table('physical_codes')->insert([
                        'physical_code_id' => $id,
                        'code_value' => $codeValue,
                        'code_type' => 'ITEM',
                        'carrier_type' => $validated['carrier_type'],
                        'hardware_uid' => $validated['hardware_uid'] ?? null,
                        'item_id' => $item->item_id,
                        'container_id' => null,
                        'assigned_object_type' => 'ITEM',
                        'assigned_object_id' => $item->item_id,
                        'is_primary' => (bool) ($validated['is_primary'] ?? false),
                        'assigned_at' => now(),
                        'assigned_by' => $this->actorId(),
                        'encoded_payload_version' => $validated['encoded_payload_version'] ?? null,
                        'status' => 'ACTIVE',
                        'version' => 1,
                        'created_by' => $this->actorId(),
                        'updated_by' => $this->actorId(),
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
                            'hardware_uid' => $validated['hardware_uid'] ?? null,
                        ],
                    );

                    return [
                        'physical_code_id' => $id,
                        'sku_id' => $skuId,
                        'item_id' => (string) $item->item_id,
                        'code_value' => $codeValue,
                        'carrier_type' => $validated['carrier_type'],
                        'hardware_uid' => $validated['hardware_uid'] ?? null,
                        'is_primary' => (bool) ($validated['is_primary'] ?? false),
                        'status' => 'ACTIVE',
                        'version' => 1,
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
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "item_identity_revoke_{$skuId}_{$physicalCodeId}",
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            function () use ($skuId, $physicalCodeId, $request, $validated, $expectedVersion): array {
                $data = DB::transaction(function () use ($skuId, $physicalCodeId, $request, $validated, $expectedVersion): array {
                    $code = DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->lockForUpdate()->first();
                    if ($code === null || ($code->item_id ?? $code->assigned_object_id) === null) {
                        ApiError::abort(404, 'PHYSICAL_CODE_NOT_FOUND', 'Không tìm thấy item physical code.');
                    }
                    if ((int) ($code->version ?? 0) !== $expectedVersion) {
                        ApiError::abort(412, 'STALE_VERSION', 'Physical code version mismatch.');
                    }
                    $itemId = (string) ($code->item_id ?? $code->assigned_object_id);
                    $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
                    if ($item === null || (string) $item->sku_id !== $skuId) {
                        ApiError::abort(404, 'PHYSICAL_CODE_NOT_FOUND', 'Physical code không thuộc item của SKU này.');
                    }
                    if ($code->status !== 'ACTIVE') {
                        ApiError::abort(409, 'PHYSICAL_CODE_NOT_ACTIVE', 'Chỉ code ACTIVE mới được deactivate.');
                    }

                    DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->update([
                        'status' => 'INACTIVE',
                        'deactivated_at' => now(),
                        'deactivation_reason' => $validated['reason'],
                        'version' => $expectedVersion + 1,
                        'updated_by' => $this->actorId(),
                        'updated_at' => now(),
                    ]);

                    AuditLogger::record(
                        action: 'ITEM_PHYSICAL_CODE_DEACTIVATE',
                        objectType: 'physical_code',
                        objectId: $physicalCodeId,
                        actorId: $this->actorId(),
                        requestId: $request->attributes->get('request_id'),
                        reason: $validated['reason'],
                        beforeData: ['status' => $code->status, 'version' => $code->version ?? 0],
                        afterData: ['status' => 'INACTIVE', 'item_id' => $itemId, 'version' => $expectedVersion + 1],
                    );

                    return [
                        'physical_code_id' => $physicalCodeId,
                        'sku_id' => $skuId,
                        'item_id' => $itemId,
                        'status' => 'INACTIVE',
                        'version' => $expectedVersion + 1,
                        'stock_effect' => 'NONE',
                    ];
                }, 3);

                return [200, $this->envelope('Physical identity deactivated.', $data)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho physical-code mutation.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
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

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
