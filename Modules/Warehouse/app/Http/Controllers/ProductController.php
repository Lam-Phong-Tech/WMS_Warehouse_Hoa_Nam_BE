<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreProductRequest;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductRepositoryInterface $products) {}

    #[OA\Get(
        path: '/api/v1/products',
        operationId: 'product_list',
        summary: 'Danh sách sản phẩm (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách sản phẩm'),
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
        summary: 'Tạo sản phẩm mới',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 100, example: 'SP-0001'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Máy khoan cầm tay'),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 30, nullable: true, example: 'cái'),
                    new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT'], nullable: true),
                    new OA\Property(property: 'is_serial_tracked', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo sản phẩm thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền product.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: code đã tồn tại)'),
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
            'CREATE',
            'product',
            $product->getKey(),
            auth('api')->id(),
            $request->attributes->get('request_id'),
            afterData: $product->only([
                'id', 'code', 'sku_code', 'name', 'unit', 'sku_type',
                'brand_code', 'category_code', 'model_code', 'published_to_app',
                'is_serial_tracked', 'status',
            ])
        );

        return $this->respondCreated($product, 'Tạo sản phẩm thành công');
    }

    #[OA\Get(
        path: '/api/v1/products/{id}',
        operationId: 'product_detail',
        summary: 'Chi tiết sản phẩm',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết sản phẩm'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy sản phẩm'),
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
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        return $this->respondSuccess($product);
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
        $product->version = ((int) $product->version) + 1;
        $product->save();

        AuditLogger::record(
            $status === 'ACTIVE' ? 'ENABLE' : 'DISABLE',
            'product',
            $product->getKey(),
            auth('api')->id(),
            request()->attributes->get('request_id'),
            afterData: $product->only(['id', 'code', 'sku_code', 'name', 'status', 'version'])
        );

        return $this->respondSuccess($product, $message);
    }
}
