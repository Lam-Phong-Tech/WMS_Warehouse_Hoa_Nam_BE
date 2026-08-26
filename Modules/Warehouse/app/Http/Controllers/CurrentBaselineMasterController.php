<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use OpenApi\Attributes as OA;

/**
 * 5 loại catalog master (brands/product_categories/power_sources/product_models/
 * package_variants) — danh mục gần như tĩnh, đọc rất nhiều (mọi SKU form phải load
 * để hiển thị dropdown), ghi cực hiếm (chỉ qua Data Admin). Best ROI cho caching
 * theo audit (mục 6): version-based invalidation theo từng bảng, không dùng
 * wildcard delete.
 */
class CurrentBaselineMasterController extends Controller
{
    use ApiResponse;
    use ResolvesActorOrNull { actorIdOrNull as actorId; }

    private const CACHE_TTL_POLICY = 'master';

    public function listBrands(Request $request)
    {
        return $this->page($request, 'brands');
    }

    public function listProductCategories(Request $request)
    {
        return $this->page($request, 'product_categories');
    }

    public function listPowerSources(Request $request)
    {
        return $this->page($request, 'power_sources');
    }

    public function listProductModels(Request $request)
    {
        return $this->page($request, 'product_models');
    }

    public function listPackageVariants(Request $request)
    {
        return $this->page($request, 'package_variants');
    }

