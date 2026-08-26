<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Warehouse\Services\PvCatalogReadModel;
use OpenApi\Attributes as OA;

/**
 * WWHN-252 / HN-PV-001 — mặt công khai của App PV (Annex C: listPvProducts,
 * getPvProduct, listPvIndustries, listPvRecommendations).
 *
 * Ba ràng buộc hợp đồng chi phối toàn bộ controller này:
 *
 * 1. security: [] — PUBLIC_ANONYMOUS. Route nằm ngoài `auth:api`, trong nhóm
 *    `throttle:pv-public` (config/rate_limit.php) để 429 trong error set là mã
 *    có thật.
 * 2. Error set của cả 4 operation là ĐÚNG {400, 404, 429, 500} — KHÔNG có 422.
 *    Vì vậy controller không dùng $request->validate() (nó ném 422) mà tự kiểm
 *    tham số rồi ApiError::abort(400, 'INVALID_QUERY'|'INVALID_PATH').
 * 3. §15 OP-09: response chỉ chứa dữ liệu catalog + available_qty + stock_status
 *    + data_as_of. Whitelist nằm ở PvCatalogReadModel::presentMany().
 */
class PvPublicCatalogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PvCatalogReadModel $readModel) {}

    #[OA\Get(
        path: '/api/v1/pv/products',
        operationId: 'listPvProducts',
        summary: 'App PV — danh sách sản phẩm đã publish (ẩn danh, read-only)',
        security: [],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'industry_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'tag_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'brand_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'power_source_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'in_stock', in: 'query', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PVProductPage'),
            new OA\Response(response: 400, description: 'INVALID_QUERY'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function listProducts(Request $request): JsonResponse
    {
        $filters = [
            'keyword' => (string) $request->query('keyword', ''),
            'industry_id' => $this->optionalUuid($request, 'industry_id'),
            'tag_id' => $this->optionalUuid($request, 'tag_id'),
            'brand_id' => $this->optionalUuid($request, 'brand_id'),
            'category_id' => $this->optionalUuid($request, 'category_id'),
            'power_source_id' => $this->optionalUuid($request, 'power_source_id'),
            'in_stock' => $this->optionalBool($request, 'in_stock'),
        ];

        $page = $this->pageNumber($request);
        $perPage = $this->pageSize($request);

        return $this->respondPvPage($this->readModel->paginateProducts($filters, $page, $perPage));
    }

    #[OA\Get(
        path: '/api/v1/pv/products/{skuId}',
        operationId: 'getPvProduct',
        summary: 'App PV — chi tiết sản phẩm đã publish (ẩn danh, read-only)',
        security: [],
        tags: ['12. App PV'],
        parameters: [
            // Tên tham số phải TRÙNG tên biến route ({skuId}) chứ không phải tên
            // trong Annex C ({sku_id}): RouteSyncProcessor ghép annotation với
            // route theo path đã render, lệch một ký tự là nó coi như không có
            // annotation và tự sinh summary skeleton.
            new OA\Parameter(name: 'skuId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PVProduct'),
            new OA\Response(response: 400, description: 'INVALID_PATH'),
            new OA\Response(response: 404, description: 'RESOURCE_NOT_FOUND (kể cả khi SKU tồn tại nhưng chưa publish)'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function showProduct(string $skuId): JsonResponse
    {
        if (! $this->looksLikeUuid($skuId)) {
            ApiError::abort(400, 'INVALID_PATH', 'sku_id phải là UUID.');
        }

        $product = $this->readModel->findPublishedProduct($skuId);
        if ($product === null) {
            // §13.8: 404 mask cả hai ca "không tồn tại" và "chưa publish/không
            // ACTIVE" — deep-link tới SKU nội bộ không được xác nhận sự tồn tại.
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        $response = $this->respondSuccess($product);

        return $this->mergeFreshnessMeta($response);
    }

    #[OA\Get(
        path: '/api/v1/pv/industries',
        operationId: 'listPvIndustries',
        summary: 'App PV — danh sách ngành nghề ACTIVE dùng cho filter/gợi ý',
        security: [],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PVIndustryPage'),
            new OA\Response(response: 400, description: 'INVALID_QUERY'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function listIndustries(Request $request): JsonResponse
    {
        return $this->respondPvPage($this->readModel->paginateIndustries(
            (string) $request->query('keyword', ''),
            $this->pageNumber($request),
            $this->pageSize($request),
        ));
    }

    #[OA\Get(
        path: '/api/v1/pv/recommendations',
        operationId: 'listPvRecommendations',
        summary: 'App PV — gợi ý sản phẩm theo ngành nghề (rule ACTIVE, theo priority)',
        security: [],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'industry_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PVProductPage (kèm recommendation_priority)'),
            new OA\Response(response: 400, description: 'INVALID_QUERY'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function listRecommendations(Request $request): JsonResponse
    {
        $industryId = $this->optionalUuid($request, 'industry_id');
        if ($industryId === null) {
            ApiError::abort(400, 'INVALID_QUERY', 'industry_id là tham số bắt buộc và phải là UUID.');
        }

        return $this->respondPvPage($this->readModel->paginateRecommendations(
            $industryId,
            $this->pageNumber($request),
            $this->pageSize($request),
        ));
    }

    // ------------------------------------------------------------------
    // Helpers — mọi lỗi tham số của nhóm PV là 400, không phải 422.
    // ------------------------------------------------------------------

    private function respondPvPage(LengthAwarePaginator $paginator): JsonResponse
    {
        return $this->mergeFreshnessMeta($this->respondPaginated($paginator));
    }

    private function mergeFreshnessMeta(JsonResponse $response): JsonResponse
    {
        $payload = $response->getData(true);
        $payload['meta'] = array_merge($payload['meta'] ?? [], $this->readModel->freshnessMeta());
        $response->setData($payload);

        return $response;
    }

    private function pageNumber(Request $request): int
    {
        $raw = $request->query('page');
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (! is_scalar($raw) || ! ctype_digit((string) $raw) || (int) $raw < 1) {
            ApiError::abort(400, 'INVALID_QUERY', 'page phải là số nguyên dương.');
        }

        return (int) $raw;
    }

    /**
     * NFR-SRS-009 đo P95 với page_size <= 50 nên vượt trần là lỗi tham số, không
     * phải im lặng cắt bớt — client sẽ tưởng đã nhận đủ trang.
     */
    private function pageSize(Request $request): int
    {
        $max = max(1, (int) config('pv.max_page_size', 50));
        $raw = $request->query('per_page');
        if ($raw === null || $raw === '') {
            return min($max, max(1, (int) config('pv.default_page_size', 20)));
        }
        if (! is_scalar($raw) || ! ctype_digit((string) $raw) || (int) $raw < 1) {
            ApiError::abort(400, 'INVALID_QUERY', 'per_page phải là số nguyên dương.');
        }
        if ((int) $raw > $max) {
            ApiError::abort(400, 'INVALID_QUERY', "per_page tối đa là {$max}.");
        }

        return (int) $raw;
    }

    private function optionalUuid(Request $request, string $key): ?string
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw) || ! $this->looksLikeUuid($raw)) {
            ApiError::abort(400, 'INVALID_QUERY', "{$key} phải là UUID.");
        }

        return $raw;
    }

    private function optionalBool(Request $request, string $key): ?bool
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $normalized = strtolower((string) $raw);
        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return false;
        }

        ApiError::abort(400, 'INVALID_QUERY', "{$key} phải là boolean.");
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }
}
