<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreContainerItemRequest;
use Modules\Warehouse\Http\Requests\StoreContainerRequest;
use Modules\Warehouse\Http\Requests\UpdateContainerRequest;
use Modules\Warehouse\Repositories\Contracts\ContainerItemRepositoryInterface;
use Modules\Warehouse\Repositories\Contracts\ContainerRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Thùng CARTON, hộp linh kiện BOX và khay TRAY (ERD §5.2 container). Container/locator không
 * phải bucket tồn độc lập.
 */
class ContainerController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ContainerRepositoryInterface $containers,
        private readonly ContainerItemRepositoryInterface $containerItems,
    ) {}

    #[OA\Get(
        path: '/api/v1/containers',
        operationId: 'container_list',
        summary: 'Danh sách container (phân trang, filter theo keyword/container_type/status/zone_code)',
        security: [['bearerAuth' => []]],
        tags: ['23. Container'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'container_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['CARTON', 'BOX', 'TRAY'])),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách container'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền container.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->containers->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/containers',
        operationId: 'container_create',
        summary: 'Tạo container mới (CARTON/BOX/TRAY)',
        security: [['bearerAuth' => []]],
        tags: ['23. Container'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['container_code', 'container_type', 'physical_code_id', 'status'],
                properties: [
                    new OA\Property(property: 'container_code', type: 'string', maxLength: 100),
                    new OA\Property(property: 'container_type', type: 'string', enum: ['CARTON', 'BOX', 'TRAY']),
                    new OA\Property(property: 'physical_code_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'primary_sku_id', type: 'string', format: 'uuid', nullable: true, description: 'Bắt buộc với CARTON/BOX'),
                    new OA\Property(property: 'parent_container_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'zone_code', type: 'string', maxLength: 50, nullable: true),
                    new OA\Property(property: 'location_hint', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'status', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo container thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền container.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: CARTON/BOX thiếu primary_sku_id)'),
        ]
    )]
    public function store(StoreContainerRequest $request)
    {
        $container = DB::transaction(fn () => $this->containers->create($request->validated() + ['version' => 1]));

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'container',
            objectId: $container->container_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $container->toArray(),
        );

        return $this->respondCreated($container, 'Tạo container thành công');
    }

    #[OA\Get(
        path: '/api/v1/containers/{id}',
        operationId: 'container_detail',
        summary: 'Chi tiết container (kèm danh sách item bên trong)',
        security: [['bearerAuth' => []]],
        tags: ['23. Container'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết container'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy container'),
        ]
    )]
    public function show(string $id)
    {
        $container = $this->containers->find($id);

        if (! $container) {
            return $this->respondNotFound('Không tìm thấy container.');
        }

        return $this->respondSuccess($container->load('items'));
    }

    #[OA\Patch(
        path: '/api/v1/containers/{id}',
        operationId: 'container_update',
        summary: 'Sửa container (optimistic locking qua version)',
        security: [['bearerAuth' => []]],
        tags: ['23. Container'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'container_code', type: 'string', maxLength: 100),
                new OA\Property(property: 'container_type', type: 'string', enum: ['CARTON', 'BOX', 'TRAY']),
                new OA\Property(property: 'physical_code_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'primary_sku_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'parent_container_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'zone_code', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'location_hint', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'version', type: 'integer', nullable: true, description: 'Optimistic locking'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền container.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy container'),
            new OA\Response(response: 409, description: 'version không khớp — dữ liệu đã bị thay đổi bởi thao tác khác'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateContainerRequest $request, string $id)
    {
        $before = $this->containers->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy container.');
        }

        $beforeData = $before->toArray();
        $data = $request->validated();

        if (array_key_exists('version', $data)) {
            $expectedVersion = $data['version'];
            unset($data['version']);

            if ((int) $before->version !== (int) $expectedVersion) {
                return $this->respondConflict(
                    'Container đã bị thay đổi bởi thao tác khác, vui lòng tải lại dữ liệu.',
                    [],
                    'STATE_CONFLICT'
                );
            }
        }

        $data['version'] = $before->version + 1;

        $container = DB::transaction(fn () => $this->containers->update($id, $data));

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'container',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $container->toArray(),
        );

        return $this->respondSuccess($container, 'Cập nhật container thành công');
    }

    #[OA\Post(
        path: '/api/v1/containers/{id}/items',
        operationId: 'container_add_item',
        summary: 'Gán item (serial) hoặc sku+quantity vào container',
        security: [['bearerAuth' => []]],
        tags: ['23. Container'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true, description: 'XOR với sku_id — dùng cho hàng có serial'),
                new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true, description: 'XOR với item_id — dùng cho hàng theo số lượng'),
                new OA\Property(property: 'quantity', type: 'number', nullable: true, description: 'Bắt buộc khi dùng sku_id'),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Gán item vào container thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền container.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy container'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vi phạm XOR item_id/sku_id+quantity)'),
        ]
    )]
    public function addItem(StoreContainerItemRequest $request, string $id)
    {
        $container = $this->containers->find($id);

        if (! $container) {
            return $this->respondNotFound('Không tìm thấy container.');
        }

        $containerItem = DB::transaction(function () use ($request, $id) {
            return $this->containerItems->create($request->validated() + [
                'container_id' => $id,
                'status' => 'ACTIVE',
            ]);
        });

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'container_item',
            objectId: $containerItem->container_item_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $containerItem->toArray(),
        );

        return $this->respondCreated($containerItem, 'Gán item vào container thành công');
    }

    #[OA\Delete(
        path: '/api/v1/containers/{id}/items/{containerItemId}',
        operationId: 'container_remove_item',
        summary: 'Gỡ item khỏi container (đánh dấu REMOVED, không xoá bản ghi)',
        security: [['bearerAuth' => []]],
        tags: ['23. Container'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'containerItemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Gỡ item thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền container.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy mapping item trong container'),
        ]
    )]
    public function removeItem(Request $request, string $id, string $containerItemId)
    {
        $containerItem = $this->containerItems->find($containerItemId);

        if (! $containerItem || $containerItem->container_id !== $id) {
            return $this->respondNotFound('Không tìm thấy mapping item trong container.');
        }

        $beforeData = $containerItem->toArray();
        $updated = $this->containerItems->update($containerItemId, ['status' => 'REMOVED']);

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'container_item',
            objectId: $containerItemId,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $updated->toArray(),
        );

        return $this->respondSuccess($updated, 'Gỡ item khỏi container thành công');
    }
}
