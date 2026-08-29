<?php

namespace Modules\Warehouse\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\PublicApiEnvelope;
use App\Support\PublicApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Controllers\PublicApi\Concerns\ParsesPublicQuery;
use Modules\Warehouse\Services\PublicCatalogReadModel;
use OpenApi\Attributes as OA;

/**
 * Storefront công khai — nhóm catalog của `/api/v1/public/*`.
 *
 * Ẩn danh hoàn toàn (`security: []`), chỉ đọc, nằm trong nhóm
 * `throttle:public-api`. Không dùng $request->validate() vì nó ném 422 kèm
 * envelope validation của Laravel; nhóm này quy ước mọi lỗi tham số là 400 với
 * mã lỗi tường minh (INVALID_QUERY / INVALID_SORT / INVALID_DOMAIN /
 * INVALID_CURSOR) để FE switch-case được.
 */
class PublicCatalogController extends Controller
{
    use ParsesPublicQuery;

    public function __construct(private readonly PublicCatalogReadModel $readModel) {}

    #[OA\Get(
        path: '/api/v1/public/categories',
        operationId: 'listPublicCategories',
        summary: 'Storefront — danh mục theo domain',
        security: [],
        tags: ['13. Public Storefront'],
        parameters: [
            new OA\Parameter(name: 'domain', in: 'query', schema: new OA\Schema(type: 'string', enum: ['POWER_TOOLS', 'HAND_TOOLS', 'ACCESSORIES'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách danh mục'),
            new OA\Response(response: 400, description: 'INVALID_DOMAIN'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function categories(Request $request): JsonResponse
    {
        $domain = $this->domainParam($request);

        return PublicApiEnvelope::success(
            ['items' => $this->readModel->categories($domain)],
            'Lấy danh mục thành công.'
        );
    }

    #[OA\Get(
        path: '/api/v1/public/products',
        operationId: 'listPublicProducts',
        summary: 'Storefront — danh sách sản phẩm đang bán (tìm kiếm/lọc/sắp xếp/cursor)',
        security: [],
        tags: ['13. Public Storefront'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Từ khoá; không phân biệt hoa/thường và dấu.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'query', schema: new OA\Schema(type: 'string', enum: ['POWER_TOOLS', 'HAND_TOOLS', 'ACCESSORIES'])),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'power_source', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'feature', in: 'query', description: 'Lặp lại được; hoặc phân tách bằng dấu phẩy.', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['featured', 'updated_desc', 'name_asc'], default: 'featured')),
            new OA\Parameter(name: 'cursor', in: 'query', description: 'Token đục do backend phát hành.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data.items + data.page_info.next_cursor'),
            new OA\Response(response: 400, description: 'INVALID_QUERY / INVALID_SORT / INVALID_DOMAIN / INVALID_CURSOR'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function products(Request $request): JsonResponse
    {
        $result = $this->readModel->listProducts(
            $this->catalogFilters($request),
            $this->sortParam($request),
            $this->stringParam($request, 'cursor'),
            $this->limitParam($request),
        );

        return PublicApiEnvelope::success($result, 'Lấy danh sách sản phẩm thành công.');
    }

    #[OA\Get(
        path: '/api/v1/public/products/{slug}',
        operationId: 'getPublicProduct',
        summary: 'Storefront — chi tiết sản phẩm theo slug',
        security: [],
        tags: ['13. Public Storefront'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết sản phẩm'),
            new OA\Response(response: 404, description: 'PRODUCT_NOT_FOUND (kể cả khi sản phẩm tồn tại nhưng chưa publish hoặc hết hàng)'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function product(string $slug): JsonResponse
    {
        $product = $this->readModel->findBySlug($slug);
        if ($product === null) {
            // 404 che cả ba ca: không tồn tại / chưa publish / hết hàng. Phân
            // biệt chúng là xác nhận cho người ẩn danh biết mã hàng nội bộ nào
            // có thật.
            PublicApiError::productNotFound();
        }

        return PublicApiEnvelope::success($product, 'Lấy chi tiết sản phẩm thành công.');
    }

    #[OA\Get(
        path: '/api/v1/public/products/{slug}/related',
        operationId: 'listPublicRelatedProducts',
        summary: 'Storefront — sản phẩm liên quan (cursor pagination)',
        security: [],
        tags: ['13. Public Storefront'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data.items + data.page_info.next_cursor'),
            new OA\Response(response: 404, description: 'PRODUCT_NOT_FOUND'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function related(Request $request, string $slug): JsonResponse
    {
        $product = $this->readModel->findBySlug($slug);
        if ($product === null) {
            PublicApiError::productNotFound();
        }

        $result = $this->readModel->related(
            $product,
            $this->stringParam($request, 'cursor'),
            $this->limitParam($request),
        );

        return PublicApiEnvelope::success($result, 'Lấy sản phẩm liên quan thành công.');
    }

    #[OA\Get(
        path: '/api/v1/public/facets',
        operationId: 'listPublicFacets',
        summary: 'Storefront — bộ lọc động kèm số lượng',
        security: [],
        tags: ['13. Public Storefront'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'power_source', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'feature', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'category / power_source / feature / spec facets'),
            new OA\Response(response: 400, description: 'INVALID_QUERY'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function facets(Request $request): JsonResponse
    {
        return PublicApiEnvelope::success(
            $this->readModel->facets($this->catalogFilters($request)),
            'Lấy bộ lọc thành công.'
        );
    }
}
