<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\PvCatalogImportService;
use OpenApi\Attributes as OA;

/**
 * FE App PV yêu cầu importer JSON riêng cho catalogue DongCheng (61 SKU khởi
 * tạo) — khác `POST /api/v1/skus/import-excel` (WWHN-172, chỉ tạo `sku`, không
 * đụng hồ sơ App PV). Endpoint này tạo ĐỒNG THỜI SKU + hồ sơ App PV (tên hiển
 * thị/mô tả/specifications/ảnh) trong một giao dịch atomic, published_to_app
 * luôn false (publish là thao tác riêng: PATCH /pv/products/{sku_id}/publish,
 * quyền pv.publish).
 */
class PvCatalogImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PvCatalogImportService $importService) {}

    #[OA\Post(
        path: '/api/v1/pv-admin/catalog-import',
        operationId: 'pv_catalog_import',
        summary: 'App PV — import hàng loạt SKU + hồ sơ trình bày (catalogue DongCheng), hỗ trợ dry-run',
        description: 'Tạo đồng thời `sku` + hồ sơ App PV (display_name/description/specifications/ảnh) cho mỗi dòng, '
            .'trong một giao dịch all-or-nothing: có bất kỳ dòng nào lỗi thì committed=false, không có gì được ghi. '
            .'`published_to_app` LUÔN false khi tạo (đây không phải importer để publish) — dùng '
            .'PATCH /api/v1/pv/products/{sku_id}/publish (quyền pv.publish) sau khi đã QA xong từng SKU. '
            .'Không nhận giá/số lượng/barcode: các khoá đó bị từ chối tường minh (FORBIDDEN_FIELD) ngay ở bước validate, '
            .'trước khi chạm DB. `brand_code`/`category_code` phải đã tồn tại ACTIVE trong master data '
            .'(tạo trước qua POST /api/v1/brands, /api/v1/product-categories) — dry_run=true cho phép kiểm tra mapping '
            .'trước khi tạo master data thật, lỗi trả về UNKNOWN_MASTER_CODE theo từng dòng.',
        security: [['bearerAuth' => []]],
        tags: ['12. App PV'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'dry_run', type: 'boolean', default: false, example: true),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        maxItems: 500,
                        items: new OA\Items(
                            type: 'object',
                            required: ['product_code', 'name', 'brand_code', 'category_code'],
                            properties: [
                                new OA\Property(property: 'product_code', type: 'string', maxLength: 100, example: 'DC-DZ180-001'),
                                new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Máy khoan vặn vít dùng pin DongCheng DZ180'),
                                new OA\Property(property: 'brand_code', type: 'string', example: 'DONGCHENG'),
                                new OA\Property(property: 'category_code', type: 'string', example: 'DRILL_FASTEN_DC'),
                                new OA\Property(property: 'domain', type: 'string', enum: ['POWER_TOOLS', 'HAND_TOOLS', 'ACCESSORIES'], nullable: true, example: 'POWER_TOOLS'),
                                new OA\Property(property: 'unit', type: 'string', nullable: true, example: 'Cái'),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                new OA\Property(property: 'short_description', type: 'string', nullable: true),
                                new OA\Property(property: 'specifications', type: 'object', nullable: true, example: ['power' => '18V', 'voltage' => '18V']),
                                new OA\Property(
                                    property: 'media_url',
                                    type: 'array',
                                    nullable: true,
                                    items: new OA\Items(type: 'string', format: 'uri', example: 'https://cdn.example.com/dongcheng/dz180.jpg')
                                ),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Import committed atomically, hoặc dry-run hợp lệ (committed=false, dry_run=true, would_insert=N).'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Thiếu quyền sku.manage hoặc pv.master.manage'),
            new OA\Response(response: 422, description: 'row_errors theo từng dòng (product_code/name/brand_code/category_code/domain/media_url/specifications không hợp lệ, '
                .'UNKNOWN_MASTER_CODE, CATEGORY_DOMAIN_MISMATCH, DUPLICATE_CODE, hoặc FORBIDDEN_FIELD nếu payload chứa price/quantity/barcode/published). '
                .'Không có SKU/hồ sơ nào được ghi.'),
        ]
    )]
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
        ]);

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $actorId = ($id = auth('api')->id()) === null ? null : (string) $id;
        $requestId = is_string($request->attributes->get('request_id'))
            ? $request->attributes->get('request_id')
            : null;

        $result = $this->importService->import($validated['items'], $dryRun, $actorId, $requestId);

        if (isset($result['_http_status'])) {
            return $this->respondError($result['_message'], [], $result['_http_status'], $result['_error_code']);
        }

        if ($result['row_errors'] !== []) {
            return $this->respondError(
                $dryRun
                    ? 'Kiểm tra thử import hoàn tất; phát hiện lỗi, không có gì được tạo.'
                    : 'Import bị từ chối; không có SKU/hồ sơ nào được tạo.',
                $result['row_errors'],
                422,
                $dryRun ? 'PV_CATALOG_IMPORT_DRY_RUN_REJECTED' : 'PV_CATALOG_IMPORT_VALIDATION_FAILED',
                ['data' => $result],
            );
        }

        return $this->respondSuccess(
            $result,
            $dryRun
                ? 'Kiểm tra thử import hoàn tất, không có dữ liệu nào được ghi.'
                : 'Import catalogue App PV đã commit nguyên tử. published_to_app=false cho toàn bộ SKU vừa tạo.',
        );
    }
}
