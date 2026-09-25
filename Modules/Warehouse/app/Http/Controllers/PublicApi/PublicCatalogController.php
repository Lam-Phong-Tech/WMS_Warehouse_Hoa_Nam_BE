<?php

namespace Modules\Warehouse\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\PublicApiEnvelope;
use App\Support\PublicApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            new OA\Parameter(
                name: 'ids[]',
                in: 'query',
                description: 'Chế độ TRA THEO ID (dùng cho màn "đã lưu" của App): trả đúng các sản phẩm có id gửi lên, '
                    .'theo ĐÚNG thứ tự gửi, kèm `data.missing_ids` cho id không còn hiển thị công khai. '
                    .'Tối đa 50 id, loại trùng tự động. KHÔNG dùng chung với q/domain/category/power_source/feature/spec.*/'
                    .'sort/cursor/limit — trộn vào sẽ trả 400 INVALID_QUERY. Chế độ này không phân trang nên không có page_info.',
                schema: new OA\Schema(type: 'array', maxItems: 50, items: new OA\Items(type: 'string', format: 'uuid'))
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data.items + data.page_info {limit, has_more, next_cursor, total}; total đếm SKU công khai theo bộ lọc trước phân trang. Riêng ids[] trả data.items + data.missing_ids, không có page_info.'),
            new OA\Response(response: 400, description: 'INVALID_QUERY / INVALID_SORT / INVALID_DOMAIN / INVALID_CURSOR'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function products(Request $request): JsonResponse
    {
        $ids = $this->productIdsParam($request);
        if ($ids !== null) {
            return PublicApiEnvelope::success(
                $this->readModel->productsByIds($ids),
                'Lấy sản phẩm theo danh sách id thành công.'
            );
        }

        $result = $this->readModel->listProducts(
            $this->catalogFilters($request),
            $this->sortParam($request),
            $this->stringParam($request, 'cursor'),
            $this->limitParam($request),
        );

        return PublicApiEnvelope::success($result, 'Lấy danh sách sản phẩm thành công.');
    }

    /**
     * Tham số của chế độ tra-theo-id. Trả null khi client không gửi `ids`.
     *
     * Vì sao chặn trộn tham số thay vì bỏ qua: tra theo id không phân trang và
     * không sắp theo sort của catalog, nên `?ids[]=..&sort=name_asc&limit=5`
     * KHÔNG thể phục vụ đúng cả hai vế. Im lặng bỏ vế còn lại là kiểu hỏng tệ
     * nhất — client tưởng đã lọc/sắp/cắt trang, thực tế thì không. (Đúng lớp lỗi
     * mà `ids[]` đang mắc trước bản vá này: bị bỏ qua hoàn toàn và trả về nguyên
     * trang đầu 20 sản phẩm, nhìn như đã chạy.)
     *
     * @return list<string>|null
     */
    private function productIdsParam(Request $request): ?array
    {
        $ids = $this->listParam($request, 'ids');
        $sent = array_key_exists('ids', $this->rawQuery($request));

        if (! $sent) {
            return null;
        }
        if ($ids === []) {
            PublicApiError::invalidQuery('ids không được rỗng.');
        }

        $conflicting = array_values(array_filter(
            array_keys($this->rawQuery($request)),
            static fn (string $name): bool => $name !== 'ids' && (
                str_starts_with($name, 'spec.')
                || in_array($name, ['q', 'domain', 'category', 'power_source', 'feature', 'sort', 'cursor', 'limit'], true)
            )
        ));
        if ($conflicting !== []) {
            PublicApiError::invalidQuery(
                'Không dùng ids chung với bộ lọc/sắp xếp/phân trang.',
                ['conflicting' => $conflicting]
            );
        }

        $max = max(1, (int) config('public_api.max_ids', 50));
        if (count($ids) > $max) {
            PublicApiError::invalidQuery("ids tối đa {$max} phần tử.", ['sent' => count($ids)]);
        }

        // UUID sai định dạng là lỗi của client, không phải "không tìm thấy":
        // gộp nó vào missing_ids sẽ khiến app xoá vĩnh viễn một id do CHÍNH nó
        // ghi hỏng, mà không ai biết đã hỏng ở đâu.
        $invalid = array_values(array_filter($ids, static fn (string $id): bool => Str::isUuid($id) === false));
        if ($invalid !== []) {
            PublicApiError::invalidQuery('ids phải là UUID hợp lệ.', ['invalid' => array_slice($invalid, 0, 5)]);
        }

        return $ids;
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
            new OA\Response(response: 200, description: 'Chi tiết sản phẩm công khai, availability IN_STOCK hoặc PREORDER'),
            new OA\Response(response: 404, description: 'PRODUCT_NOT_FOUND — không tồn tại, không ACTIVE hoặc chưa publish; hết tồn không phải lý do 404'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function product(string $slug): JsonResponse
    {
        $product = $this->readModel->findBySlug($slug);
        if ($product === null) {
            // Không tiết lộ mã chưa công khai. Hết tồn vẫn trả PREORDER.
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
            new OA\Response(response: 200, description: 'data.items + data.page_info {limit, has_more, next_cursor, total}; total không gồm chính sản phẩm đang xem'),
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
