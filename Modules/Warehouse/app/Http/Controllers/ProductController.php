<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreProductRequest;
use Modules\Warehouse\Http\Requests\UpdateProductRequest;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductRepositoryInterface $products) {}

    #[OA\Get(
        path: '/api/v1/products',
        operationId: 'product_list',
        summary: 'Danh sách SKU (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách SKU'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->products->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/products',
        operationId: 'product_create',
        summary: 'Tạo SKU mới qua product compatibility façade',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku_code', 'sku_name'],
                properties: [
                    new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, example: 'SP-0001'),
                    new OA\Property(property: 'sku_name', type: 'string', maxLength: 255, example: 'Máy khoan cầm tay'),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 30, nullable: true, example: 'cái'),
                    new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT'], nullable: true),
                    new OA\Property(property: 'is_serial_tracked', type: 'boolean', nullable: true),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'package_variant', type: 'string', enum: ['BARE', 'KIT', 'SET', 'BODY_ONLY'], nullable: true),
                    new OA\Property(property: 'warranty_applicable', type: 'boolean', nullable: true),
                    new OA\Property(property: 'specification', type: 'object', nullable: true),
                    new OA\Property(property: 'published_to_app', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo SKU thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: sku_code đã tồn tại)'),
        ]
    )]
    public function store(StoreProductRequest $request)
    {
        $product = $this->products->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'product',
            objectId: $product->sku_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $product->toArray(),
        );

        return $this->respondCreated($product, 'Tạo SKU thành công');
    }

    #[OA\Get(
        path: '/api/v1/products/{id}',
        operationId: 'product_detail',
        summary: 'Chi tiết SKU',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết SKU'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
        ]
    )]
    public function show(string $id)
    {
        $product = $this->products->find($id);

        if (! $product) {
            return $this->respondNotFound('Không tìm thấy SKU.');
        }

        return $this->respondSuccess($product);
    }

    #[OA\Patch(
        path: '/api/v1/products/{id}',
        operationId: 'product_update',
        summary: 'Sửa SKU (brand/category/model/package/specification...)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'sku_code', type: 'string', maxLength: 100),
                new OA\Property(property: 'sku_name', type: 'string', maxLength: 255),
                new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'package_variant', type: 'string', enum: ['BARE', 'KIT', 'SET', 'BODY_ONLY'], nullable: true),
                new OA\Property(property: 'warranty_applicable', type: 'boolean', nullable: true),
                new OA\Property(property: 'specification', type: 'object', nullable: true),
                new OA\Property(property: 'published_to_app', type: 'boolean', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateProductRequest $request, string $id)
    {
        $before = $this->products->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy SKU.');
        }

        $beforeData = $before->toArray();
        $product = $this->products->update($id, $request->validated());

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'product',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $product->toArray(),
        );

        return $this->respondSuccess($product, 'Cập nhật SKU thành công');
    }
}
