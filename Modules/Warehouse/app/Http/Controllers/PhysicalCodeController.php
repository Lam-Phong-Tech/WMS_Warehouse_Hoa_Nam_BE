<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StorePhysicalCodeRequest;
use Modules\Warehouse\Http\Requests\UpdatePhysicalCodeRequest;
use Modules\Warehouse\Repositories\Contracts\PhysicalCodeRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Nguồn định danh vật lý duy nhất cho item/container/mã tạm (ERD §5.2 physical_code).
 */
class PhysicalCodeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PhysicalCodeRepositoryInterface $physicalCodes) {}

    #[OA\Get(
        path: '/api/v1/physical-codes',
        operationId: 'physical_code_list',
        summary: 'Danh sách mã vật lý (phân trang, filter theo keyword/code_type/carrier_type/status)',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách mã vật lý'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền physical_code.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->physicalCodes->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/physical-codes',
        operationId: 'physical_code_create',
        summary: 'Đăng ký mã vật lý mới',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code_value', 'code_type', 'carrier_type'],
                properties: [
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 100),
                    new OA\Property(property: 'code_type', type: 'string', enum: ['ITEM', 'CARTON', 'COMPONENT_SERIAL', 'BOX', 'TRAY', 'TEMP_WARRANTY', 'UNKNOWN']),
                    new OA\Property(property: 'carrier_type', type: 'string', enum: ['QR', 'BARCODE', 'RFID', 'NFC', 'PLAIN_LABEL', 'MANUAL_CODE', 'TEMP_CODE']),
                    new OA\Property(property: 'hardware_uid', type: 'string', maxLength: 150, nullable: true),
                    new OA\Property(property: 'encoded_payload_version', type: 'string', maxLength: 30, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo mã vật lý thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền physical_code.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: code_type=ITEM nhưng carrier_type không phải chip)'),
        ]
    )]
    public function store(StorePhysicalCodeRequest $request)
    {
        $physicalCode = $this->physicalCodes->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'physical_code',
            objectId: $physicalCode->physical_code_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $physicalCode->toArray(),
        );

        return $this->respondCreated($physicalCode, 'Tạo mã vật lý thành công');
    }

    #[OA\Get(
        path: '/api/v1/physical-codes/{id}',
        operationId: 'physical_code_detail',
        summary: 'Chi tiết mã vật lý',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết mã vật lý'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy mã vật lý'),
        ]
    )]
    public function show(string $id)
    {
        $physicalCode = $this->physicalCodes->find($id);

        return $physicalCode ? $this->respondSuccess($physicalCode) : $this->respondNotFound('Không tìm thấy mã vật lý.');
    }

    #[OA\Patch(
        path: '/api/v1/physical-codes/{id}',
        operationId: 'physical_code_update',
        summary: 'Sửa trạng thái mã vật lý (LOST/REPLACED/INACTIVE)',
        security: [['bearerAuth' => []]],
        tags: ['21. Physical Code'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'LOST', 'REPLACED', 'INACTIVE']),
                new OA\Property(property: 'replaced_by_code_id', type: 'string', format: 'uuid', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền physical_code.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy mã vật lý'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdatePhysicalCodeRequest $request, string $id)
    {
        $before = $this->physicalCodes->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy mã vật lý.');
        }

        $beforeData = $before->toArray();
        $physicalCode = $this->physicalCodes->update($id, $request->validated());

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'physical_code',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $physicalCode->toArray(),
        );

        return $this->respondSuccess($physicalCode, 'Cập nhật mã vật lý thành công');
    }
}
