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
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

class CurrentBaselinePhysicalCodeController extends Controller
{
    use ApiResponse;
    use ResolvesActor;

    #[OA\Post(
        path: '/api/v1/physical-codes',
        operationId: 'physical_code_create',
        summary: 'Gán mã vật lý (QR/barcode/RFID/NFC) tới một item hoặc container',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code_value', 'carrier_type'],
                properties: [
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 200),
                    new OA\Property(property: 'code_type', type: 'string', enum: ['ITEM', 'CARTON', 'COMPONENT_SERIAL', 'BOX', 'TRAY', 'TEMP_WARRANTY', 'UNKNOWN'], nullable: true),
                    new OA\Property(property: 'carrier_type', type: 'string', enum: ['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL']),
                    new OA\Property(property: 'hardware_uid', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'is_primary', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Đã gán mã vật lý vào đối tượng đích'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ, hoặc map đồng thời cả item_id và container_id (PHYSICAL_CODE_TARGET_XOR)'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code_value' => ['required', 'string', 'max:200', 'unique:physical_codes,code_value'],
            'code_type' => ['nullable', Rule::in(['ITEM', 'CARTON', 'COMPONENT_SERIAL', 'BOX', 'TRAY', 'TEMP_WARRANTY', 'UNKNOWN'])],
            'carrier_type' => ['required', Rule::in(['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'])],
            'hardware_uid' => ['nullable', 'string', 'max:200', 'unique:physical_codes,hardware_uid'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        $hasItem = ! empty($validated['item_id']);
        $hasContainer = ! empty($validated['container_id']);
        // SRS v1.12 §7.3: Physical_Code – Item/Container = 1–0..1 (UNASSIGNED hợp lệ
        // theo lifecycle §13.9); chỉ chặn khi map đồng thời cả hai target.
        if ($hasItem && $hasContainer) {
            ApiError::abort(422, 'PHYSICAL_CODE_TARGET_XOR', 'Physical code phải map tối đa một item_id hoặc container_id.');
        }
        $targetType = $validated['code_type'] ?? ($hasItem ? 'ITEM' : ($hasContainer ? 'CONTAINER' : 'UNKNOWN'));
        if ($targetType === 'ITEM' && ! in_array($validated['carrier_type'], ['QR', 'BARCODE', 'RFID', 'NFC'], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', [
                'carrier_type' => ['Mã vật lý loại ITEM bắt buộc dùng carrier chip (QR/BARCODE/RFID/NFC).'],
            ]);
        }
        if ($validated['carrier_type'] === 'RFID' && empty($validated['hardware_uid'])) {
            ApiError::abort(422, 'RFID_HARDWARE_UID_REQUIRED', 'RFID bắt buộc hardware_uid.');
        }

        $id = DB::transaction(function () use ($validated, $hasItem, $hasContainer, $targetType, $request): string {
            $targetId = $hasItem ? (string) $validated['item_id'] : ($hasContainer ? (string) $validated['container_id'] : null);
            // firstOrFail() ném Illuminate\Database\RecordsNotFoundException (RuntimeException),
            // không phải HttpExceptionInterface/ValidationException — bootstrap/app.php chỉ
            // Việt hoá 3 loại đó, mọi Throwable khác rơi thẳng vào safety net 500. Giữa lúc
            // validate 'exists:...' xong và lúc lock ở đây chạy, nếu dòng bị xoá bởi một
            // tiến trình khác thì phải trả 404 RESOURCE_NOT_FOUND như mọi nhánh khác của
            // controller này, không phải 500.
            if ($hasItem) {
                $target = DB::table('item_instances')->where('item_id', $targetId)->lockForUpdate()->first();
                if ($target === null) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy item.');
                }
            } elseif ($hasContainer) {
                $target = DB::table('containers')->where('container_id', $targetId)->lockForUpdate()->first();
                if ($target === null) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy container.');
                }
            }

            $physicalCodeId = (string) Str::uuid();
            DB::table('physical_codes')->insert([
                'physical_code_id' => $physicalCodeId,
                'code_value' => strtoupper(trim($validated['code_value'])),
                'code_type' => $targetType,
                'carrier_type' => $validated['carrier_type'],
                'hardware_uid' => $validated['hardware_uid'] ?? null,
                'item_id' => $hasItem ? $targetId : null,
                'container_id' => $hasContainer ? $targetId : null,
                'assigned_object_type' => $targetId !== null ? $targetType : null,
                'assigned_object_id' => $targetId,
                'is_primary' => (bool) ($validated['is_primary'] ?? false),
                'assigned_at' => $targetId !== null ? now() : null,
                'assigned_by' => $this->actorId(),
                'status' => 'ACTIVE',
                'version' => 1,
                'created_by' => $this->actorId(),
                'updated_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($hasContainer && DB::getSchemaBuilder()->hasColumn('containers', 'physical_code_id')) {
                $container = DB::table('containers')->where('container_id', $targetId)->first();
                if ($container !== null && empty($container->physical_code_id)) {
                    DB::table('containers')->where('container_id', $targetId)->update(['physical_code_id' => $physicalCodeId, 'updated_at' => now()]);
                }
            }

            AuditLogger::record(
                action: 'CREATE',
                objectType: 'physical_code',
                objectId: $physicalCodeId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['target_type' => $targetType, 'target_id' => $targetId, 'carrier_type' => $validated['carrier_type']],
            );

            return $physicalCodeId;
        }, 3);

