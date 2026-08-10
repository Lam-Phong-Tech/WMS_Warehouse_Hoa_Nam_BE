<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselinePhysicalCodeController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code_value' => ['required', 'string', 'max:200', 'unique:physical_codes,code_value'],
            'carrier_type' => ['required', Rule::in(['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'])],
            'hardware_uid' => ['nullable', 'string', 'max:200', 'unique:physical_codes,hardware_uid'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        $hasItem = ! empty($validated['item_id']);
        $hasContainer = ! empty($validated['container_id']);
        if ($hasItem === $hasContainer) {
            ApiError::abort(422, 'PHYSICAL_CODE_TARGET_XOR', 'Physical code phải map đúng một item_id hoặc container_id.');
        }
        if ($validated['carrier_type'] === 'RFID' && empty($validated['hardware_uid'])) {
            ApiError::abort(422, 'RFID_HARDWARE_UID_REQUIRED', 'RFID bắt buộc hardware_uid.');
        }

        $id = DB::transaction(function () use ($validated, $hasItem, $request): string {
            $targetId = (string) ($hasItem ? $validated['item_id'] : $validated['container_id']);
            $targetType = $hasItem ? 'ITEM' : 'CONTAINER';
            if ($hasItem) {
                DB::table('item_instances')->where('item_id', $targetId)->lockForUpdate()->firstOrFail();
            } else {
                DB::table('containers')->where('container_id', $targetId)->lockForUpdate()->firstOrFail();
            }

            $physicalCodeId = (string) Str::uuid();
            DB::table('physical_codes')->insert([
                'physical_code_id' => $physicalCodeId,
                'code_value' => strtoupper(trim($validated['code_value'])),
                'code_type' => $targetType,
                'carrier_type' => $validated['carrier_type'],
                'hardware_uid' => $validated['hardware_uid'] ?? null,
                'item_id' => $hasItem ? $targetId : null,
                'container_id' => $hasItem ? null : $targetId,
                'assigned_object_type' => $targetType,
                'assigned_object_id' => $targetId,
                'is_primary' => (bool) ($validated['is_primary'] ?? false),
                'assigned_at' => now(),
                'assigned_by' => $this->actorId(),
                'status' => 'ACTIVE',
                'version' => 1,
                'created_by' => $this->actorId(),
                'updated_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! $hasItem && DB::getSchemaBuilder()->hasColumn('containers', 'physical_code_id')) {
                $container = DB::table('containers')->where('container_id', $targetId)->first();
                if ($container !== null && empty($container->physical_code_id)) {
                    DB::table('containers')->where('container_id', $targetId)->update(['physical_code_id' => $physicalCodeId, 'updated_at' => now()]);
                }
            }

            AuditLogger::record(
                action: 'PHYSICAL_CODE_ASSIGN',
                objectType: 'physical_code',
                objectId: $physicalCodeId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['target_type' => $targetType, 'target_id' => $targetId, 'carrier_type' => $validated['carrier_type']],
            );
            return $physicalCodeId;
        }, 3);

        return $this->respondCreated(DB::table('physical_codes')->where('physical_code_id', $id)->first(), 'Physical code mapped to canonical target.');
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE', 'LOST', 'DAMAGED'])],
            'deactivation_reason' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion, $request): object {
            $code = DB::table('physical_codes')->where('physical_code_id', $id)->lockForUpdate()->first();
            if ($code === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy physical code.');
            }
            if ((int) ($code->version ?? 0) !== $expectedVersion) {
                ApiError::abort(412, 'PRECONDITION_FAILED', 'Physical code version mismatch.');
            }
            if ($validated['status'] !== 'ACTIVE' && empty($validated['deactivation_reason'])) {
                ApiError::abort(422, 'DEACTIVATION_REASON_REQUIRED', 'LOST/DAMAGED/INACTIVE bắt buộc deactivation_reason.');
            }

            DB::table('physical_codes')->where('physical_code_id', $id)->update([
                'status' => $validated['status'],
                'is_primary' => (bool) ($validated['is_primary'] ?? $code->is_primary),
                'deactivated_at' => $validated['status'] === 'ACTIVE' ? null : now(),
                'deactivation_reason' => $validated['status'] === 'ACTIVE' ? null : $validated['deactivation_reason'],
                'version' => $expectedVersion + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);

            $after = DB::table('physical_codes')->where('physical_code_id', $id)->first();
            AuditLogger::record(
                action: 'PHYSICAL_CODE_STATUS_CHANGE',
                objectType: 'physical_code',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                reason: $validated['deactivation_reason'] ?? null,
                beforeData: ['status' => $code->status, 'version' => $code->version ?? 0],
                afterData: ['status' => $after->status, 'version' => $after->version],
            );
            return $after;
        }, 3);

        return $this->respondSuccess($result, 'Physical code updated.');
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }
        return (int) $header;
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
