<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreProductRequest;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Warehouse\Services\SkuAggregateService;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SkuAggregateService $skuAggregate
    ) {}

    #[OA\Get(
        path: '/api/v1/products',
        operationId: 'product_list',
        summary: 'Danh sách sản phẩm',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        responses: [new OA\Response(response: 200, description: 'Danh sách sản phẩm')]
    )]
    #[OA\Get(
        path: '/api/v1/skus',
        operationId: 'sku_list',
        summary: 'Danh sách SKU',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        responses: [new OA\Response(response: 200, description: 'Danh sách SKU')]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->respondPaginated(
            $this->products->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/skus',
        operationId: 'sku_create',
        summary: 'Tạo SKU aggregate cùng component và physical code',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: false,
                schema: new OA\Schema(type: 'string', maxLength: 128)
            ),
        ],
        responses: [
            new OA\Response(response: 201, description: 'SKU được tạo atomically'),
            new OA\Response(response: 409, description: 'Idempotency/code conflict'),
            new OA\Response(response: 422, description: 'Business rule violation'),
        ]
    )]
    public function store(StoreProductRequest $request): JsonResponse
    {
        if ($this->isSkuRoute($request)) {
            return $this->storeSku($request);
        }

        $product = DB::transaction(function () use ($request): Product {
            $attributes = Arr::except($request->validated(), ['components', 'physical_codes']);
            $attributes['status'] = $attributes['status'] ?? 'ACTIVE';
            $attributes['version'] = 1;

            /** @var Product $product */
            $product = $this->products->create($attributes);
            AuditLogger::record(
                'CREATE',
                'product',
                (string) $product->getKey(),
                $this->actorId(),
                $this->requestId($request),
                afterData: $product->toArray(),
                reason: 'Product create transaction boundary'
            );

            return $product;
        }, 3);

        return $this->respondCreated($product, 'Tạo sản phẩm thành công');
    }

    #[OA\Get(
        path: '/api/v1/skus/{id}',
        operationId: 'sku_detail',
        summary: 'Chi tiết SKU aggregate',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU, components và physical codes'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        if ($this->isSkuRoute($request)) {
            $aggregate = $this->skuAggregate->snapshot($id);
            if ($aggregate === []) {
                return $this->respondNotFound('Không tìm thấy SKU.');
            }

            return $this->respondSuccess($aggregate);
        }

        $product = $this->products->find($id);
        if (! $product) {
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        return $this->respondSuccess($product);
    }

    public function enable(string $id): JsonResponse
    {
        return $this->setStatus($id, 'ACTIVE', 'Bật sản phẩm thành công');
    }

    public function disable(string $id): JsonResponse
    {
        return $this->setStatus($id, 'INACTIVE', 'Tắt sản phẩm thành công');
    }

    private function storeSku(StoreProductRequest $request): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key !== '' && mb_strlen($key) > 128) {
            ApiError::abort(422, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key tối đa 128 ký tự.');
        }

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key === '' ? null : $key,
            'sku_create',
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            function () use ($request): array {
                $data = $this->skuAggregate->create(
                    $request->validated(),
                    $this->actorId(),
                    $this->requestId($request)
                );

                return [201, $this->envelope('Tạo SKU thành công.', $data)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function setStatus(string $id, string $status, string $message): JsonResponse
    {
        $product = DB::transaction(function () use ($id, $status): Product {
            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($id);
            if ($product === null) {
                ApiError::abort(404, 'PRODUCT_NOT_FOUND', 'Không tìm thấy sản phẩm.');
            }

            $before = $product->toArray();
            $product->status = $status;
            $product->version = ((int) $product->version) + 1;
            $product->save();

            AuditLogger::record(
                $status === 'ACTIVE' ? 'ENABLE' : 'DISABLE',
                'product',
                (string) $product->getKey(),
                $this->actorId(),
                $this->requestId(request()),
                beforeData: $before,
                afterData: $product->fresh()->toArray(),
                reason: 'Product status transaction boundary'
            );

            return $product;
        }, 3);

        return $this->respondSuccess($product, $message);
    }

    private function isSkuRoute(Request $request): bool
    {
        return str_starts_with('/'.ltrim($request->path(), '/'), '/api/v1/skus');
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }

    private function requestId(Request $request): ?string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) ? $id : null;
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
