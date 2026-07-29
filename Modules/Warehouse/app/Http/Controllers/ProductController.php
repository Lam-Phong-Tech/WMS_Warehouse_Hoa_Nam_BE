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
    public function show(string $id)
    {
        $product = $this->products->find($id);

        if (! $product) {
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        return $this->respondSuccess($product);
    }
}
