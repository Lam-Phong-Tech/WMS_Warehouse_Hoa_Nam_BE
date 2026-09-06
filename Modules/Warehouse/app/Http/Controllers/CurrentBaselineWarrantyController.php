<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\Validation\InputLimits;
use App\Support\WarrantyPiiMasker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use OpenApi\Attributes as OA;

class CurrentBaselineWarrantyController extends CurrentBaselineOperationsController
{
    use ResolvesActorOrNull { actorIdOrNull as currentActorId; }

    /**
     * DEC-WAR-STATE-001 (WWHN-244 / DEV244-P1-02) - ma tran trang thai da chot tren
     * SRS/FRD v1.12 §6 + ERD §45. Diem duy nhat docs khong phat bieu tuong minh la
     * CHECKING -> COMPLETED: disposition da chot la CHO PHEP, vi case kiem tra xong
     * ket luan khong can sua khong duoc ep di qua REPAIRING gia de dong - neu ep,
     * REPAIRING mat nghia va metric turnaround "da sua chua" cua RPT-WAR-01 bi thoi
     * phong. Bu lai, moi nhanh roi CHECKING deu bi chan boi guard chan doan ben duoi.
     */
    private const TRANSITIONS = [
        'RECEIVED' => ['CHECKING', 'CANCELLED'],
        'CHECKING' => ['REPAIRING', 'COMPLETED', 'CANCELLED'],
        'REPAIRING' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['RETURNED', 'CANCELLED'],
        'RETURNED' => [],
        'CANCELLED' => [],
    ];

    /**
     * DEC-WAR-STATE-001: cac trang thai chi vao duoc khi da co ket luan kiem tra.
     * ERD §45 confirmed_defect = "Ket qua kiem tra".
     */
    private const REQUIRES_DIAGNOSIS = ['REPAIRING', 'COMPLETED'];

    /** §6: hai trạng thái không còn transition nào — case đóng, dữ liệu chỉ đọc. */
    private const TERMINAL_STATUSES = ['RETURNED', 'CANCELLED'];

    /** §13.8: PII bảo hành giữ 5 năm sau khi đóng case. */
    public const PII_RETENTION_YEARS = 5;

    /** §13.8: các cột PII bị anonymize khi hết hạn lưu trữ. */
    public const PII_COLUMNS = ['customer_name', 'customer_phone', 'customer_address'];

    /** ERD §46: event_type RECEIVE/CHECK/REPAIR/COMPLETE/RETURN/CANCEL/NOTE. */
    private const STATUS_EVENT_TYPES = [
        'RECEIVED' => 'RECEIVE',
        'CHECKING' => 'CHECK',
        'REPAIRING' => 'REPAIR',
        'COMPLETED' => 'COMPLETE',
        'RETURNED' => 'RETURN',
        'CANCELLED' => 'CANCEL',
    ];