    #[OA\Post(
        path: '/api/v1/brands',
        operationId: 'brand_create',
        summary: 'Tạo brand mới (catalog master)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['brand_code', 'brand_name'],
                properties: [
                    new OA\Property(property: 'brand_code', type: 'string', maxLength: 80, example: 'BR-0001'),
                    new OA\Property(property: 'brand_name', type: 'string', maxLength: 200, example: 'Samsung'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Thương hiệu điện tử Samsung'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo brand thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: brand_code đã tồn tại)'),
        ]
    )]
    public function storeBrand(Request $request)
    {
        return $this->storeSimpleMaster($request, 'brands', 'brand_code', 'brand_name');
    }

    #[OA\Post(
        path: '/api/v1/product-categories',
        operationId: 'product_category_create',
        summary: 'Tạo product category mới (catalog master)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category_code', 'category_name'],
                properties: [
                    new OA\Property(property: 'category_code', type: 'string', maxLength: 80, example: 'CAT-0001'),
                    new OA\Property(property: 'category_name', type: 'string', maxLength: 200, example: 'Điện thoại di động'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Danh mục điện thoại di động'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo product category thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: category_code đã tồn tại)'),
        ]
    )]
    public function storeProductCategory(Request $request)
    {
        return $this->storeSimpleMaster($request, 'product_categories', 'category_code', 'category_name');
    }

    #[OA\Post(
        path: '/api/v1/power-sources',
        operationId: 'power_source_create',
        summary: 'Tạo power source mới (catalog master)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['power_source_code', 'power_source_name'],
                properties: [
                    new OA\Property(property: 'power_source_code', type: 'string', maxLength: 80, example: 'PWR-0001'),
                    new OA\Property(property: 'power_source_name', type: 'string', maxLength: 200, example: 'Điện AC 220V'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Nguồn điện AC 220V tiêu chuẩn'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo power source thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: power_source_code đã tồn tại)'),
        ]
    )]
    public function storePowerSource(Request $request)
    {
        return $this->storeSimpleMaster($request, 'power_sources', 'power_source_code', 'power_source_name');
    }

    #[OA\Post(
        path: '/api/v1/product-models',
        operationId: 'product_model_create',
        summary: 'Tạo product model mới (catalog master; brand tham chiếu phải ACTIVE)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['model_code', 'model_name'],
                properties: [
                    new OA\Property(property: 'model_code', type: 'string', maxLength: 80, example: 'MDL-0001'),
                    new OA\Property(property: 'model_name', type: 'string', maxLength: 200, example: 'Galaxy S24'),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Model điện thoại Samsung Galaxy S24'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo product model thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: model_code đã tồn tại, BRAND_NOT_ACTIVE)'),
        ]
    )]
    public function storeProductModel(Request $request)
    {
        $validated = $request->validate([
            'model_code' => ['required', 'string', 'max:80', 'unique:product_models,model_code'],
            'model_name' => ['required', 'string', 'max:200'],
            'brand_id' => ['nullable', 'uuid', 'exists:brands,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! empty($validated['brand_id'])) {
            $active = DB::table('brands')->where('id', $validated['brand_id'])->where('status', 'ACTIVE')->exists();
            if (! $active) {
                ApiError::abort(422, 'BRAND_NOT_ACTIVE', 'Product model chỉ được tham chiếu Brand ACTIVE.');
            }
        }

        return $this->insert('product_models', $validated);
    }

    #[OA\Post(
        path: '/api/v1/package-variants',
        operationId: 'package_variant_create',
        summary: 'Tạo package variant mới (catalog master)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['package_code', 'package_name'],
                properties: [
                    new OA\Property(property: 'package_code', type: 'string', maxLength: 80, example: 'PKG-0001'),
                    new OA\Property(property: 'package_name', type: 'string', maxLength: 200, example: 'Đóng gói tiêu chuẩn'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Đóng gói tiêu chuẩn dạng hộp carton'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo package variant thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: package_code đã tồn tại)'),
        ]
    )]
    public function storePackageVariant(Request $request)
    {
        $validated = $request->validate([
            'package_code' => ['required', 'string', 'max:80', 'unique:package_variants,package_code'],
            'package_name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (Schema::hasColumn('package_variants', 'variant_code')) {
            $validated['variant_code'] = $validated['package_code'];
        }
        if (Schema::hasColumn('package_variants', 'variant_name')) {
            $validated['variant_name'] = $validated['package_name'];
        }

        return $this->insert('package_variants', $validated);
    }

    #[OA\Patch(
        path: '/api/v1/brands/{id}',
        operationId: 'brand_update',
        summary: 'Sửa brand (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'brand_code', type: 'string', maxLength: 80, example: 'BR-0001'),
                new OA\Property(property: 'brand_name', type: 'string', maxLength: 200, example: 'Samsung'),
                new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Thương hiệu điện tử Samsung'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], example: 'ACTIVE'),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật brand thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy catalog master'),
            new OA\Response(response: 409, description: 'MASTER_CODE_IMMUTABLE_AFTER_USE: mã đã được SKU tham chiếu'),
            new OA\Response(response: 412, description: 'STALE_VERSION khi If-Match không khớp version'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc EMPTY_UPDATE'),
        ]
    )]
    public function updateBrand(Request $request, string $id)
    {
        return $this->updateMaster($request, $id, 'brands', 'brand_code', 'brand_name');
    }

    #[OA\Patch(
        path: '/api/v1/product-categories/{id}',
        operationId: 'product_category_update',
        summary: 'Sửa product category (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'category_code', type: 'string', maxLength: 80, example: 'CAT-0001'),
                new OA\Property(property: 'category_name', type: 'string', maxLength: 200, example: 'Điện thoại di động'),
                new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Danh mục điện thoại di động'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], example: 'ACTIVE'),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật product category thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy catalog master'),
            new OA\Response(response: 409, description: 'MASTER_CODE_IMMUTABLE_AFTER_USE: mã đã được SKU tham chiếu'),
            new OA\Response(response: 412, description: 'STALE_VERSION khi If-Match không khớp version'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc EMPTY_UPDATE'),
        ]
    )]
    public function updateProductCategory(Request $request, string $id)
    {
        return $this->updateMaster($request, $id, 'product_categories', 'category_code', 'category_name');
    }

    #[OA\Patch(
        path: '/api/v1/power-sources/{id}',
        operationId: 'power_source_update',
        summary: 'Sửa power source (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'power_source_code', type: 'string', maxLength: 80, example: 'PWR-0001'),
                new OA\Property(property: 'power_source_name', type: 'string', maxLength: 200, example: 'Điện AC 220V'),
                new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Nguồn điện AC 220V tiêu chuẩn'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], example: 'ACTIVE'),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật power source thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy catalog master'),
            new OA\Response(response: 409, description: 'MASTER_CODE_IMMUTABLE_AFTER_USE: mã đã được SKU tham chiếu'),
            new OA\Response(response: 412, description: 'STALE_VERSION khi If-Match không khớp version'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc EMPTY_UPDATE'),
        ]
    )]
    public function updatePowerSource(Request $request, string $id)
    {
        return $this->updateMaster($request, $id, 'power_sources', 'power_source_code', 'power_source_name');
    }

    #[OA\Patch(
        path: '/api/v1/product-models/{id}',
        operationId: 'product_model_update',
        summary: 'Sửa product model (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'model_code', type: 'string', maxLength: 80, example: 'MDL-0001'),
                new OA\Property(property: 'model_name', type: 'string', maxLength: 200, example: 'Galaxy S24'),
                new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Model điện thoại Samsung Galaxy S24'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], example: 'ACTIVE'),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật product model thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy catalog master'),
            new OA\Response(response: 409, description: 'MASTER_CODE_IMMUTABLE_AFTER_USE: mã đã được SKU tham chiếu'),
            new OA\Response(response: 412, description: 'STALE_VERSION khi If-Match không khớp version'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc EMPTY_UPDATE'),
        ]
    )]
    public function updateProductModel(Request $request, string $id)
    {
        return $this->updateMaster($request, $id, 'product_models', 'model_code', 'model_name');
    }

    #[OA\Patch(
        path: '/api/v1/package-variants/{id}',
        operationId: 'package_variant_update',
        summary: 'Sửa package variant (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'package_code', type: 'string', maxLength: 80, example: 'PKG-0001'),
                new OA\Property(property: 'package_name', type: 'string', maxLength: 200, example: 'Đóng gói tiêu chuẩn'),
                new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Đóng gói tiêu chuẩn dạng hộp carton'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], example: 'ACTIVE'),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật package variant thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy catalog master'),
            new OA\Response(response: 409, description: 'MASTER_CODE_IMMUTABLE_AFTER_USE: mã đã được SKU tham chiếu'),
            new OA\Response(response: 412, description: 'STALE_VERSION khi If-Match không khớp version'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc EMPTY_UPDATE'),
        ]
    )]
    public function updatePackageVariant(Request $request, string $id)
    {
        return $this->updateMaster($request, $id, 'package_variants', 'package_code', 'package_name');
    }

    /**
     * Annex C listCatalogMasterEntries / createCatalogMasterEntry /
     * updateCatalogMasterEntry — một path chung `/catalog-masters/{master_type}`
     * cho cả năm loại master của REQ-SRS-029. Route theo từng loại
     * (/brands, /product-categories, ...) giữ nguyên cho client hiện hành.
     *
     * Body chấp nhận cả cặp `code`/`name` chung lẫn tên cột riêng của từng loại
     * (brand_code, category_name, ...) để không bắt client viết hai kiểu payload.
     *
     * @var array<string, array{0:string,1:string,2:string}> master_type => [bảng, cột code, cột name]
     */
    private const CATALOG_MASTER_TYPES = [
        'brands' => ['brands', 'brand_code', 'brand_name'],
        'brand' => ['brands', 'brand_code', 'brand_name'],
        'product-categories' => ['product_categories', 'category_code', 'category_name'],
        'category' => ['product_categories', 'category_code', 'category_name'],
        'product-models' => ['product_models', 'model_code', 'model_name'],
        'model' => ['product_models', 'model_code', 'model_name'],
        'power-sources' => ['power_sources', 'power_source_code', 'power_source_name'],
        'power' => ['power_sources', 'power_source_code', 'power_source_name'],
        'power-source' => ['power_sources', 'power_source_code', 'power_source_name'],
        'package-variants' => ['package_variants', 'package_code', 'package_name'],
        'package' => ['package_variants', 'package_code', 'package_name'],
    ];

    public function listCatalogMasterEntries(Request $request, string $masterType)
    {
        [$table] = $this->resolveCatalogMasterType($masterType);

        return $this->page($request, $table);
    }

    #[OA\Post(
        path: '/api/v1/catalog-masters/{masterType}',
        operationId: 'catalog_master_entry_create',
        summary: 'Tạo catalog master entry theo master_type (Annex C createCatalogMasterEntry)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'masterType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['brands', 'product-categories', 'product-models', 'power-sources', 'package-variants'])),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Chấp nhận cặp code/name chung hoặc tên cột riêng của từng loại (brand_code/brand_name, category_code/category_name, model_code/model_name, power_source_code/power_source_name, package_code/package_name).',
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'BR-0001'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Samsung'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Thương hiệu điện tử Samsung'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true, description: 'Chỉ áp dụng cho product-models; brand tham chiếu phải ACTIVE', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo catalog master entry thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'UNKNOWN_MASTER_TYPE / BRAND_NOT_ACTIVE / dữ liệu không hợp lệ'),
        ]
    )]
    public function createCatalogMasterEntry(Request $request, string $masterType)
    {
        [$table, $codeColumn, $nameColumn] = $this->resolveCatalogMasterType($masterType);
        $this->normalizeGenericMasterPayload($request, $codeColumn, $nameColumn);

        return match ($table) {
            'product_models' => $this->storeProductModel($request),
            'package_variants' => $this->storePackageVariant($request),
            default => $this->storeSimpleMaster($request, $table, $codeColumn, $nameColumn),
        };
    }

    #[OA\Patch(
        path: '/api/v1/catalog-masters/{masterType}/{id}',
        operationId: 'catalog_master_entry_update',
        summary: 'Sửa catalog master entry theo master_type (Annex C updateCatalogMasterEntry)',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'masterType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['brands', 'product-categories', 'product-models', 'power-sources', 'package-variants'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Chấp nhận cặp code/name chung hoặc tên cột riêng của từng loại (brand_code/brand_name, category_code/category_name, model_code/model_name, power_source_code/power_source_name, package_code/package_name).',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'BR-0001'),
                new OA\Property(property: 'name', type: 'string', maxLength: 200, example: 'Samsung'),
                new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true, example: 'Thương hiệu điện tử Samsung'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], example: 'ACTIVE'),
                new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, nullable: true, example: 1),
                new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true, description: 'Chỉ áp dụng cho product-models', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật catalog master entry thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy catalog master'),
            new OA\Response(response: 409, description: 'MASTER_CODE_IMMUTABLE_AFTER_USE: mã đã được SKU tham chiếu'),
            new OA\Response(response: 412, description: 'STALE_VERSION khi If-Match không khớp version'),
            new OA\Response(response: 422, description: 'UNKNOWN_MASTER_TYPE / EMPTY_UPDATE / dữ liệu không hợp lệ'),
        ]
    )]
    public function updateCatalogMasterEntry(Request $request, string $masterType, string $id)
    {
        [$table, $codeColumn, $nameColumn] = $this->resolveCatalogMasterType($masterType);
        $this->normalizeGenericMasterPayload($request, $codeColumn, $nameColumn);

        return $this->updateMaster($request, $id, $table, $codeColumn, $nameColumn);
    }

    /** @return array{0:string,1:string,2:string} */
    private function resolveCatalogMasterType(string $masterType): array
    {
        $key = strtolower(str_replace('_', '-', trim($masterType)));
        if (! isset(self::CATALOG_MASTER_TYPES[$key])) {
            ApiError::abort(422, 'UNKNOWN_MASTER_TYPE', 'master_type phải thuộc: brands, product-categories, product-models, power-sources, package-variants.');
        }

        return self::CATALOG_MASTER_TYPES[$key];
    }

    private function normalizeGenericMasterPayload(Request $request, string $codeColumn, string $nameColumn): void
    {
        $mapped = [];
        if (! $request->has($codeColumn) && $request->has('code')) {
            $mapped[$codeColumn] = $request->input('code');
        }
        if (! $request->has($nameColumn) && $request->has('name')) {
            $mapped[$nameColumn] = $request->input('name');
        }
        if ($mapped !== []) {
            $request->merge($mapped);
        }
    }

    /**
     * Annex C updateCatalogMasterEntry + §8.1 Master: "Unique, active/inactive,
     * immutable code after use". BR-MST-01..07: deactivate thay vì xoá; mã đã được
     * SKU tham chiếu thì không đổi được. If-Match theo ERD (version) — chỉ enforce
     * khi client gửi header để không phá client đang chạy.
     */
    private function updateMaster(Request $request, string $id, string $table, string $codeColumn, string $nameColumn)
    {
        $rules = [
            $codeColumn => ['sometimes', 'string', 'max:80', Rule::unique($table, $codeColumn)->ignore($id, 'id')],
            $nameColumn => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
        if ($table === 'product_models') {
            $rules['brand_id'] = ['nullable', 'uuid', 'exists:brands,id'];
        }
        $validated = $request->validate($rules);
        if ($validated === []) {
            ApiError::abort(422, 'EMPTY_UPDATE', 'Phải gửi ít nhất một trường để cập nhật.');
        }

        return DB::transaction(function () use ($request, $id, $table, $codeColumn, $nameColumn, $validated) {
            $current = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if ($current === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy catalog master.');
            }
            $this->assertVersionIfProvided($request, $current);

            if (array_key_exists($codeColumn, $validated)
                && (string) $validated[$codeColumn] !== (string) $current->{$codeColumn}
                && $this->masterIsReferenced($table, $id)) {
                ApiError::abort(409, 'MASTER_CODE_IMMUTABLE_AFTER_USE', 'Mã master đã được SKU tham chiếu nên không được đổi; hãy tạo master mới.');
            }

            $payload = $validated + ['updated_at' => now()];
            // package_variants giữ cả cặp cột legacy variant_* nên phải ghi đồng bộ.
            if ($table === 'package_variants') {
                if (array_key_exists($codeColumn, $validated) && Schema::hasColumn($table, 'variant_code')) {
                    $payload['variant_code'] = $validated[$codeColumn];
                }
                if (array_key_exists($nameColumn, $validated) && Schema::hasColumn($table, 'variant_name')) {
                    $payload['variant_name'] = $validated[$nameColumn];
                }
            }
            foreach (['updated_by' => $this->actorId(), 'version' => ((int) ($current->version ?? 0)) + 1] as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $payload[$column] = $value;
                }
            }

            DB::table($table)->where('id', $id)->update($payload);
            $this->invalidateMasterCache($table);

            AuditLogger::record(
                action: 'UPDATE',
                objectType: $table,
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: (array) $current,
                afterData: (array) DB::table($table)->where('id', $id)->first(),
            );

            return $this->respondSuccess(DB::table($table)->where('id', $id)->first(), 'Cập nhật catalog master thành công.');
        }, 3);
    }

    /**
     * Master được coi là "đã dùng" khi có SKU trỏ tới.
     *
     * BR-MST-01..07 áp cho CẢ NĂM loại master; trước đây power_sources và
     * package_variants rơi vào nhánh default => null nên vẫn đổi được mã sau khi
     * SKU đã tham chiếu (ERD bảng 16 có đủ hai FK skus.power_source_id /
     * skus.package_variant_id).
     */
    private function masterIsReferenced(string $table, string $id): bool
    {
        $column = match ($table) {
            'brands' => 'brand_id',
            'product_categories' => 'category_id',
            'product_models' => 'model_id',
            'power_sources' => 'power_source_id',
            'package_variants' => 'package_variant_id',
            default => null,
        };
        if ($column === null || ! Schema::hasColumn('skus', $column)) {
            return false;
        }

        return DB::table('skus')->where($column, $id)->exists();
    }

    private function assertVersionIfProvided(Request $request, object $current): void
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            return;
        }
        $header = trim(preg_replace('/^W\//', '', $header) ?? $header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên.');
        }
        if ((int) $header !== (int) ($current->version ?? 0)) {
            ApiError::abort(412, 'STALE_VERSION', 'Bản ghi đã được cập nhật bởi phiên khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $header,
                'current_version' => (int) ($current->version ?? 0),
            ]);
        }
    }

    private function storeSimpleMaster(Request $request, string $table, string $code, string $name)
    {
        $validated = $request->validate([
            $code => ['required', 'string', 'max:80', "unique:{$table},{$code}"],
            $name => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->insert($table, $validated);
    }

    private function insert(string $table, array $data)
    {
        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            ...$data,
            'status' => $data['status'] ?? 'ACTIVE',
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (['created_by', 'updated_by', 'version'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $row[$column] = $column === 'version' ? 0 : $this->actorId();
            }
        }
        DB::table($table)->insert($row);
        $this->invalidateMasterCache($table);

        return $this->respondCreated(DB::table($table)->where('id', $id)->first(), 'Created.');
    }

    private function page(Request $request, string $table)
    {
        $filters = $request->only(['status', 'keyword']);
        $perPage = $this->perPage($request, 50);
        $currentPage = (int) $request->integer('page', 1);

        $key = CacheKey::of("warehouse:catalog-master:{$table}:list", [
            'f' => CacheKey::hashFilters($filters),
            'per_page' => $perPage,
            'page' => $currentPage,
        ], $this->cacheService()->currentVersion($this->listVersionNamespace($table)));

        // Chỉ cache DỮ LIỆU thô (rows + total) — không cache JsonResponse/paginator
        // object, vì response còn mang request_id/timestamp/links riêng của từng
        // request (mục 21: không cache object graph phức tạp/Request). Paginator
        // và response luôn được dựng lại mới ở mọi lần gọi, kể cả cache hit.
        $cached = $this->cacheService()->rememberStampede(
            $key,
            CacheTtl::seconds(self::CACHE_TTL_POLICY),
            function () use ($table, $request, $perPage, $currentPage): array {
                $paginator = $this->buildMasterQuery($table, $request)->paginate($perPage, ['*'], 'page', $currentPage);

                return ['items' => $paginator->items(), 'total' => $paginator->total()];
            }
        );

        $paginator = new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->respondPaginated($paginator);
    }

    private function buildMasterQuery(string $table, Request $request)
    {
        $query = DB::table($table);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $this->applyKeyword($query, $request, $this->keywordColumns($table));

        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    private function listVersionNamespace(string $table): string
    {
        return "warehouse.catalog_master.{$table}:list";
    }

    private function invalidateMasterCache(string $table): void
    {
        $namespace = $this->listVersionNamespace($table);
        DB::afterCommit(fn () => $this->cacheService()->bumpVersion($namespace));
    }

    private function cacheService(): CacheService
    {
        return app(CacheService::class);
    }

    /**
     * @return array<int, string>
     */
    private function keywordColumns(string $table): array
    {
        $candidates = [
            'brands' => ['brand_code', 'brand_name'],
            'product_categories' => ['category_code', 'category_name'],
            'power_sources' => ['power_source_code', 'power_source_name'],
            'product_models' => ['model_code', 'model_name'],
            'package_variants' => ['package_code', 'package_name', 'variant_code', 'variant_name'],
        ][$table] ?? [];

        return array_values(array_filter($candidates, fn (string $column): bool => Schema::hasColumn($table, $column)));
    }

}
