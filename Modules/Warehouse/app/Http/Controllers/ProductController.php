<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            new OA\Response(response: 409, description: 'sku_code/is_serial_tracked đã bị khoá vì SKU có stock/movement (BR-SKU-10)'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateProductRequest $request, string $id)
    {
        $before = $this->products->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy SKU.');
        }

        $validated = $request->validated();
        $this->assertImmutableAfterStock($id, $validated, $before);
        $validated = $this->assertPublishRequiresActive($validated, $before);
        $nextVersion = $this->assertVersionMatchesIfProvided($request, $before);

        $beforeData = $before->toArray();
        if ($nextVersion !== null) {
            $validated['version'] = $nextVersion;
        }
        $product = $this->products->update($id, $validated);

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

    /**
     * Annex C publishPvProduct: PATCH /pv/products/{sku_id}/publish, permission
     * riêng `pv.publish` (§3 tách khỏi sku.manage) + If-Match. REQ-SRS-020:
     * SKU không ACTIVE thì không được publish; publish không phụ thuộc tồn kho.
     */
    public function publishPvProduct(Request $request, string $id)
    {
        $validated = $request->validate([
            'published' => ['required', 'boolean'],
        ]);

        $before = $this->products->find($id);
        if (! $before) {
            return $this->respondNotFound('Không tìm thấy SKU.');
        }

        $payload = $this->assertPublishRequiresActive(['published_to_app' => (bool) $validated['published']], $before);
        $nextVersion = $this->assertVersionMatchesIfProvided($request, $before);
        if ($nextVersion !== null) {
            $payload['version'] = $nextVersion;
        }

        $beforeData = $before->toArray();
        $product = $this->products->update($id, $payload);

        AuditLogger::record(
            action: $validated['published'] ? 'PV_PUBLISH' : 'PV_UNPUBLISH',
            objectType: 'sku',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $product->toArray(),
        );

        return $this->respondSuccess($product, $validated['published'] ? 'Đã publish SKU lên App PV.' : 'Đã gỡ SKU khỏi App PV.');
    }

    /**
     * Annex C updateSku + ERD bảng 16 (skus.version): khi client gửi If-Match,
     * phải so version thật và trả 412 STALE_VERSION nếu lệch, rồi tăng version.
     * Không gửi header thì giữ hành vi hiện hành (chưa bắt buộc theo baseline
     * đang chạy), nên client cũ không bị vỡ.
     */
    private function assertVersionMatchesIfProvided(Request $request, Model $current): ?int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            return null;
        }

        $header = trim(preg_replace('/^W\//', '', $header) ?? $header, '" ');
        if (! ctype_digit($header) || (int) $header < 1) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }

        $currentVersion = (int) ($current->version ?? 0);
        if ((int) $header !== $currentVersion) {
            ApiError::abort(412, 'STALE_VERSION', 'SKU đã được cập nhật bởi phiên khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $header,
                'current_version' => $currentVersion,
            ]);
        }

        return $currentVersion + 1;
    }

    /**
     * BR-SKU-10: sku_code và is_serial_tracked bị khoá ngay khi SKU đã có ít nhất
     * một stock movement (append-only, chỉ ghi khi document POSTED) hoặc tồn > 0
     * trên inventory_balances. Chỉ chặn khi giá trị mới thực sự khác giá trị hiện tại,
     * để các PATCH không đụng tới 2 field này (hoặc gửi lại đúng giá trị cũ) vẫn qua được.
     */
    private function assertImmutableAfterStock(string $skuId, array $payload, Model $current): void
    {
        if (! array_key_exists('sku_code', $payload) && ! array_key_exists('is_serial_tracked', $payload)) {
            return;
        }

        $newCode = array_key_exists('sku_code', $payload) ? (string) $payload['sku_code'] : (string) $current->sku_code;
        $newTracking = array_key_exists('is_serial_tracked', $payload) ? (bool) $payload['is_serial_tracked'] : (bool) $current->is_serial_tracked;

        $unchanged = strtoupper(trim($newCode)) === strtoupper(trim((string) $current->sku_code))
            && $newTracking === (bool) $current->is_serial_tracked;

        if ($unchanged) {
            return;
        }

        $hasMovement = DB::table('stock_movements')->where('product_id', $skuId)->exists();
        $hasStock = DB::table('inventory_balances')
            ->where('product_id', $skuId)
            ->where('on_hand_qty', '>', 0)
            ->exists();

        if ($hasMovement || $hasStock) {
            ApiError::abort(
                409,
                'SKU_IDENTITY_IMMUTABLE_AFTER_STOCK',
                'Không được đổi sku_code/tracking mode sau khi SKU đã có stock hoặc movement.'
            );
        }
    }

    /**
     * REQ-SRS-020 / AC-SRS-020: chỉ SKU active mới publish; inactive không được publish.
     * Nếu request cố publish (published_to_app=true) trong khi trạng thái kết quả không
     * phải ACTIVE, chặn 422. Nếu SKU chuyển sang/đang ở INACTIVE mà không tường minh yêu
     * cầu publish, tự động ép published_to_app=false để không để lại SKU inactive nhưng
     * vẫn publish (cascade unpublish khi deactivate thay vì chặn hẳn thao tác deactivate).
     */
    private function assertPublishRequiresActive(array $payload, Model $current): array
    {
        $resultingStatus = array_key_exists('status', $payload)
            ? strtoupper((string) $payload['status'])
            : strtoupper((string) $current->status);

        if ($resultingStatus === 'ACTIVE') {
            return $payload;
        }

        if (array_key_exists('published_to_app', $payload) && (bool) $payload['published_to_app']) {
            ApiError::abort(
                422,
                'SKU_PUBLISH_REQUIRES_ACTIVE',
                'Chỉ SKU đang ACTIVE mới được publish cho App PV.'
            );
        }

        $payload['published_to_app'] = false;

        return $payload;
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
