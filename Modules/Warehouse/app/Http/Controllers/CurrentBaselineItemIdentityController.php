<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\Database\SchemaCache;
use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

class CurrentBaselineItemIdentityController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    /**
     * Gán/cấp/thu hồi định danh vật lý là ghi STOCK-NEUTRAL: không qua mutateBalance()
     * nên không ai bump 'stock:version' hộ, trong khi read cache tra cứu mã (Mini App
     * bảo hành, truy vết theo mã, chi tiết phiếu xuất) đọc thẳng item_instances /
     * physical_codes vừa ghi. Bump nằm trong closure IdempotencyStore nên replay
     * không xoá cache vô ích.
     */
    private function invalidateReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    #[OA\Post(
        path: '/api/v1/skus/{id}/physical-codes',
        operationId: 'sku_item_physical_code_assign',
        summary: 'Gán định danh vật lý (physical code) cho item của SKU, stock-neutral',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['item_id', 'code_value', 'carrier_type'],
                properties: [
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', example: '9c6b1a2e-3f4d-4b7a-8c1e-2d3f4a5b6c7d'),
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 200, example: 'HN-ITEM-000123'),
                    new OA\Property(property: 'carrier_type', type: 'string', enum: ['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'], example: 'BARCODE'),
                    new OA\Property(property: 'hardware_uid', type: 'string', maxLength: 200, nullable: true, description: 'Bắt buộc khi carrier_type=RFID', example: 'RFID-UID-000456'),
                    new OA\Property(property: 'encoded_payload_version', type: 'string', maxLength: 30, nullable: true, example: 'v1'),
                    new OA\Property(property: 'is_primary', type: 'boolean', nullable: true, example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Gán physical code thành công (stock_effect=NONE)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU ACTIVE'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: item không thuộc SKU, RFID thiếu hardware_uid, code_value/hardware_uid trùng)'),
        ]
    )]
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
                    $this->invalidateReadCache();

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

                return [201, $this->envelope('Đã gán định danh vật lý cho item.', $data)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    /**
     * Phát hành item mới: chưa hề có UI/API nào tạo được Item_Instance +
     * Physical_Code cùng lúc trước đây — chỗ duy nhất trước đây chỉ gán code
     * cho item ĐÃ tồn tại (assign() ở trên) hoặc tạo item nhưng bắt buộc
     * physical_code_id đã có sẵn (ItemInstanceController@store). Endpoint này
     * lấp khoảng trống: tạo cả hai cùng transaction, giống hệt cơ chế
     * registerCandidateItems() dùng khi Post Receipt (CurrentBaselineStockService),
     * kể cả cách điền cột driver-dependent (id/item_code/status/condition) qua
     * Schema::hasColumn để tương thích cả sqlite test lẫn ERD PostgreSQL.
     *
     * Stock-neutral: item tạo ra ở item_status=NEW, KHÔNG cộng vào tồn kho —
     * đúng ngữ nghĩa "đăng ký định danh trước", giống candidate item của luồng
     * nhập kho trước khi Post Receipt chuyển sang IN_STOCK.
     */
    #[OA\Post(
        path: '/api/v1/skus/{id}/items',
        operationId: 'sku_item_issue',
        summary: 'Tạo Item_Instance mới kèm Physical_Code mới (QR/Barcode) cho SKU serial-tracked, stock-neutral',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'carrier_type', type: 'string', enum: ['QR', 'BARCODE'], nullable: true, description: 'Mặc định QR', example: 'QR'),
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 200, nullable: true, description: 'Bỏ trống thì server tự sinh theo pattern <SKU_CODE>-<timestamp>-<random>', example: 'SP-0001-20260822-AB12'),
                    new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, nullable: true, description: 'Bỏ trống thì server tự sinh SN-<SKU_CODE>-<random>', example: 'SN-SP-0001-8F3C1A2B'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo item + physical code thành công (stock_effect=NONE)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU ACTIVE'),
            new OA\Response(response: 422, description: 'SKU không phải PRODUCT/serial-tracked, hoặc code_value/serial_number trùng'),
            new OA\Response(response: 428, description: 'PRECONDITION_REQUIRED — thiếu Idempotency-Key'),
        ]
    )]
    public function issue(Request $request, string $skuId)
    {
        $validated = $request->validate([
            'carrier_type' => ['nullable', Rule::in(['QR', 'BARCODE'])],
            'code_value' => ['nullable', 'string', 'max:200', 'unique:physical_codes,code_value'],
            'serial_number' => ['nullable', 'string', 'max:100', 'unique:item_instances,serial_number'],
        ]);
        $carrierType = strtoupper($validated['carrier_type'] ?? 'QR');

        $key = IdempotencyKeyGuard::require($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "item_identity_issue_{$skuId}",
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            function () use ($skuId, $validated, $carrierType, $request): array {
                $data = DB::transaction(function () use ($skuId, $validated, $carrierType, $request): array {
                    $sku = DB::table('sku')->where('sku_id', $skuId)->lockForUpdate()->first();
                    if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
                        ApiError::abort(404, 'SKU_NOT_FOUND', 'Không tìm thấy SKU ACTIVE.');
                    }
                    if (strtoupper((string) $sku->sku_type) !== 'PRODUCT' || ! (bool) $sku->is_serial_tracked) {
                        ApiError::abort(422, 'SKU_NOT_SERIAL_TRACKED', 'Chỉ SKU loại PRODUCT có is_serial_tracked=true (tracking_mode=SERIAL) mới tạo được Item_Instance kèm mã định danh mới.');
                    }
                    $skuCode = strtoupper(trim((string) $sku->sku_code));

                    $codeValue = isset($validated['code_value']) && trim((string) $validated['code_value']) !== ''
                        ? strtoupper(trim((string) $validated['code_value']))
                        : $this->generateUniqueCodeValue($skuCode);
                    $serial = isset($validated['serial_number']) && trim((string) $validated['serial_number']) !== ''
                        ? strtoupper(trim((string) $validated['serial_number']))
                        : $this->generateUniqueSerialNumber($skuCode);

                    if (DB::table('physical_codes')->where('code_value', $codeValue)->exists()) {
                        ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', [
                            'code_value' => ['Mã "'.$codeValue.'" đã được gán cho một đối tượng khác trong hệ thống. Vui lòng dùng mã khác.'],
                        ]);
                    }
                    if (DB::table('item_instances')->where('serial_number', $serial)->exists()) {
                        ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu đầu vào không hợp lệ.', [
                            'serial_number' => ['Số serial "'.$serial.'" đã tồn tại trong hệ thống. Vui lòng dùng số khác.'],
                        ]);
                    }

                    $itemId = (string) Str::uuid();
                    $itemRow = [
                        'item_id' => $itemId,
                        'sku_id' => $skuId,
                        'serial_number' => $serial,
                        'item_status' => 'NEW',
                        'item_condition' => 'NORMAL',
                        'version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    // Cột chỉ có trên nhánh ERD PostgreSQL (id/item_code/status/condition/
                    // created_by/updated_by) — điền có điều kiện, giống registerCandidateItems()
                    // trong CurrentBaselineStockService, để không vỡ trên sqlite test driver.
                    foreach ([
                        'id' => $itemId,
                        'item_code' => $codeValue,
                        'status' => 'NEW',
                        'condition' => 'NORMAL',
                        'created_by' => $this->actorId(),
                        'updated_by' => $this->actorId(),
                    ] as $column => $value) {
                        if (SchemaCache::hasColumn('item_instances', $column)) {
                            $itemRow[$column] = $value;
                        }
                    }
                    DB::table('item_instances')->insert($itemRow);

                    $physicalCodeId = (string) Str::uuid();
                    DB::table('physical_codes')->insert([
                        'physical_code_id' => $physicalCodeId,
                        'code_value' => $codeValue,
                        'code_type' => 'ITEM',
                        'carrier_type' => $carrierType,
                        'hardware_uid' => null,
                        'item_id' => $itemId,
                        'container_id' => null,
                        'assigned_object_type' => 'ITEM',
                        'assigned_object_id' => $itemId,
                        'is_primary' => true,
                        'assigned_at' => now(),
                        'assigned_by' => $this->actorId(),
                        'encoded_payload_version' => null,
                        'status' => 'ACTIVE',
                        'version' => 1,
                        'created_by' => $this->actorId(),
                        'updated_by' => $this->actorId(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('item_instances')->where('item_id', $itemId)->update([
                        'physical_code_id' => $physicalCodeId,
                        'updated_at' => now(),
                    ]);

                    AuditLogger::record(
                        action: 'ITEM_ISSUE_NEW_CODE',
                        objectType: 'item_instance',
                        objectId: $itemId,
                        actorId: $this->actorId(),
                        requestId: $request->attributes->get('request_id'),
                        afterData: [
                            'sku_id' => $skuId,
                            'serial_number' => $serial,
                            'physical_code_id' => $physicalCodeId,
                            'code_value' => $codeValue,
                            'carrier_type' => $carrierType,
                            'stock_effect' => 'NONE',
                        ],
                    );
                    $this->invalidateReadCache();

                    return [
                        'item_id' => $itemId,
                        'sku_id' => $skuId,
                        'serial_number' => $serial,
                        'item_status' => 'NEW',
                        'item_condition' => 'NORMAL',
                        'physical_code_id' => $physicalCodeId,
                        'code_value' => $codeValue,
                        'carrier_type' => $carrierType,
                        'is_primary' => true,
                        'status' => 'ACTIVE',
                        'version' => 1,
                        'stock_effect' => 'NONE',
                    ];
                }, 3);

                return [201, $this->envelope('Đã tạo item mới kèm mã định danh.', $data)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function generateUniqueCodeValue(string $skuCode): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $skuCode.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! DB::table('physical_codes')->where('code_value', $candidate)->exists()) {
                return $candidate;
            }
        }
        // Backstop cực hiếm khi 5 lần liền trùng: UUID đảm bảo duy nhất tuyệt đối.
        return $skuCode.'-'.Str::upper(Str::uuid()->toString());
    }

    private function generateUniqueSerialNumber(string $skuCode): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'SN-'.$skuCode.'-'.Str::upper(Str::random(8));
            if (! DB::table('item_instances')->where('serial_number', $candidate)->exists()) {
                return $candidate;
            }
        }
        return 'SN-'.$skuCode.'-'.Str::upper(Str::uuid()->toString());
    }

    #[OA\Delete(
        path: '/api/v1/skus/{id}/physical-codes/{physicalCodeId}',
        operationId: 'item_identity_revoke',
        summary: 'Vô hiệu hoá mã vật lý gán cho item (deactivate, không xoá lịch sử)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'sku_id', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'physicalCodeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã vô hiệu hoá định danh vật lý'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy item physical code, hoặc không thuộc SKU này'),
            new OA\Response(response: 409, description: 'PHYSICAL_CODE_NOT_ACTIVE — chỉ code ACTIVE mới được deactivate'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp version hiện tại'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match hợp lệ'),
        ]
    )]
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
                        ApiError::abort(412, 'STALE_VERSION', 'Mã vật lý đã bị thay đổi bởi thao tác khác. Vui lòng tải lại dữ liệu mới nhất.');
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
                    $this->invalidateReadCache();

                    return [
                        'physical_code_id' => $physicalCodeId,
                        'sku_id' => $skuId,
                        'item_id' => $itemId,
                        'status' => 'INACTIVE',
                        'version' => $expectedVersion + 1,
                        'stock_effect' => 'NONE',
                    ];
                }, 3);

                return [200, $this->envelope('Đã vô hiệu hóa định danh vật lý.', $data)];
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

}
