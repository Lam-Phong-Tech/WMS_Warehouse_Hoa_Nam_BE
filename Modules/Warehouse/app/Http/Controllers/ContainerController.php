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
            $this->containers->paginate($request->all(), (int) $request->input('per_page', 10))
        );
    }

    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): #[OA\Post] cho
    // POST /api/v1/containers ĐÃ GỠ khỏi đây — `php artisan route:list` xác nhận
    // path này thật sự do CurrentBaselineContainerFlowController::store phục vụ,
    // không phải method dưới đây (không route nào trỏ tới store() của class này).
    // Annotation cũ còn khai physical_code_id/status là required, sai với contract
    // thật (đều nullable) và khiến `l5-swagger:generate` crash "Unable to merge"
    // vì hai class cùng khai một path+method. Giữ method PHP nguyên vẹn (không rõ
    // superseded hoàn toàn hay còn được gọi nội bộ), chỉ gỡ annotation sai route.
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

    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): #[OA\Patch] cho
    // PATCH /api/v1/containers/{id} ĐÃ GỠ — route thật thuộc
    // CurrentBaselineContainerFlowController::update (bắt buộc header If-Match,
    // khác cơ chế version-in-body của method dưới đây). Cùng lý do với store().
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

    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): #[OA\Post] cho
    // POST /api/v1/containers/{id}/items ĐÃ GỠ — route thật thuộc
    // CurrentBaselineContainerFlowController::addItem. Cùng lý do với store().
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

    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): #[OA\Delete] cho
    // DELETE /api/v1/containers/{id}/items/{containerItemId} ĐÃ GỠ — route thật
    // thuộc CurrentBaselineContainerFlowController::removeItem (bắt buộc If-Match
    // + reason). Cùng lý do với store().
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
