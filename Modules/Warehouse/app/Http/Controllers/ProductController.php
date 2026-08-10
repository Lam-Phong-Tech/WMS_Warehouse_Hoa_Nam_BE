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
    #[OA\Get(
        path: '/api/v1/skus',
        operationId: 'sku_list',
        summary: 'List SKU records (alias of products list)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated SKU records'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.view permission'),
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
        summary: 'Tạo SKU mới',
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
    #[OA\Post(
        path: '/api/v1/skus',
        operationId: 'sku_create',
        summary: 'Create a SKU record (alias of product create)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 100, example: 'SKU-0001'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Cordless drill'),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 30, nullable: true, example: 'pcs'),
                    new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT'], nullable: true),
                    new OA\Property(property: 'is_serial_tracked', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'SKU created successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreProductRequest $request)
    {
        $product = $this->products->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'sku',
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
    #[OA\Get(
        path: '/api/v1/skus/{id}',
        operationId: 'sku_detail',
        summary: 'SKU detail (alias of product detail)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU detail'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'SKU not found'),
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
<<<<<<< HEAD
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
            objectType: 'sku',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $product->toArray(),
        );

        return $this->respondSuccess($product, 'Cập nhật SKU thành công');
    }

    #[OA\Patch(
        path: '/api/v1/products/{id}/enable',
        operationId: 'product_enable',
        summary: 'Enable a product',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product enabled successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function enable(string $id)
    {
        return $this->setStatus($id, 'ACTIVE', 'Bật sản phẩm thành công');
    }

    #[OA\Patch(
        path: '/api/v1/products/{id}/disable',
        operationId: 'product_disable',
        summary: 'Disable a product',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product disabled successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function disable(string $id)
    {
        return $this->setStatus($id, 'INACTIVE', 'Tắt sản phẩm thành công');
    }

    private function setStatus(string $id, string $status, string $message)
    {
        $product = $this->products->find($id);

        if (! $product) {
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        $product->status = $status;
        $product->save();

        AuditLogger::record(
            action: $status === 'ACTIVE' ? 'ENABLE' : 'DISABLE',
            objectType: 'sku',
            objectId: $product->sku_id,
            actorId: request()->user()?->user_id,
            requestId: request()->attributes->get('request_id'),
            afterData: $product->toArray()
        );

        return $this->respondSuccess($product, $message);
    }
}
