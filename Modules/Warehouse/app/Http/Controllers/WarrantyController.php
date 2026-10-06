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
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\InventorySerial;
use Modules\Warehouse\Models\WarrantyCase;
use Modules\Warehouse\Models\WarrantyEvent;
use Modules\Warehouse\Support\MasterSelectionGuard;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use OpenApi\Attributes as OA;

class WarrantyController extends Controller
{
    use ApiResponse;

    /** @var array<int, string> */
    private const ELIGIBLE_SERIAL_STATUSES = [
        InventorySerial::STATUS_ISSUED,
        InventorySerial::STATUS_RETURNED_TO_CUSTOMER,
    ];

    /**
     * SRS §6 + unique index uk_warranty_case_one_active_item (status NOT IN
     * RETURNED/CANCELLED): case đã RETURNED không còn là "active case" —
     * thiếu RETURNED ở đây gây 409 ACTIVE_WARRANTY_EXISTS oan. Giữ CLOSED
     * cho dữ liệu legacy nếu còn.
     *
     * @var array<int, string>
     */
    private const TERMINAL_CASE_STATUSES = [
        WarrantyCase::STATUS_RETURNED,
        WarrantyCase::STATUS_CLOSED,
        WarrantyCase::STATUS_CANCELLED,
    ];

    // WWHN-153 root-cause fix 2026-08-20: attribute này trước đây gắn trên `class
    // WarrantyController` thay vì trên method — swagger-php chỉ bind OA\Post theo
    // method, nên operation không bao giờ nhận requestBody này. RouteSyncProcessor
    // (app/Swagger/Processors/RouteSyncProcessor.php:458) coi requestBody là
    // Undefined và fallback generic `{type: object, additionalProperties: true}`,
    // đúng như QA1 WWHN-152 ghi nhận "typed schema still generic" và FE1 WWHN-180
    // ghi nhận TEMP_ONLY requestBody thiếu trong artifact. `required` cũng bổ sung
    // `manual_product_description` vì DB CHECK chk_erd_warranty_identification_shape
    // và validate() runtime (dòng dưới) đều bắt buộc field này, không phải optional.
    #[OA\Post(
        path: '/api/v1/warranty-cases/{id}/temp-only',
        operationId: 'warranty_temp_only',
        summary: 'Tiếp nhận bảo hành chưa định danh, không tác động tồn kho',
        description: 'TEMP_ONLY chỉ cập nhật trạng thái phiếu và tạo warranty_event. Không liên kết inventory_serial, không tạo stock_movement và không cập nhật inventory_balance.',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                schema: new OA\Schema(type: 'string', minLength: IdempotencyKeyGuard::MIN_LENGTH, maxLength: IdempotencyKeyGuard::MAX_LENGTH)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['temporary_item_code', 'missing_code_reason', 'manual_product_description', 'condition_status'],
                properties: [
                    new OA\Property(property: 'temporary_item_code', type: 'string', maxLength: 150, example: 'TEMP-20260821-0001'),
                    new OA\Property(property: 'missing_code_reason', type: 'string', maxLength: 500, example: 'Nhãn bị mờ không đọc được mã'),
                    new OA\Property(property: 'manual_product_description', type: 'string', maxLength: 2000, example: 'Tivi Samsung 55 inch'),
                    new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', nullable: true, example: '2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f'),
                    new OA\Property(property: 'condition_status', type: 'string', enum: ['WAITING_CHECK', 'INSPECTING'], example: 'WAITING_CHECK'),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true, example: 'Khách mang đến trực tiếp, không có hộp'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tiếp nhận TEMP_ONLY thành công, stock_effect=NONE'),
            new OA\Response(response: 403, description: 'Thiếu permission warranty.temp_only'),
            new OA\Response(response: 409, description: 'Sai trạng thái, active warranty trùng hoặc khóa idempotency xung đột payload'),
            new OA\Response(response: 422, description: 'Dữ liệu hoặc Idempotency-Key không hợp lệ, hoặc serial chưa Post Issue'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key'),
        ]
    )]
    public function tempOnly(Request $request, string $id)
    {
        $idemKey = IdempotencyKeyGuard::require($request);

        $validated = $request->validate([
            'temporary_item_code' => ['required', 'string', 'max:150'],
            'missing_code_reason' => ['required', 'string', 'max:500'],
            // Annex D SCR-WAR-01: manual_product_description bắt buộc khi TEMP_ONLY
            // (DB CHECK chk_erd_warranty_identification_shape enforce).
            'manual_product_description' => ['required', 'string', 'max:2000'],
            'defect_id' => ['nullable', 'uuid', 'exists:defect_catalog,defect_id'],
            'condition_status' => ['required', 'string', 'in:WAITING_CHECK,INSPECTING'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        // BR-DEF-01..04: không gán bệnh/lỗi INACTIVE, nhưng giữ nguyên giá trị cũ
        // của hồ sơ vẫn hợp lệ (lịch sử không bị đổi).
        MasterSelectionGuard::assertDefectSelectable(
            $validated['defect_id'] ?? null,
            DB::table('warranty_case')->where('warranty_case_id', $id)->value('defect_id')
        );

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

                    // §6: case chỉ có RECEIVED/CHECKING/REPAIRING/COMPLETED/RETURNED/
                    // CANCELLED. Chuyển sang TEMP_ONLY là sửa nhận diện lúc tiếp nhận
                    // nên chỉ hợp lệ khi case chưa đi qua bước kiểm tra.
                    if ($case->status !== WarrantyCase::STATUS_RECEIVED) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Phiếu phải ở trạng thái RECEIVED để chuyển TEMP_ONLY.');
                    }

                    $this->assertLinkedSerialEligible($case);

                    $fromStatus = $case->status;
                    $fromInventorySerialId = $case->inventory_serial_id;
                    $fromProductId = $case->product_id;
                    $case->update([
                        'inventory_serial_id' => null,
                        // View warranty_case expose cả alias item_id — phải null tường minh
                        // để trigger không COALESCE lại giá trị cũ (shape TEMP_ONLY đòi item null).
                        'item_id' => null,
                        'product_id' => null,
                        'temporary_item_code' => $validated['temporary_item_code'],
                        'identification_status' => 'TEMP_ONLY',
                        'missing_code_reason' => $validated['missing_code_reason'],
                        'manual_product_description' => $validated['manual_product_description'] ?? null,
                        'defect_id' => $validated['defect_id'] ?? null,
                        'condition_status' => $validated['condition_status'],
                        // SRS v1.12 §6: case status chỉ gồm RECEIVED/CHECKING/REPAIRING/
                        // COMPLETED/RETURNED/CANCELLED — TEMP_ONLY là identification_status,
                        // không phải state của case (DB CHECK chk_erd_warranty_cases_2 enforce).
                        'version' => ((int) $case->version) + 1,
                    ]);

                    WarrantyEvent::create([
                        'warranty_event_id' => (string) Str::uuid(),
                        'warranty_case_id' => $case->warranty_case_id,
                        'event_type' => WarrantyEvent::EVENT_TEMP_ONLY,
                        'from_status' => $fromStatus,
                        'to_status' => $fromStatus,
                        'note' => $validated['note'] ?? 'Tiếp nhận bảo hành chưa định danh',
                        'created_by' => $userId,
                        'event_at' => now(),
                    ]);

                    AuditLogger::record(
                        action: 'WARRANTY_TEMP_ONLY',
                        objectType: 'warranty_case',
                        objectId: $case->warranty_case_id,
                        actorId: $userId,
                        requestId: request()->attributes->get('request_id'),
                        beforeData: ['status' => $fromStatus, 'inventory_serial_id' => $fromInventorySerialId, 'product_id' => $fromProductId],
                        afterData: ['status' => $fromStatus, 'identification_status' => 'TEMP_ONLY', 'temporary_item_code' => $validated['temporary_item_code'], 'version' => $case->version],
                    );

                    return [
                        ...WarrantyWarehouseScope::fields((string) $case->warranty_case_id),
                        'warranty_case_id' => $case->warranty_case_id,
                        'status' => $fromStatus,
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

    private function assertLinkedSerialEligible(WarrantyCase $case): void
    {
        if ($case->inventory_serial_id === null) {
            return;
        }

        /** @var InventorySerial|null $serial */
        $serial = InventorySerial::query()->lockForUpdate()->find($case->inventory_serial_id);
        if ($serial === null) {
            ApiError::abort(422, 'WARRANTY_ITEM_NOT_ELIGIBLE', 'Serial liên kết không tồn tại; không được tiếp nhận bảo hành.');
        }

        $serialStatus = strtoupper((string) $serial->status);
        if (! in_array($serialStatus, self::ELIGIBLE_SERIAL_STATUSES, true)) {
            ApiError::abort(
                422,
                'WARRANTY_ITEM_NOT_ELIGIBLE',
                'Serial phải hoàn tất Post Issue trước khi tiếp nhận bảo hành.',
                [
                    'inventory_serial_id' => (string) $serial->id,
                    'serial_status' => $serialStatus,
                    'eligible_statuses' => self::ELIGIBLE_SERIAL_STATUSES,
                    'required_action' => 'POST_ISSUE',
                ]
            );
        }

        $otherActiveCase = WarrantyCase::query()
            ->where('inventory_serial_id', $serial->id)
            ->where('warranty_case_id', '!=', $case->warranty_case_id)
            ->whereNotIn('status', self::TERMINAL_CASE_STATUSES)
            ->lockForUpdate()
            ->first(['warranty_case_id']);

        if ($otherActiveCase !== null) {
            ApiError::abort(
                409,
                'ACTIVE_WARRANTY_EXISTS',
                'Serial đã có một phiếu bảo hành đang hoạt động.',
                [
                    'inventory_serial_id' => (string) $serial->id,
                    'active_warranty_case_id' => (string) $otherActiveCase->warranty_case_id,
                ]
            );
        }
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
