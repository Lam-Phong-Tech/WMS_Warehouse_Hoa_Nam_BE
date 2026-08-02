<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreProductMasterRequest;
use Modules\Warehouse\Http\Requests\UpdateProductMasterRequest;
use Modules\Warehouse\Repositories\Contracts\ProductMasterRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Danh mục chuẩn hóa BRAND/CATEGORY/MODEL/PACKAGE/POWER dùng làm brand_id/category_id/model_id
 * cho SKU (ERD §5.1 product_master).
 */
class ProductMasterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductMasterRepositoryInterface $productMasters) {}

    #[OA\Get(
        path: '/api/v1/product-masters',
        operationId: 'product_master_list',
        summary: 'Danh sách product master (phân trang, filter theo keyword/master_type/status)',
        security: [['bearerAuth' => []]],
        tags: ['20. Product Master'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'master_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER'])),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách product master'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product_master.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->productMasters->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/product-masters',
        operationId: 'product_master_create',
        summary: 'Tạo product master mới',
        security: [['bearerAuth' => []]],
        tags: ['20. Product Master'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['master_type', 'code', 'name'],
                properties: [
                    new OA\Property(property: 'master_type', type: 'string', enum: ['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER']),
                    new OA\Property(property: 'code', type: 'string', maxLength: 80),
                    new OA\Property(property: 'name', type: 'string', maxLength: 200),
                    new OA\Property(property: 'parent_master_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo product master thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product_master.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: code trùng trong cùng master_type)'),
        ]
    )]
    public function store(StoreProductMasterRequest $request)
    {
        $productMaster = $this->productMasters->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'product_master',
            objectId: $productMaster->master_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $productMaster->toArray(),
        );

        return $this->respondCreated($productMaster, 'Tạo product master thành công');
    }

    #[OA\Get(
        path: '/api/v1/product-masters/{id}',
        operationId: 'product_master_detail',
        summary: 'Chi tiết product master',
        security: [['bearerAuth' => []]],
        tags: ['20. Product Master'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết product master'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy product master'),
        ]
    )]
    public function show(string $id)
    {
        $productMaster = $this->productMasters->find($id);

        return $productMaster ? $this->respondSuccess($productMaster) : $this->respondNotFound('Không tìm thấy product master.');
    }

    #[OA\Patch(
        path: '/api/v1/product-masters/{id}',
        operationId: 'product_master_update',
        summary: 'Sửa product master',
        security: [['bearerAuth' => []]],
        tags: ['20. Product Master'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'master_type', type: 'string', enum: ['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER']),
                new OA\Property(property: 'code', type: 'string', maxLength: 80),
                new OA\Property(property: 'name', type: 'string', maxLength: 200),
                new OA\Property(property: 'parent_master_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product_master.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy product master'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateProductMasterRequest $request, string $id)
    {
        $before = $this->productMasters->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy product master.');
        }

        $beforeData = $before->toArray();
        $productMaster = $this->productMasters->update($id, $request->validated());

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'product_master',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $productMaster->toArray(),
        );

        return $this->respondSuccess($productMaster, 'Cập nhật product master thành công');
    }
}