    #[OA\Post(
        path: '/api/v1/warranty-cases',
        operationId: 'createWarrantyCase',
        summary: 'Tiếp nhận hồ sơ bảo hành mới (RECEIVED, stock_effect=NONE)',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customer_name', 'customer_phone', 'description'],
                properties: [
                    new OA\Property(property: 'warranty_case_code', type: 'string', maxLength: 80, nullable: true, description: 'Bỏ trống để hệ thống tự sinh mã phiếu.', example: 'WC-20260821-AB12'),
                    new OA\Property(property: 'case_no', type: 'string', maxLength: 80, nullable: true, description: 'Alias của warranty_case_code.', example: 'WC-20260821-AB12'),
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true, description: 'Item đã xuất cho khách; thiếu item thì case ở nhánh TEMP_ONLY.', example: '9c6b1a2e-3f4d-4b7a-8c1e-2d3f4a5b6c7d'),
                    new OA\Property(property: 'item_code', type: 'string', maxLength: 200, nullable: true, example: 'SN-000123456'),
                    new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', nullable: true, example: '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e'),
                    new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, nullable: true, example: 'DEF-SCREEN-001'),
                    new OA\Property(property: 'customer_name', type: 'string', maxLength: 200, description: 'PII — mask theo quyền warranty.pii.view khi đọc lại.', example: 'Nguyễn Văn A'),
                    new OA\Property(property: 'customer_phone', type: 'string', maxLength: 30, example: '0912345678'),
                    new OA\Property(property: 'customer_address', type: 'string', maxLength: 500, nullable: true, example: '123 Đường Lê Lợi, Quận 1, TP.HCM'),
                    new OA\Property(property: 'purchase_date', type: 'string', format: 'date', nullable: true, description: 'Không được sau ngày hiện tại.', example: '2026-06-15'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, example: 'Sản phẩm lỗi màn hình, khách yêu cầu bảo hành'),
                    new OA\Property(property: 'reported_defect', type: 'string', maxLength: 2000, nullable: true, example: 'Màn hình bị sọc ngang khi khởi động'),
                    new OA\Property(property: 'accessories_received', type: 'string', maxLength: 1000, nullable: true, example: 'Sạc, hộp, dây cáp'),
                    new OA\Property(property: 'received_condition', type: 'string', maxLength: 500, nullable: true, example: 'Máy còn nguyên vẹn, có trầy nhẹ ở góc'),
                    new OA\Property(property: 'missing_code_reason', type: 'string', maxLength: 500, nullable: true, description: 'Bắt buộc cùng manual_product_description khi không resolve được item (TEMP_ONLY).', example: 'Mất tem serial do sử dụng lâu ngày'),
                    new OA\Property(property: 'manual_product_description', type: 'string', maxLength: 2000, nullable: true, example: 'Điện thoại Samsung Galaxy A54, màu đen'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Case bảo hành đã tiếp nhận (RECEIVED); ETag = version; stock_effect=NONE.'),
        ]
    )]
    public function storeWarrantyCase(Request $request)
    {
        // Annex C createWarrantyCase: cột Idempotency/If-Match = "-" và §8.1 nhóm
        // Warranty không liệt kê 428 -> header là tùy chọn, có thì vẫn replay.
        $idempotencyKey = IdempotencyKeyGuard::optional($request);
        $validated = $request->validate([
            'warranty_case_code' => ['nullable', 'string', 'max:80'],
            'case_no' => ['nullable', 'string', 'max:80'],
            'item_id' => ['nullable', 'uuid'],
            // Change Pack QR/SKU: item_code chấp nhận cả RAW composite QR (trần 512).
            'item_code' => ['nullable', 'string', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'defect_ids.*' => ['uuid', 'distinct'],
            'defect_code' => ['nullable', 'string', 'max:80'],
            'estimated_completion_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.InputLimits::futureDateMax()],
            'customer_name' => ['required', 'string', 'max:200'],
            // Rà soát validate định dạng input (Flow04 Warranty): SĐT khách VN —
            // bắt đầu bằng 0, đúng 10 chữ số. Không đổi required/max hiện có, chỉ
            // thêm ràng buộc định dạng để chặn nhập chữ/độ dài sai lúc tiếp nhận.
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            // Annex A/D: customer_address tối đa 500 ký tự.
            'customer_address' => ['nullable', 'string', 'max:500'],
            'purchase_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'description' => ['required', 'string', 'max:2000'],
            'reported_defect' => ['nullable', 'string', 'max:2000'],
            'accessories_received' => ['nullable', 'string', 'max:1000'],
            'received_condition' => ['nullable', 'string', 'max:500'],
            'missing_code_reason' => ['nullable', 'string', 'max:500'],
            'manual_product_description' => ['nullable', 'string', 'max:2000'],
        ], [
            'customer_phone.regex' => 'Số điện thoại phải bắt đầu bằng số 0 và đủ 10 chữ số.',
        ]);

        $execute = function () use ($validated, $request): array {
            $caseId = $this->createWarrantyCase($validated, $request);

            return [201, $this->caseEnvelope($caseId, $request, 'Đã tiếp nhận ca bảo hành; tồn kho chưa thay đổi (stock_effect=NONE).')];
        };

        [$code, $body, $replay] = $idempotencyKey === null
            ? [...$execute(), false]
            : IdempotencyStore::handle(
                $idempotencyKey,
                'create_warranty_case',
                $this->currentActorId(),
                IdempotencyStore::requestHash($request),
                $execute
            );

        return response()->json($body, $code)
            ->header('Idempotent-Replay', $replay ? 'true' : 'false')
            ->header('ETag', '"'.(int) ($body['data']['version'] ?? 1).'"');
    }

    private function createWarrantyCase(array $validated, Request $request): string
    {
        return DB::transaction(function () use ($validated, $request): string {
            $item = $this->resolveWarrantyItem($validated);
            $defectIds = $this->resolveDefectIds($validated);

            if ($item !== null) {
                if (! in_array((string) $item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)) {
                    ApiError::abort(422, 'WARRANTY_ITEM_NOT_ELIGIBLE', 'Item phải đã xuất cho khách trước khi tiếp nhận bảo hành.', [
                        'item_id' => (string) $item->item_id,
                        'item_status' => (string) $item->item_status,
                    ]);
                }
                $active = DB::table('warranty_case')
                    ->where('item_id', $item->item_id)
                    ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
                    ->exists();
                if ($active) {
                    ApiError::abort(409, 'ACTIVE_WARRANTY_EXISTS', 'Item đã có warranty case đang hoạt động.');
                }
            } elseif (empty($validated['missing_code_reason']) || empty($validated['manual_product_description'])) {
                ApiError::abort(422, 'TEMP_ONLY_EVIDENCE_REQUIRED', 'TEMP_ONLY bắt buộc missing_code_reason và manual_product_description.');
            }

            $caseCode = trim((string) ($validated['warranty_case_code'] ?? $validated['case_no'] ?? ''));
            if ($caseCode === '') {
                $caseCode = 'WC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            }
            if (DB::table('warranty_case')->where('warranty_case_code', $caseCode)->exists()) {
                ApiError::abort(422, 'WARRANTY_CASE_CODE_EXISTS', 'Mã phiếu bảo hành đã tồn tại.');
            }

            $caseId = (string) Str::uuid();
            DB::table('warranty_case')->insert([
                'warranty_case_id' => $caseId,
                'warranty_case_code' => $caseCode,
                'item_id' => $item?->item_id,
                'inventory_serial_id' => null,
                'product_id' => $item?->sku_id,
                'temporary_item_code' => $item ? null : ('TEMP-'.Str::upper(Str::random(10))),
                'identification_status' => $item ? 'IDENTIFIED' : 'TEMP_ONLY',
                'sales_trace_status' => $item ? 'TRACEABLE' : 'NOT_TRACEABLE',
                'missing_code_reason' => $validated['missing_code_reason'] ?? null,
                'manual_product_description' => $validated['manual_product_description'] ?? null,
                // Kept as the primary/legacy value during the contract transition.
                'defect_id' => $defectIds[0] ?? null,
                'estimated_completion_date' => $validated['estimated_completion_date'] ?? null,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_address' => $validated['customer_address'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? null,
                'status' => 'RECEIVED',
                'description' => $validated['description'],
                'reported_defect' => $validated['reported_defect'] ?? $validated['description'],
                'accessories_received' => $validated['accessories_received'] ?? null,
                'received_condition' => $validated['received_condition'] ?? null,
                'received_at' => now(),
                'created_by' => $this->currentActorId(),
                'version' => 1,
                'legal_hold_flag' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->syncWarrantyDefects($caseId, $defectIds);

            if ($item !== null) {
                DB::table('item_instances')->where('item_id', $item->item_id)->update([
                    'item_status' => 'IN_WARRANTY',
                    'updated_at' => now(),
                ]);
            }

            DB::table('warranty_event')->insert([
                'warranty_event_id' => (string) Str::uuid(),
                'warranty_case_id' => $caseId,
                'event_type' => 'RECEIVE',
                'from_status' => null,
                'to_status' => 'RECEIVED',
                'note' => $validated['description'],
                'created_by' => $this->currentActorId(),
                'event_at' => now(),
            ]);

            AuditLogger::record(
                action: 'WARRANTY_CASE_RECEIVED',
                objectType: 'warranty_case',
                objectId: $caseId,
                actorId: $this->currentActorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: [
                    'status' => 'RECEIVED',
                    'version' => 1,
                    'identification_status' => $item ? 'IDENTIFIED' : 'TEMP_ONLY',
                    'item_id' => $item?->item_id,
                    'defect_id' => $defectIds[0] ?? null,
                    'defect_ids' => $defectIds,
                    'stock_effect' => 'NONE',
                ],
            );

            event(new \Modules\Warehouse\Events\WarrantyCaseCreated($caseId, $caseCode, $this->currentActorId()));
            // Mở hồ sơ bảo hành là stock-neutral nhưng đổi warranty_case/item_instances
            // — dữ liệu mà read cache tra cứu bảo hành và báo cáo lỗi đang phục vụ.
            $this->invalidateReadCache();

            return $caseId;
        }, 3);
    }

    public function showWarrantyCase(Request $request, string $id)
    {
        $payload = $this->casePayload($id, $request);
        if ($payload === []) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy ca bảo hành.');
        }

        return $this->respondSuccess($payload)->header('ETag', '"'.$payload['version'].'"');
    }

    #[OA\Post(
        path: '/api/v1/warranty-cases/{id}/status',
        operationId: 'transitionWarrantyStatus',
        summary: 'Annex C transitionWarrantyStatus — chuyển trạng thái / hiệu chỉnh case bảo hành',
        description: 'DEC-WAR-STATE-001 (SRS v1.12 §6 + ERD §45). Ma trận transition đã chốt: '
            .'RECEIVED->{CHECKING,CANCELLED}; CHECKING->{REPAIRING,COMPLETED,CANCELLED}; '
            .'REPAIRING->{COMPLETED,CANCELLED}; COMPLETED->{RETURNED,CANCELLED}; '
            .'RETURNED/CANCELLED là terminal (mọi mutation trả 409 STATE_CONFLICT). '
            .'Guard nghiệp vụ: rời CHECKING sang REPAIRING/COMPLETED bắt buộc confirmed_defect '
            .'(422 WARRANTY_DIAGNOSIS_REQUIRED); COMPLETED bắt buộc note kết quả xử lý '
            .'(422 WARRANTY_TREATMENT_RESULT_REQUIRED); RETURNED bắt buộc note biên bản bàn '
            .'giao/người nhận (422 WARRANTY_HANDOVER_NOTE_REQUIRED); CANCELLED bắt buộc lý do '
            .'(422 CANCEL_REASON_REQUIRED). Transition ngoài ma trận: 409 '
            .'INVALID_WARRANTY_TRANSITION. If-Match bắt buộc (428/412). Idempotency-Key tuỳ chọn. '
            .'Sửa customer_name/phone/address cần warranty.pii.view (403 PERMISSION_DENIED); '
            .'response luôn mask PII khi thiếu quyền (pii_masked=true). stock_effect=NONE.',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'], description: 'Trạng thái đích; bỏ trống nếu chỉ hiệu chỉnh dữ liệu.', example: 'RECEIVED'),
            new OA\Property(property: 'confirmed_defect', type: 'string', maxLength: 2000, description: 'Kết quả kiểm tra; bắt buộc khi rời CHECKING.', example: 'Màn hình bị vỡ do rơi, cần thay thế'),
            new OA\Property(property: 'note', type: 'string', maxLength: 2000, description: 'Ghi vào warranty_event.note; bắt buộc cho COMPLETED (kết quả xử lý) và RETURNED (biên bản bàn giao/người nhận).', example: 'Đã kiểm tra và sửa chữa xong, máy hoạt động bình thường'),
            new OA\Property(property: 'cancel_reason', type: 'string', maxLength: 1000, description: 'Bắt buộc khi CANCELLED.', example: 'Khách hàng yêu cầu hủy bảo hành'),
            new OA\Property(property: 'condition_status', type: 'string', maxLength: 30, example: 'Bình thường'),
            new OA\Property(property: 'accessories_received', type: 'string', maxLength: 1000, example: 'Sạc, hộp, dây cáp'),
            new OA\Property(property: 'received_condition', type: 'string', maxLength: 500, example: 'Máy còn nguyên vẹn, có trầy nhẹ ở góc'),
            new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', example: '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e'),
            new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, example: 'DEF-SCREEN-001'),
            new OA\Property(property: 'customer_name', type: 'string', maxLength: 200, description: 'PII — cần warranty.pii.view.', example: 'Nguyễn Văn A'),
            new OA\Property(property: 'customer_phone', type: 'string', maxLength: 30, example: '0901234567', description: 'PII — cần warranty.pii.view.'),
            new OA\Property(property: 'customer_address', type: 'string', maxLength: 500, description: 'PII — cần warranty.pii.view.', example: '123 Đường Lê Lợi, Quận 1, TP.HCM'),
            new OA\Property(property: 'purchase_date', type: 'string', format: 'date', example: '2026-06-15'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Case đã cập nhật; ETag = version mới; stock_effect=NONE.')]
    )]
    #[OA\Patch(
        path: '/api/v1/warranty-cases/{id}',
        operationId: 'updateWarrantyCase',
        summary: 'Cập nhật / chuyển trạng thái hồ sơ bảo hành (cùng handler với POST /warranty-cases/{id}/status)',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'], description: 'Trạng thái đích theo ma trận DEC-WAR-STATE-001; bỏ trống nếu chỉ hiệu chỉnh dữ liệu.', example: 'RECEIVED'),
            new OA\Property(property: 'condition_status', type: 'string', maxLength: 30, nullable: true, example: 'Bình thường'),
            new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Sản phẩm lỗi màn hình, khách yêu cầu bảo hành'),
            new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, description: 'Ghi vào warranty_event.note, không ghi đè mô tả tiếp nhận gốc.', example: 'Đã kiểm tra và sửa chữa xong, máy hoạt động bình thường'),
            new OA\Property(property: 'confirmed_defect', type: 'string', maxLength: 2000, nullable: true, description: 'Kết quả kiểm tra; bắt buộc khi rời CHECKING.', example: 'Màn hình bị vỡ do rơi, cần thay thế'),
            new OA\Property(property: 'accessories_received', type: 'string', maxLength: 1000, nullable: true, example: 'Sạc, hộp, dây cáp'),
            new OA\Property(property: 'received_condition', type: 'string', maxLength: 500, nullable: true, example: 'Máy còn nguyên vẹn, có trầy nhẹ ở góc'),
            new OA\Property(property: 'cancel_reason', type: 'string', maxLength: 1000, nullable: true, description: 'Bắt buộc khi CANCELLED.', example: 'Khách hàng yêu cầu hủy bảo hành'),
            new OA\Property(property: 'defect_id', type: 'string', format: 'uuid', nullable: true, example: '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e'),
            new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, nullable: true, example: 'DEF-SCREEN-001'),
            new OA\Property(property: 'customer_name', type: 'string', maxLength: 200, description: 'PII — cần warranty.pii.view.', example: 'Nguyễn Văn A'),
            new OA\Property(property: 'customer_phone', type: 'string', maxLength: 30, description: 'PII — cần warranty.pii.view.', example: '0912345678'),
            new OA\Property(property: 'customer_address', type: 'string', maxLength: 500, nullable: true, description: 'PII — cần warranty.pii.view.', example: '123 Đường Lê Lợi, Quận 1, TP.HCM'),
            new OA\Property(property: 'purchase_date', type: 'string', format: 'date', nullable: true, description: 'Không được sau ngày hiện tại.', example: '2026-06-15'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Case đã cập nhật; ETag = version mới; stock_effect=NONE.')]
    )]
    public function updateWarrantyCase(Request $request, string $id)
    {
        // Annex C transitionWarrantyStatus khai duy nhất If-Match; Idempotency-Key là
        // tùy chọn (xem storeWarrantyCase). If-Match vẫn bắt buộc -> 428 nếu thiếu.
        $idempotencyKey = IdempotencyKeyGuard::optional($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'in:RECEIVED,CHECKING,REPAIRING,COMPLETED,RETURNED,CANCELLED'],
            'condition_status' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            // §13.8: ghi chú timeline đi vào warranty_event.note, KHÔNG ghi đè
            // mô tả tiếp nhận gốc (description) của case.
            'note' => ['nullable', 'string', 'max:2000'],
            'confirmed_defect' => ['nullable', 'string', 'max:2000'],
            'accessories_received' => ['nullable', 'string', 'max:1000'],
            'received_condition' => ['nullable', 'string', 'max:500'],
            'cancel_reason' => ['nullable', 'string', 'max:1000'],
            // Annex D SCR-WAR-01 không giới hạn các field dưới đây ở bước tạo:
            // defect phải sửa được khi CHECKING kết luận khác, và PII sai lúc tiếp
            // nhận phải sửa được thay vì mở case mới.
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'defect_ids.*' => ['uuid', 'distinct'],
            'defect_code' => ['nullable', 'string', 'max:80'],
            'estimated_completion_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.InputLimits::futureDateMax()],
            'customer_name' => ['sometimes', 'string', 'max:200'],
            // Rà soát validate định dạng input (Flow04 Warranty): giữ nguyên
            // 'sometimes' (PII sửa được sau tiếp nhận), chỉ thêm định dạng SĐT VN
            // khi field này thực sự có mặt trong payload.
            'customer_phone' => ['sometimes', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'purchase_date' => array_merge(['nullable'], InputLimits::pastDate()),
        ], [
            'customer_phone.regex' => 'Số điện thoại phải bắt đầu bằng số 0 và đủ 10 chữ số.',
        ]);
        if ($validated === []) {
            ApiError::abort(422, 'EMPTY_UPDATE', 'Phải gửi ít nhất một field để cập nhật.');
        }
        // §13.8: không cho ghi đè PII khi actor chỉ thấy giá trị đã mask — nếu không,
        // một lần submit form sẽ lưu chính chuỗi mask lên dữ liệu thật.
        $piiFields = array_values(array_intersect(
            ['customer_name', 'customer_phone', 'customer_address'],
            array_keys($validated)
        ));
        if ($piiFields !== [] && ! $request->user()?->hasPermission('warranty.pii.view')) {
            ApiError::abort(403, 'PERMISSION_DENIED', 'Sửa thông tin khách hàng cần quyền warranty.pii.view.', [
                'fields' => $piiFields,
            ]);
        }
        $expectedVersion = $this->expectedVersion($request);

        // Replay cùng key trả lại snapshot đã commit; version check (412) chỉ chạy
        // cho lần thực thi đầu tiên nên retry sau khi thành công không bị 412 giả.
        $execute = function () use ($id, $validated, $expectedVersion, $request): array {
            $this->applyWarrantyCaseUpdate($id, $validated, $expectedVersion, $request);

            return [200, $this->caseEnvelope($id, $request, 'Đã cập nhật ca bảo hành; tồn kho chưa thay đổi (stock_effect=NONE).')];
        };

        [$code, $body, $replay] = $idempotencyKey === null
            ? [...$execute(), false]
            : IdempotencyStore::handle(
                $idempotencyKey,
                "update_warranty_case_{$id}",
                $this->currentActorId(),
                IdempotencyStore::requestHash($request),
                $execute
            );

        return response()->json($body, $code)
            ->header('Idempotent-Replay', $replay ? 'true' : 'false')
            ->header('ETag', '"'.(int) ($body['data']['version'] ?? 0).'"');
    }

    private function applyWarrantyCaseUpdate(string $id, array $validated, int $expectedVersion, Request $request): void
    {
        DB::transaction(function () use ($id, $validated, $expectedVersion, $request): void {
            $case = DB::table('warranty_case')->where('warranty_case_id', $id)->lockForUpdate()->first();
            if ($case === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy ca bảo hành.');
            }
            if ((int) $case->version !== $expectedVersion) {
                ApiError::abort(412, 'STALE_VERSION', 'Hồ sơ bảo hành vừa được cập nhật ở nơi khác. Hãy tải lại rồi thao tác lại.', [
                    'expected_version' => $expectedVersion,
                    'current_version' => (int) $case->version,
                ]);
            }
            // §6: RETURNED/CANCELLED là terminal. Nếu chỉ chặn transition thì request
            // không kèm `status` vẫn ghi được confirmed_defect/PII lên case đã đóng.
            if (in_array((string) $case->status, self::TERMINAL_STATUSES, true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Case bảo hành đã ở trạng thái terminal; không được cập nhật.', [
                    'current_status' => (string) $case->status,
                    'terminal_statuses' => self::TERMINAL_STATUSES,
                ]);
            }

            $target = (string) ($validated['status'] ?? $case->status);
            if ($target !== $case->status && ! in_array($target, self::TRANSITIONS[$case->status] ?? [], true)) {
                ApiError::abort(409, 'INVALID_WARRANTY_TRANSITION', "Không cho phép {$case->status} -> {$target}.");
            }

            if (array_key_exists('defect_ids', $validated)) {
                $defectIds = $this->resolveDefectIds($validated);
            } elseif (array_key_exists('defect_id', $validated) || array_key_exists('defect_code', $validated)) {
                $singleDefect = $this->resolveDefect($validated);
                $defectIds = $singleDefect !== null ? [(string) $singleDefect->defect_id] : [];
            } else {
                $defectIds = $this->currentDefectIds($id, $case->defect_id ?? null);
            }

            $cancelReason = trim((string) ($validated['cancel_reason'] ?? $validated['note'] ?? $validated['description'] ?? ''));
            if ($target === 'CANCELLED' && $case->status !== 'CANCELLED' && $cancelReason === '') {
                ApiError::abort(422, 'CANCEL_REASON_REQUIRED', 'Hủy case bảo hành bắt buộc có lý do/bằng chứng.');
            }

            // DEC-WAR-STATE-001 guard 1 - chan doan: khong roi CHECKING sang REPAIRING/
            // COMPLETED khi chua co confirmed_defect (payload lan nay hoac da luu truoc).
            $confirmedDefect = trim((string) ($validated['confirmed_defect'] ?? $case->confirmed_defect ?? ''));
            if ($target !== $case->status && in_array($target, self::REQUIRES_DIAGNOSIS, true) && $confirmedDefect === '') {
                ApiError::abort(422, 'WARRANTY_DIAGNOSIS_REQUIRED', 'Phải ghi kết quả kiểm tra (confirmed_defect) trước khi chuyển sang '.$target.'.', [
                    'current_status' => (string) $case->status,
                    'target_status' => $target,
                    'required_field' => 'confirmed_defect',
                ]);
            }

            // DEC-WAR-STATE-001 guard 2 - ket qua xu ly: COMPLETED phai kem mo ta da xu
            // ly gi, ghi vao warranty_event (§13.8 note timeline, khong de description).
            $handoverNote = trim((string) ($validated['note'] ?? $validated['description'] ?? ''));
            if ($target === 'COMPLETED' && $case->status !== 'COMPLETED' && $handoverNote === '') {
                ApiError::abort(422, 'WARRANTY_TREATMENT_RESULT_REQUIRED', 'Hoàn tất bảo hành bắt buộc ghi kết quả xử lý (note).', [
                    'target_status' => $target,
                    'required_field' => 'note',
                ]);
            }

            // DEC-WAR-STATE-001 guard 3 - ban giao/nguoi nhan: ERD §45 khong co cot
            // receiver rieng, bien ban ban giao la warranty_event RETURN voi note bat
            // buoc + created_by = actor. Thieu note thi khong co bang chung tra may.
            if ($target === 'RETURNED' && $case->status !== 'RETURNED' && $handoverNote === '') {
                ApiError::abort(422, 'WARRANTY_HANDOVER_NOTE_REQUIRED', 'Trả máy cho khách bắt buộc ghi biên bản bàn giao/người nhận (note).', [
                    'target_status' => $target,
                    'required_field' => 'note',
                ]);
            }

            $closing = in_array($target, self::TERMINAL_STATUSES, true);
            $closedAt = $closing ? ($case->closed_at ?? now()) : $case->closed_at;

            DB::table('warranty_case')->where('warranty_case_id', $id)->update([
                'status' => $target,
                'condition_status' => $validated['condition_status'] ?? $case->condition_status,
                'description' => $validated['description'] ?? $case->description,
                'confirmed_defect' => $validated['confirmed_defect'] ?? ($case->confirmed_defect ?? null),
                'accessories_received' => $validated['accessories_received'] ?? ($case->accessories_received ?? null),
                'received_condition' => $validated['received_condition'] ?? ($case->received_condition ?? null),
                'defect_id' => $defectIds[0] ?? null,
                'estimated_completion_date' => array_key_exists('estimated_completion_date', $validated)
                    ? $validated['estimated_completion_date']
                    : ($case->estimated_completion_date ?? null),
                'customer_name' => $validated['customer_name'] ?? $case->customer_name,
                'customer_phone' => $validated['customer_phone'] ?? $case->customer_phone,
                'customer_address' => array_key_exists('customer_address', $validated)
                    ? $validated['customer_address']
                    : ($case->customer_address ?? null),
                'purchase_date' => array_key_exists('purchase_date', $validated)
                    ? $validated['purchase_date']
                    : ($case->purchase_date ?? null),
                'cancel_reason' => $target === 'CANCELLED' ? ($cancelReason ?: ($case->cancel_reason ?? null)) : ($case->cancel_reason ?? null),
                'completed_at' => $target === 'COMPLETED' && $case->status !== 'COMPLETED' ? now() : ($case->completed_at ?? null),
                'returned_at' => $target === 'RETURNED' && $case->status !== 'RETURNED' ? now() : ($case->returned_at ?? null),
                'version' => $expectedVersion + 1,
                'closed_at' => $closedAt,
                // §13.8: PII giữ 5 năm sau khi đóng case rồi anonymize/purge. Mốc phải
                // được chốt ngay lúc đóng để job purge (warranty:purge-pii) có căn cứ.
                'retention_until' => $closing
                    ? Carbon::parse((string) $closedAt)->addYears(self::PII_RETENTION_YEARS)
                    : ($case->retention_until ?? null),
                'updated_at' => now(),
                'updated_by' => $this->currentActorId(),
            ]);
            // GĐ1-REQ-009: warranty_case_defects là contract canonical — đồng bộ cả khi
            // client chỉ gửi defect_id/defect_code đơn, nếu không hai nguồn sẽ lệch nhau.
            if (
                array_key_exists('defect_ids', $validated)
                || array_key_exists('defect_id', $validated)
                || array_key_exists('defect_code', $validated)
            ) {
                $this->syncWarrantyDefects($id, $defectIds);
            }

            if ($target !== $case->status) {
                DB::table('warranty_event')->insert([
                    'warranty_event_id' => (string) Str::uuid(),
                    'warranty_case_id' => $id,
                    'event_type' => self::STATUS_EVENT_TYPES[$target] ?? 'STATUS_CHANGE',
                    'from_status' => $case->status,
                    'to_status' => $target,
                    'note' => $validated['note'] ?? $validated['description'] ?? ($target === 'CANCELLED' ? $cancelReason : null),
                    'created_by' => $this->currentActorId(),
                    'event_at' => now(),
                ]);

                event(new \Modules\Warehouse\Events\WarrantyStatusChanged($id, $case->status, $target, $this->currentActorId()));
            } elseif (($validated['note'] ?? $validated['description'] ?? $validated['confirmed_defect'] ?? null) !== null) {
                DB::table('warranty_event')->insert([
                    'warranty_event_id' => (string) Str::uuid(),
                    'warranty_case_id' => $id,
                    'event_type' => 'NOTE',
                    'from_status' => $case->status,
                    'to_status' => $case->status,
                    'note' => $validated['description'] ?? $validated['confirmed_defect'],
                    'created_by' => $this->currentActorId(),
                    'event_at' => now(),
                ]);
            }

            if ($target === 'RETURNED' && $case->item_id !== null) {
                DB::table('item_instances')->where('item_id', $case->item_id)->update([
                    'item_status' => 'RETURNED_TO_CUSTOMER',
                    'updated_at' => now(),
                ]);
            }

            // §13.8: audit ghi metadata thay đổi, KHÔNG ghi giá trị PII thật vào log.
            $changedFields = array_values(array_diff(array_keys($validated), ['note']));

            AuditLogger::record(
                action: 'WARRANTY_STATUS_CHANGE',
                objectType: 'warranty_case',
                objectId: $id,
                actorId: $this->currentActorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: ['status' => $case->status, 'version' => $case->version],
                afterData: [
                    'status' => $target,
                    'version' => $expectedVersion + 1,
                    'changed_fields' => $changedFields,
                    'pii_fields_changed' => array_values(array_intersect($changedFields, self::PII_COLUMNS)),
                    'stock_effect' => 'NONE',
                ],
            );
            // Đổi trạng thái hồ sơ bảo hành là stock-neutral nhưng read cache tra cứu
            // bảo hành + báo cáo lỗi đọc thẳng cột vừa update.
            $this->invalidateReadCache();
        }, 3);
    }

    private function caseEnvelope(string $id, Request $request, string $message): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $this->casePayload($id, $request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    private function resolveWarrantyItem(array $validated): ?object
    {
        $itemId = trim((string) ($validated['item_id'] ?? ''));
        $itemCode = trim((string) ($validated['item_code'] ?? ''));

        if ($itemId !== '') {
            $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
            if ($item === null) {
                ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'Không tìm thấy item_id.');
            }

            return $item;
        }
        if ($itemCode === '') {
            return null;
        }

        // Change Pack QR/SKU §6.4 (AC-QR-06): item_code có thể là RAW composite QR
        // (WAR-02 quét thẳng hoặc nhập tay). Parse xong resolve theo normalized
        // item_unique; QR hỏng/không resolve được -> WARRANTY_ITEM_NOT_FOUND để UI
        // đi tiếp nhánh TEMP_ONLY, tuyệt đối không suy đoán link theo SKU/model.
        $parser = app(ProductQrPayloadParser::class);
        if ($parser->looksComposite($itemCode)) {
            $parsed = $parser->parse($itemCode);
            if (! $parsed->isValid()) {
                ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'QR composite không parse được; dùng TEMP_ONLY nếu không thể định danh item.', [
                    'parse_status' => $parsed->parseStatus,
                ]);
            }
            $identity = app(ProductIdentityResolver::class);
            $embeddedSku = $identity->requireActiveSkuByCode((string) $parsed->skuCode);
            $resolved = $identity->findItemByUnique((string) $parsed->itemUnique);
            if ($resolved === null) {
                ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'QR hợp lệ nhưng item chưa được đăng ký; dùng TEMP_ONLY nếu cần.', [
                    'item_unique' => $parsed->itemUnique,
                ]);
            }
            if ((string) $resolved->sku_id !== (string) $embeddedSku->sku_id) {
                ApiError::abort(422, 'WRONG_SKU', 'SKU embedded trong QR không khớp SKU của item đã đăng ký.', [
                    'sku_code' => $parsed->skuCode,
                    'item_unique' => $parsed->itemUnique,
                ]);
            }

            return DB::table('item_instances')->where('item_id', $resolved->item_id)->lockForUpdate()->first();
        }

        $item = DB::table('item_instances')
            ->where('serial_number', $itemCode)
            ->lockForUpdate()
            ->first();
        if ($item !== null) {
            return $item;
        }

        $mappedId = DB::table('physical_codes')
            ->where('code_value', $itemCode)
            ->where('status', 'ACTIVE')
            ->where('assigned_object_type', 'ITEM')
            ->value('assigned_object_id');
        if ($mappedId !== null) {
            $item = DB::table('item_instances')->where('item_id', $mappedId)->lockForUpdate()->first();
            if ($item !== null) {
                return $item;
            }
        }

        ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'Không resolve được item từ barcode/serial/physical code.', [
            'item_code' => $itemCode,
        ]);
    }

    private function resolveDefect(array $validated): ?object
    {
        $defectId = trim((string) ($validated['defect_id'] ?? ''));
        $defectCode = trim((string) ($validated['defect_code'] ?? ''));
        if ($defectId === '' && $defectCode === '') {
            return null;
        }

        $query = DB::table('defect_catalogs')->where('status', 'ACTIVE');
        $defect = $defectId !== ''
            ? $query->where('defect_id', $defectId)->first()
            : $query->where('defect_code', $defectCode)->first();
        if ($defect === null) {
            ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Không tìm thấy bệnh/lỗi ACTIVE.', [
                'defect_id' => $defectId ?: null,
                'defect_code' => $defectCode ?: null,
            ]);
        }

        return $defect;
    }

    /** @return list<string> */
    private function resolveDefectIds(array $validated): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $validated['defect_ids'] ?? []),
            fn (string $id): bool => trim($id) !== ''
        )));
        if ($ids === []) {
            $single = $this->resolveDefect($validated);

            return $single === null ? [] : [(string) $single->defect_id];
        }

        $activeIds = DB::table('defect_catalogs')
            ->where('status', 'ACTIVE')
            ->whereIn('defect_id', $ids)
            ->pluck('defect_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        if (count($activeIds) !== count($ids)) {
            ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Danh sách bệnh/lỗi phải gồm các mã ACTIVE hợp lệ.', [
                'defect_ids' => $ids,
            ]);
        }

        return $ids;
    }

    /** @return list<string> */
    private function currentDefectIds(string $caseId, mixed $legacyDefectId): array
    {
        $ids = Schema::hasTable('warranty_case_defects')
            ? DB::table('warranty_case_defects')->where('warranty_case_id', $caseId)->pluck('defect_id')->all()
            : [];
        if ($ids === [] && $legacyDefectId !== null) {
            $ids[] = $legacyDefectId;
        }

        return array_values(array_filter(array_map('strval', $ids)));
    }

    /** @param list<string> $defectIds */
    private function syncWarrantyDefects(string $caseId, array $defectIds): void
    {
        if (! Schema::hasTable('warranty_case_defects')) {
            return;
        }
        $hasPrimaryColumn = Schema::hasColumn('warranty_case_defects', 'is_primary');
        DB::table('warranty_case_defects')->where('warranty_case_id', $caseId)->delete();
        foreach (array_values($defectIds) as $index => $defectId) {
            $row = [
                'warranty_case_defect_id' => (string) Str::uuid(),
                'warranty_case_id' => $caseId,
                'defect_id' => $defectId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasPrimaryColumn) {
                // Phần tử đầu là defect chính — khớp warranty_case.defect_id legacy.
                $row['is_primary'] = $index === 0;
            }
            DB::table('warranty_case_defects')->insert($row);
        }
    }

    private function casePayload(string $id, Request $request): array
    {
        $case = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        if ($case === null) {
            return [];
        }

        $item = $case->item_id
            ? DB::table('item_instances')->where('item_id', $case->item_id)->first()
            : null;
        $skuId = $case->product_id ?? $item?->sku_id;
        $sku = $skuId ? DB::table('sku')->where('sku_id', $skuId)->first() : null;
        $defect = $case->defect_id
            ? DB::table('defect_catalogs')->where('defect_id', $case->defect_id)->first()
            : null;

        // GĐ1-REQ-009: chi tiết case phải trả đủ multi-defect, không chỉ defect_id đơn legacy —
        // khớp cách enrich của CanonicalMvpController::listWarrantyCases (WAR-01).
        $defects = Schema::hasTable('warranty_case_defects')
            ? DB::table('warranty_case_defects as wcd')
                ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
                ->where('wcd.warranty_case_id', $id)
                ->orderByDesc('wcd.is_primary')
                ->orderBy('wcd.created_at')
                ->get(['wcd.defect_id', 'wcd.is_primary', 'dc.defect_code', 'dc.defect_name'])
                ->map(fn (object $row): array => [
                    'defect_id' => (string) $row->defect_id,
                    'defect_code' => (string) $row->defect_code,
                    'defect_name' => (string) $row->defect_name,
                    'is_primary' => (bool) $row->is_primary,
                ])
            : collect();
        if ($defects->isEmpty() && $defect !== null) {
            $defects = collect([[
                'defect_id' => (string) $case->defect_id,
                'defect_code' => $defect->defect_code,
                'defect_name' => $defect->defect_name,
                'is_primary' => true,
            ]]);
        }
        $defectIds = $defects->pluck('defect_id')->values()->all();

        $customerName = (string) ($case->customer_name ?? '');
        $customerPhone = (string) ($case->customer_phone ?? '');
        $customerAddress = (string) ($case->customer_address ?? '');
        if (! $request->user()?->hasPermission('warranty.pii.view')) {
            $customerName = WarrantyPiiMasker::name($customerName);
            $customerPhone = WarrantyPiiMasker::phone($customerPhone);
            $customerAddress = WarrantyPiiMasker::address($customerAddress);
        }

        return [
            'id' => (string) $case->warranty_case_id,
            'warranty_case_id' => (string) $case->warranty_case_id,
            'case_code' => (string) $case->warranty_case_code,
            'warranty_case_code' => (string) $case->warranty_case_code,
            'item_id' => $case->item_id,
            'item_code' => $item?->serial_number,
            'serial_number' => $item?->serial_number,
            'item_status' => $item?->item_status,
            'product_id' => $skuId,
            'sku_code' => $sku?->sku_code,
            'sku_name' => $sku?->sku_name,
            'temporary_item_code' => $case->temporary_item_code,
            'identification_status' => $case->identification_status,
            'sales_trace_status' => $case->sales_trace_status,
            'missing_code_reason' => $case->missing_code_reason,
            'manual_product_description' => $case->manual_product_description,
            'defect_id' => $case->defect_id,
            'defect_code' => $defect?->defect_code,
            'defect_name' => $defect?->defect_name,
            'defects' => $defects->all(),
            'defect_ids' => $defectIds,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_address' => $customerAddress,
            'purchase_date' => $case->purchase_date,
            'estimated_completion_date' => $case->estimated_completion_date ?? null,
            'status' => (string) $case->status,
            'condition_status' => $case->condition_status,
            'description' => $case->description,
            'reported_defect' => $case->reported_defect ?? $case->description,
            'confirmed_defect' => $case->confirmed_defect ?? null,
            'accessories_received' => $case->accessories_received ?? null,
            'received_condition' => $case->received_condition ?? $case->condition_status,
            'received_at' => $case->received_at ?? $case->created_at,
            'completed_at' => $case->completed_at ?? null,
            'returned_at' => $case->returned_at ?? null,
            'cancel_reason' => $case->cancel_reason ?? null,
            'version' => (int) $case->version,
            'legal_hold_flag' => (bool) $case->legal_hold_flag,
            'retention_until' => $case->retention_until ?? null,
            'pii_masked' => ! (bool) $request->user()?->hasPermission('warranty.pii.view'),
            'created_by' => $case->created_by,
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
            'closed_at' => $case->closed_at,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header) || (int) $header < 1) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }

        return (int) $header;
    }

}
