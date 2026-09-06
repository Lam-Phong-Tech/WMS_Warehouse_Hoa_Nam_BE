<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreProductRequest;
use Modules\Warehouse\Http\Requests\UpdateProductRequest;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Warehouse\Support\PvRelations;
use Modules\Warehouse\Support\SkuMasterLinkResolver;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    public function __construct(private readonly ProductRepositoryInterface $products) {}

    /**
     * SKU là master data: sku_code/sku_name/status/hồ sơ App PV của nó nằm trong
     * payload của các read cache khoá theo 'stock:version' (danh sách sản phẩm Mini
     * App, phiếu nhập/xuất, truy vết, báo cáo). ProductRepository chỉ bump
     * 'warehouse.sku:list' nên không ai vô hiệu hoá những cache đó hộ.
     */
    private function invalidateMasterReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

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
            $this->products->paginate($request->all(), (int) $request->input('per_page', 10))
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
                    new OA\Property(property: 'sku_type', type: 'string', enum: ['PRODUCT', 'COMPONENT'], nullable: true, example: 'PRODUCT'),
                    new OA\Property(property: 'is_serial_tracked', type: 'boolean', nullable: true, example: true),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true, example: 'b6f2a1d4-3c9e-4a2b-8f1d-7e6c5b4a3210'),
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true, example: 'c7a3b2e5-4d0f-4b3c-9a2e-8f7d6c5b4321'),
                    new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true, example: 'd8b4c3f6-5e10-4c4d-ab3f-9a8e7d6c5432'),
                    new OA\Property(property: 'power_source_id', type: 'string', format: 'uuid', nullable: true, description: 'FK power_sources.id (ERD bảng 16)', example: 'e9c5d4a7-6f21-4d5e-bc40-ab9f8e7d6543'),
                    new OA\Property(property: 'power_source_code', type: 'string', maxLength: 80, nullable: true, description: 'Tiện ích: gán nguồn điện theo mã master ACTIVE', example: 'AC-220V'),
                    new OA\Property(property: 'package_variant_id', type: 'string', format: 'uuid', nullable: true, description: 'FK package_variants.id (ERD bảng 16)', example: 'fa06e5b8-7032-4e6f-cd51-bc0a9f8e7654'),
                    new OA\Property(property: 'package_variant_code', type: 'string', maxLength: 80, nullable: true, description: 'Tiện ích: gán quy cách đóng gói theo mã master ACTIVE', example: 'HOP-01'),
                    new OA\Property(property: 'package_variant', type: 'string', maxLength: 50, nullable: true, description: 'Text legacy; khớp master thì tự gắn package_variant_id', example: 'Hộp carton'),
                    new OA\Property(property: 'warranty_applicable', type: 'boolean', nullable: true, example: true),
                    new OA\Property(property: 'specification', type: 'object', nullable: true, example: ['voltage' => '220V', 'color' => 'Đỏ']),
                    new OA\Property(property: 'published_to_app', type: 'boolean', nullable: true, example: false),
                    new OA\Property(property: 'min_stock', type: 'number', format: 'double', minimum: 0, nullable: true, description: 'Định mức tồn tối thiểu theo SKU, toàn hệ thống (không theo kho). Phải >= 0 — 0 là một định mức hợp lệ; null = chưa đặt, không tính cảnh báo.', example: 10),
                    new OA\Property(property: 'max_stock', type: 'number', format: 'double', minimum: 1, nullable: true, description: 'Định mức tồn tối đa theo SKU, toàn hệ thống (không theo kho). Phải >= 1 VÀ lớn hơn min_stock; null = chưa đặt, không tính cảnh báo.', example: 500),
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
        $validated = $this->withResolvedMasterLinks($request->validated());
        $product = $this->products->create($validated + ['status' => 'ACTIVE', 'version' => 1]);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'sku',
            objectId: $product->sku_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $product->toArray(),
        );

        $this->invalidateMasterReadCache();

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
                new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, example: 'SP-0001'),
                new OA\Property(property: 'sku_name', type: 'string', maxLength: 255, example: 'Máy khoan cầm tay'),
                new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true, example: 'b6f2a1d4-3c9e-4a2b-8f1d-7e6c5b4a3210'),
                new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true, example: 'c7a3b2e5-4d0f-4b3c-9a2e-8f7d6c5b4321'),
                new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true, example: 'd8b4c3f6-5e10-4c4d-ab3f-9a8e7d6c5432'),
                new OA\Property(property: 'power_source_id', type: 'string', format: 'uuid', nullable: true, description: 'FK power_sources.id (ERD bảng 16)', example: 'e9c5d4a7-6f21-4d5e-bc40-ab9f8e7d6543'),
                new OA\Property(property: 'power_source_code', type: 'string', maxLength: 80, nullable: true, example: 'AC-220V'),
                new OA\Property(property: 'package_variant_id', type: 'string', format: 'uuid', nullable: true, description: 'FK package_variants.id (ERD bảng 16)', example: 'fa06e5b8-7032-4e6f-cd51-bc0a9f8e7654'),
                new OA\Property(property: 'package_variant_code', type: 'string', maxLength: 80, nullable: true, example: 'HOP-01'),
                new OA\Property(property: 'package_variant', type: 'string', maxLength: 50, nullable: true, example: 'Hộp carton'),
                new OA\Property(property: 'warranty_applicable', type: 'boolean', nullable: true, example: true),
                new OA\Property(property: 'specification', type: 'object', nullable: true, example: ['voltage' => '220V', 'color' => 'Đỏ']),
                new OA\Property(property: 'published_to_app', type: 'boolean', nullable: true, example: false),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                new OA\Property(property: 'min_stock', type: 'number', format: 'double', minimum: 0, nullable: true, description: 'Định mức tồn tối thiểu theo SKU, toàn hệ thống (không theo kho). Phải >= 0 — 0 là một định mức hợp lệ; null = chưa đặt, không tính cảnh báo.', example: 10),
                new OA\Property(property: 'max_stock', type: 'number', format: 'double', minimum: 1, nullable: true, description: 'Định mức tồn tối đa theo SKU, toàn hệ thống (không theo kho). Phải >= 1 VÀ lớn hơn min_stock; null = chưa đặt, không tính cảnh báo.', example: 500),
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

        $validated = $this->withResolvedMasterLinks($request->validated());
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

        $this->invalidateMasterReadCache();

        return $this->respondSuccess($product, 'Cập nhật SKU thành công');
    }

    /**
     * Annex C publishPvProduct: PATCH /pv/products/{sku_id}/publish, permission
     * riêng `pv.publish` (§3 tách khỏi sku.manage) + If-Match. REQ-SRS-020:
     * SKU không ACTIVE thì không được publish; publish không phụ thuộc tồn kho.
     *
     * WWHN-252 · FE252-P1-08 — precondition là BẮT BUỘC: Annex C khai cột header
     * `If-Match` cho operation này và có 428 trong error set. Token precondition
     * là **version hồ sơ App PV**, không phải `sku.version`: bảng Field mapping
     * (§13) khai công tắc `published` của SCR-PV-ADM-01 đọc/ghi
     * `PV_Profile.published`, và đó cũng là con số duy nhất FE nhìn thấy trên
     * dòng đang thao tác (`GET /pv-admin/resources/product-profiles` → `version`).
     *
     * Quy ước version (xem docs/decision-records/DEC-API-IFMATCH-002-pv-publish-precondition.md):
     * - `If-Match: 0`  = SKU chưa có hồ sơ App PV (lần publish đầu tiên).
     * - `If-Match: N`  = version hồ sơ hiện tại (N >= 1).
     * Mỗi lần publish/unpublish thành công tăng version hồ sơ lên 1 và trả về
     * `pv_profile_version` để client refresh mà không phải đọc lại danh sách.
     */
    #[OA\Patch(
        path: '/api/v1/pv/products/{id}/publish',
        operationId: 'publishPvProduct',
        summary: 'Bật/tắt publish SKU lên App PV (quyền pv.publish, If-Match theo version hồ sơ App PV)',
        description: 'DEC-API-IFMATCH-002: If-Match là version hồ sơ App PV (0 nếu SKU chưa có hồ sơ; N >= 1 là version hiện tại). '
            .'Mỗi lần publish/unpublish thành công tăng version hồ sơ lên 1 và trả về pv_profile_version. '
            .'REQ-SRS-020: SKU không ACTIVE thì không được publish; publish không phụ thuộc tồn kho.',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['published'],
                properties: [
                    new OA\Property(property: 'published', type: 'boolean', description: 'true = publish lên App PV, false = gỡ khỏi App PV.', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã publish/gỡ SKU; response kèm pv_profile_version mới.'),
        ]
    )]
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

        $currentProfileVersion = $this->pvProfileVersion($id);
        $this->assertPvProfileVersionMatches($request, $currentProfileVersion);

        $beforeData = $before->toArray();
        $product = $this->products->update($id, $payload);
        $nextProfileVersion = $this->bumpPvProfileVersion($id, $currentProfileVersion);

        AuditLogger::record(
            action: $validated['published'] ? 'PV_PUBLISH' : 'PV_UNPUBLISH',
            objectType: 'sku',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $product->toArray(),
        );

        $this->invalidateMasterReadCache();

        return $this->respondSuccess(
            $product->toArray() + ['pv_profile_version' => $nextProfileVersion],
            $validated['published'] ? 'Đã publish SKU lên App PV.' : 'Đã gỡ SKU khỏi App PV.'
        );
    }

    /**
     * Version hồ sơ App PV của một SKU; 0 nghĩa là SKU chưa có hồ sơ nào.
     *
     * Đọc qua {@see PvRelations::profile()} chứ không dùng tên số ít, vì tên cột
     * khoá ngoại khác nhau giữa hai driver (`product_id` trên sqlite, `sku_id`
     * trên PostgreSQL) — xem docblock của PvRelations.
     */
    private function pvProfileVersion(string $skuId): int
    {
        $relation = PvRelations::profile();
        $version = DB::table($relation['table'])->where($relation['sku'], $skuId)->value('version');

        return $version === null ? 0 : (int) $version;
    }

    /**
     * §8.2: thiếu header precondition = 428 PRECONDITION_REQUIRED, lệch version
     * = 412 STALE_VERSION. Khác `assertVersionMatchesIfProvided` (dùng cho
     * updateSku, nơi baseline đang chạy vẫn cho phép bỏ header), publish là hành
     * động đổi trạng thái mặt CÔNG KHAI nên header là bắt buộc — không có header
     * thì hai người bấm công tắc cùng lúc sẽ ghi đè nhau mà không ai biết.
     */
    private function assertPvProfileVersionMatches(Request $request, int $currentVersion): void
    {
        $header = trim((string) $request->header('If-Match'));

        if ($header === '') {
            ApiError::abort(
                428,
                'PRECONDITION_REQUIRED',
                'Publish App PV bắt buộc header If-Match chứa version hồ sơ App PV hiện tại (0 nếu SKU chưa có hồ sơ).',
                ['current_version' => $currentVersion]
            );
        }

        $header = trim(preg_replace('/^W\//', '', $header) ?? $header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        if ((int) $header !== $currentVersion) {
            ApiError::abort(412, 'STALE_VERSION', 'Hồ sơ App PV đã được cập nhật bởi phiên khác; tải lại trước khi bật/tắt publish.', [
                'expected_version' => (int) $header,
                'current_version' => $currentVersion,
            ]);
        }
    }

    /**
     * Ghi version mới sau khi trạng thái publish đã đổi. Hồ sơ do trigger tạo
     * (`hn_legacy_sku_write` trên PostgreSQL, `hn_pv_profile_sync_on_sku_*` trên
     * sqlite) ngay trong lệnh update ở trên, nên tới bước này mọi SKU đang
     * publish đều đã có hồ sơ. Trường hợp duy nhất không có dòng nào là unpublish
     * một SKU chưa từng publish — khi đó version giữ nguyên 0.
     */
    private function bumpPvProfileVersion(string $skuId, int $currentVersion): int
    {
        $relation = PvRelations::profile();
        $next = $currentVersion + 1;

        $affected = DB::table($relation['table'])
            ->where($relation['sku'], $skuId)
            ->update(['version' => $next, 'updated_at' => now()]);

        return $affected > 0 ? $next : $this->pvProfileVersion($skuId);
    }

    /**
     * ERD bảng 16: quy đổi power_source_* / package_variant_* về đúng cặp FK +
     * text legacy trước khi ghi. `*_code` chỉ là tiện ích cho client, không phải
     * cột thật nên phải loại khỏi payload sau khi resolve.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withResolvedMasterLinks(array $validated): array
    {
        $resolved = SkuMasterLinkResolver::resolve($validated);
        unset($validated['power_source_code'], $validated['package_variant_code']);

        return $resolved + $validated;
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

    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): #[OA\Patch] cho
    // PATCH /api/v1/products/{id}/enable ĐÃ GỠ — `php artisan route:list` xác
    // nhận không route nào trỏ tới enable()/disable() của class này; path thật
    // do routes/governance.php khai riêng cho
    // ProductStatusCompatibilityController::enable/disable (permission
    // sku.manage, không phải product.manage như annotation cũ khai sai). Giữ
    // nguyên method PHP, chỉ gỡ annotation khai lại route không thuộc về nó.
    public function enable(string $id)
    {
        return $this->setStatus($id, 'ACTIVE', 'Bật sản phẩm thành công');
    }

    // DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 4): cùng lý do với enable() —
    // #[OA\Patch] cho PATCH /api/v1/products/{id}/disable ĐÃ GỠ.
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

        // save() trực tiếp trên model bỏ qua BaseRepository::update() (nơi tự bump
        // cache) — phải tự invalidate để enable/disable có hiệu lực ngay trên
        // GET tiếp theo (mục 7: "không được để import/enable xong API vẫn trả cũ").
        $this->products->forgetCache($id);

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
