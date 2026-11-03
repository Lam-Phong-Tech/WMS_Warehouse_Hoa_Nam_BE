<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreItemInstanceRequest;
use Modules\Warehouse\Http\Requests\UpdateItemInstanceRequest;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Repositories\Contracts\ItemInstanceRepositoryInterface;
use Modules\Warehouse\Support\P18ComponentLocationRead;
use OpenApi\Attributes as OA;

/**
 * Quản lý từng máy/từng linh kiện có serial (ERD §5.2 item_instance). Chỉ item_status=IN_STOCK
 * và condition đủ điều kiện mới tính available.
 */
class ItemInstanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ItemInstanceRepositoryInterface $itemInstances) {}

    #[OA\Get(
        path: '/api/v1/item-instances',
        operationId: 'item_instance_list',
        summary: 'Danh sách item instance (phân trang, filter theo sku_id/item_status/item_condition/container_id)',
        security: [['bearerAuth' => []]],
        tags: ['24. Item Instance'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item_status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'item_condition', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'container_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách item instance'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền item_instance.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->itemInstances->paginate($request->all(), (int) $request->input('per_page', 10))
        );
    }

    #[OA\Post(
        path: '/api/v1/item-instances',
        operationId: 'item_instance_create',
        summary: 'Tạo item instance mới (máy/linh kiện có serial)',
        security: [['bearerAuth' => []]],
        tags: ['24. Item Instance'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku_id'],
                properties: [
                    new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', example: '4b6f1c2a-8e3d-4f5a-9b2c-1d3e4f5a6b7c'),
                    new OA\Property(property: 'physical_code_id', type: 'string', format: 'uuid', nullable: true, description: 'Bắt buộc nếu SKU là PRODUCT + is_serial_tracked', example: '2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f'),
                    new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, nullable: true, example: 'SN00012345'),
                    new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true, example: '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e'),
                    new OA\Property(property: 'item_status', type: 'string', nullable: true, example: 'NEW'),
                    new OA\Property(property: 'item_condition', type: 'string', nullable: true, example: 'NORMAL'),
                    new OA\Property(property: 'import_batch_id', type: 'string', format: 'uuid', nullable: true, example: '9c6b1a2e-3f4d-4b7a-8c1e-2d3f4a5b6c7d'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo item instance thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền item_instance.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: thiếu physical_code_id bắt buộc)'),
        ]
    )]
    public function store(StoreItemInstanceRequest $request)
    {
        $data = $request->validated();
        $data['item_status'] = $data['item_status'] ?? 'NEW';
        $data['item_condition'] = $data['item_condition'] ?? 'NORMAL';
        $data['version'] = 1;

        $item = DB::transaction(fn () => $this->itemInstances->create($data));

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'item_instance',
            objectId: $item->item_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $item->toArray(),
        );

        return $this->respondCreated($item, 'Tạo item instance thành công');
    }

    #[OA\Get(
        path: '/api/v1/item-instances/{id}',
        operationId: 'item_instance_detail',
        summary: 'Chi tiết item instance',
        security: [['bearerAuth' => []]],
        tags: ['24. Item Instance'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Component-Location-Contract', in: 'header', description: 'Opt-in p18-component-location-read-v1: exact ITEM with sku.view/item_instance.view; location requires putaway.view/manage, consistent active ITEM membership and confirmed BOX putaway. No SKU/serial inference or writes. Requires explicit-read-fields-v1 and warehouse_id; old detail unchanged without this header.', schema: new OA\Schema(type: 'string', enum: ['p18-component-location-read-v1'])),
            new OA\Parameter(name: 'X-Read-Fields-Contract', in: 'header', schema: new OA\Schema(type: 'string', enum: ['explicit-read-fields-v1'])),
            new OA\Parameter(name: 'warehouse_id', in: 'query', description: 'Required assertion in P18 location mode; must equal confirmed authorized warehouse.', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết item instance'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy item instance'),
        ]
    )]
    public function show(string $id)
    {
        if (request()->hasHeader(P18ComponentLocationRead::HEADER)) {
            return app(P18ComponentLocationRead::class)->read(request(), 'ITEM', $id);
        }
        $item = $this->itemInstances->find($id);

        return $item ? $this->respondSuccess($item) : $this->respondNotFound('Không tìm thấy item instance.');
    }

    #[OA\Patch(
        path: '/api/v1/item-instances/{id}',
        operationId: 'item_instance_update',
        summary: 'Sửa item instance (optimistic locking qua version)',
        security: [['bearerAuth' => []]],
        tags: ['24. Item Instance'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', example: '4b6f1c2a-8e3d-4f5a-9b2c-1d3e4f5a6b7c'),
                new OA\Property(property: 'physical_code_id', type: 'string', format: 'uuid', nullable: true, example: '2c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f'),
                new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, nullable: true, example: 'SN00012345'),
                new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true, example: '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e'),
                new OA\Property(property: 'item_status', type: 'string', example: 'IN_STOCK'),
                new OA\Property(property: 'item_condition', type: 'string', example: 'NORMAL'),
                new OA\Property(property: 'import_batch_id', type: 'string', format: 'uuid', nullable: true, example: '9c6b1a2e-3f4d-4b7a-8c1e-2d3f4a5b6c7d'),
                new OA\Property(property: 'version', type: 'integer', nullable: true, description: 'Optimistic locking', example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền item_instance.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy item instance'),
            new OA\Response(response: 409, description: 'version không khớp — dữ liệu đã bị thay đổi bởi thao tác khác'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateItemInstanceRequest $request, string $id)
    {
        $before = $this->itemInstances->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy item instance.');
        }

        $beforeData = $before->toArray();
        $data = $request->validated();

        if (array_key_exists('version', $data)) {
            $expectedVersion = $data['version'];
            unset($data['version']);

            if ((int) $before->version !== (int) $expectedVersion) {
                return $this->respondConflict(
                    'Item instance đã bị thay đổi bởi thao tác khác, vui lòng tải lại dữ liệu.',
                    [],
                    'STATE_CONFLICT'
                );
            }
        }

        $data['version'] = $before->version + 1;

        $item = DB::transaction(fn () => $this->itemInstances->update($id, $data));

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'item_instance',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $item->toArray(),
        );

        return $this->respondSuccess($item, 'Cập nhật item instance thành công');
    }

    #[OA\Get(
        path: '/api/v1/item-instances/{id}/trace',
        operationId: 'item_instance_trace',
        summary: 'Truy vết item qua stock document line tới stock movements',
        description: 'Stock movements chỉ được chọn qua stock_document_lines.item_instance_id; sku và import batch chỉ là ngữ cảnh mô tả.',
        security: [['bearerAuth' => []]],
        tags: ['24. Item Instance'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chuỗi truy vết item, kèm stock movements liên kết qua stock document line của chính item'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy item instance'),
        ]
    )]
    public function trace(string $id)
    {
        $item = $this->itemInstances->find($id);

        if (! $item) {
            return $this->respondNotFound('Không tìm thấy item instance.');
        }

        $item->load(['sku', 'physicalCode', 'container.parent', 'importBatch']);

        // SKU and import batch are descriptive context only. Movement traceability is
        // anchored to the item recorded on the stock document line.
        $movementsQuery = StockMovement::query()
            ->with('document')
            ->whereHas('documentLine', fn ($query) => $query->where('item_instance_id', $item->item_id));

        $rawMovements = $movementsQuery->orderByDesc('occurred_at')
            ->limit(50)
            ->get();

        $formattedMovements = $rawMovements->map(function ($mov) {
            return [
                'movement_id' => $mov->id,
                'type' => $mov->movement_type,
                'document' => $mov->document ? [
                    'doc_id' => $mov->document->id,
                    'doc_no' => $mov->document->doc_no,
                    'type' => current(explode('_', $mov->movement_type)),
                ] : null,
                'timestamp' => $mov->occurred_at,
            ];
        });

        return $this->respondSuccess([
            // Thông tin truy vết cấp Item (đáp ứng tiêu chí BE2-P1-01)
            'item_instance_id' => $item->item_id,
            'serial_number' => $item->serial_number,
            'physical_code' => $item->physicalCode,
            'movements' => $formattedMovements,

            // Giữ lại các field cũ để không break test case cũ
            'item' => $item,
            'sku' => $item->sku,
            'container' => $item->container,
            'parent_container' => $item->container?->parent,
            'import_batch' => $item->importBatch,
            'stock_movements' => $rawMovements,
        ]);
    }
}