        return $this->respondCreated(DB::table('physical_codes')->where('physical_code_id', $id)->first(), 'Đã gán mã vật lý vào đối tượng đích.');
    }

    /**
     * Annex C assignItemRfid: PUT /outbound-documents/{id}/items/{item_id}/rfid.
     *
     * SRS §13.9 + DEC-RFID-FALLBACK-001: gắn RFID là thao tác stock-neutral, KHÔNG
     * phải gate của Post Issue. Idempotent theo (item, hardware_uid): gọi lại cùng
     * tag trả về chính mapping cũ thay vì tạo bản ghi trùng.
     */
    #[OA\Put(
        path: '/api/v1/outbound-documents/{id}/items/{itemId}/rfid',
        operationId: 'outbound_item_rfid_assign',
        summary: 'Gắn RFID cho item đã match trong phiếu xuất (stock-neutral, idempotent theo hardware_uid)',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['hardware_uid'],
                properties: [
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 200, nullable: true, description: 'Bỏ trống thì lấy theo hardware_uid', example: 'HN-RFID-000789'),
                    new OA\Property(property: 'hardware_uid', type: 'string', maxLength: 200, example: 'RFID-UID-000456'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã gắn RFID (hoặc trả lại mapping cũ nếu cùng hardware_uid), stock_effect=NONE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 409, description: 'DUPLICATE_HARDWARE_UID (tag đã gắn item khác) hoặc DUPLICATE_CODE_VALUE'),
            new OA\Response(response: 422, description: 'ITEM_NOT_MATCHED_IN_DOCUMENT hoặc payload không hợp lệ'),
        ]
    )]
    public function assignOutboundItemRfid(Request $request, string $documentId, string $itemId)
    {
        $validated = $request->validate([
            'code_value' => ['nullable', 'string', 'max:200'],
            'hardware_uid' => ['required', 'string', 'max:200'],
        ]);
        $hardwareUid = trim((string) $validated['hardware_uid']);
        $codeValue = strtoupper(trim((string) ($validated['code_value'] ?? $hardwareUid)));

        $matched = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('m.item_id', $itemId)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->exists();
        if (! $matched) {
            ApiError::abort(422, 'ITEM_NOT_MATCHED_IN_DOCUMENT', 'Item chưa được match vào phiếu xuất này nên không thể gắn RFID.');
        }

        $payload = DB::transaction(function () use ($itemId, $codeValue, $hardwareUid, $request): array {
            $existing = DB::table('physical_codes')
                ->where('hardware_uid', $hardwareUid)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((string) ($existing->item_id ?? '') !== $itemId) {
                    // §13.9: duplicate hardware UID không tự merge.
                    ApiError::abort(409, 'DUPLICATE_HARDWARE_UID', 'Hardware UID đã gắn cho item khác.', [
                        'physical_code_id' => (string) $existing->physical_code_id,
                    ]);
                }

                return ['physical_code' => $existing, 'created' => false];
            }

            $duplicateValue = DB::table('physical_codes')->where('code_value', $codeValue)->exists();
            if ($duplicateValue) {
                ApiError::abort(409, 'DUPLICATE_CODE_VALUE', 'code_value đã tồn tại trong hệ thống.');
            }

            $physicalCodeId = (string) Str::uuid();
            DB::table('physical_codes')->insert([
                'physical_code_id' => $physicalCodeId,
                'code_value' => $codeValue,
                'code_type' => 'ITEM',
                'carrier_type' => 'RFID',
                'hardware_uid' => $hardwareUid,
                'item_id' => $itemId,
                'container_id' => null,
                'assigned_object_type' => 'ITEM',
                'assigned_object_id' => $itemId,
                'is_primary' => false,
                'assigned_at' => now(),
                'assigned_by' => $this->actorId(),
                'status' => 'ACTIVE',
                'version' => 1,
                'created_by' => $this->actorId(),
                'updated_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'ASSIGN_ITEM_RFID',
                objectType: 'physical_code',
                objectId: $physicalCodeId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['item_id' => $itemId, 'hardware_uid' => $hardwareUid, 'stock_effect' => 'NONE'],
            );

            return [
                'physical_code' => DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->first(),
                'created' => true,
            ];
        }, 3);

        return $this->respondSuccess([
            'outbound_document_id' => $documentId,
            'item_id' => $itemId,
            'physical_code' => $payload['physical_code'],
            'rfid_required_for_post' => false,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ], $payload['created'] ? 'Đã gắn RFID cho item; RFID không phải điều kiện Post Issue.' : 'RFID đã gắn trước đó cho item này.');
    }

    #[OA\Patch(
        path: '/api/v1/physical-codes/{id}',
        operationId: 'physical_code_update',
        summary: 'Cập nhật trạng thái mã vật lý (ACTIVE/INACTIVE/LOST/DAMAGED)',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'LOST', 'DAMAGED']),
                    new OA\Property(property: 'deactivation_reason', type: 'string', maxLength: 2000, nullable: true),
                    new OA\Property(property: 'is_primary', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật mã vật lý thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy mã vật lý'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
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
                ApiError::abort(412, 'STALE_VERSION', 'Physical code version mismatch.');
            }
            if ($validated['status'] !== 'ACTIVE' && empty($validated['deactivation_reason'])) {
                ApiError::abort(422, 'DEACTIVATION_REASON_REQUIRED', 'LOST/DAMAGED/INACTIVE bắt buộc deactivation_reason.');
            }
            if ($validated['status'] !== 'ACTIVE'
                && ($code->assigned_object_type ?? null) === 'ITEM'
                && ! empty($code->assigned_object_id)
            ) {
                $inOpenOutbound = DB::table('outbound_item_matches as m')
                    ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
                    ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
                    ->where('m.item_id', $code->assigned_object_id)
                    ->whereIn('d.status', ['DRAFT', 'SCANNING'])
                    ->where(function ($query): void {
                        $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
                    })
                    ->exists();
                if ($inOpenOutbound) {
                    ApiError::abort(409, 'PHYSICAL_CODE_IN_OPEN_OUTBOUND', 'Mã đang gắn item thuộc phiếu xuất DRAFT/SCANNING; gỡ match hoặc hủy phiếu trước khi vô hiệu hóa.');
                }
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

        return $this->respondSuccess($result, 'Cập nhật mã vật lý thành công.');
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

}
