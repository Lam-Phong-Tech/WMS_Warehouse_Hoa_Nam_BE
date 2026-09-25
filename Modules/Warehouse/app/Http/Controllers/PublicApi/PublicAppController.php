<?php

namespace Modules\Warehouse\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\PublicApiEnvelope;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Services\PublicCatalogReadModel;
use OpenApi\Attributes as OA;

/**
 * Storefront công khai — nhóm khởi động app: `/config`, `/home`,
 * `/health/version`.
 *
 * FE gọi SONG SONG `/config` và `/home` lúc mở app, nên hai endpoint này không
 * được phụ thuộc lẫn nhau và phải trả lỗi độc lập: `/home` chết thì `/config`
 * vẫn phải sống để FE hiện được hotline và màn hình bảo trì.
 */
class PublicAppController extends Controller
{
    public function __construct(private readonly PublicCatalogReadModel $readModel) {}

    #[OA\Get(
        path: '/api/v1/public/config',
        operationId: 'getPublicConfig',
        summary: 'Storefront — cấu hình khởi động (hotline, Zalo OA, giờ hỗ trợ, maintenance, feature flags)',
        security: [],
        tags: ['13. Public Storefront'],
        responses: [
            new OA\Response(response: 200, description: 'Cấu hình công khai'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function config(): JsonResponse
    {
        return PublicApiEnvelope::success([
            'contact' => [
                'hotline' => config('public_api.contact.hotline'),
                'zalo_oa_id' => config('public_api.contact.zalo_oa_id'),
                'zalo_oa_url' => config('public_api.contact.zalo_oa_url'),
                'email' => config('public_api.contact.email'),
            ],
            'support_hours' => [
                'timezone' => config('public_api.support_hours.timezone'),
                'weekday' => [
                    'open' => config('public_api.support_hours.weekday_open'),
                    'close' => config('public_api.support_hours.weekday_close'),
                ],
                'saturday' => [
                    'open' => config('public_api.support_hours.saturday_open'),
                    'close' => config('public_api.support_hours.saturday_close'),
                ],
                'note' => config('public_api.support_hours.note'),
            ],
            'privacy' => [
                'url' => config('public_api.privacy.url'),
                // FE phải gửi lại đúng chuỗi này khi POST /quote-requests.
                'version' => config('public_api.privacy.version'),
            ],
            'maintenance' => [
                'enabled' => (bool) config('public_api.maintenance.enabled'),
                'message' => config('public_api.maintenance.message'),
            ],
            'feature_flags' => array_map(
                static fn ($value): bool => (bool) $value,
                (array) config('public_api.feature_flags', [])
            ),
            'quote_request' => [
                // Công bố ràng buộc để FE validate tại chỗ thay vì đoán rồi ăn 400.
                'idempotency_key_min_length' => (int) config('public_api.quote_request.idempotency_key_min'),
                'idempotency_key_max_length' => (int) config('public_api.quote_request.idempotency_key_max'),
                'note_max_length' => (int) config('public_api.quote_request.note_max_length'),
                'max_items' => (int) config('public_api.quote_request.max_items', 20),
                'max_quantity' => (int) config('public_api.quote_request.max_quantity', 9999),
            ],
            'catalog' => [
                'default_limit' => (int) config('public_api.default_limit'),
                'max_limit' => (int) config('public_api.max_limit'),
                'sorts' => PublicCatalogReadModel::SORTS,
            ],
            'contract_version' => config('public_api.contract_version'),
        ], 'Lấy cấu hình thành công.');
    }

    #[OA\Get(
        path: '/api/v1/public/home',
        operationId: 'getPublicHome',
        summary: 'Storefront — 3 domain và các section Home có dữ liệu',
        security: [],
        tags: ['13. Public Storefront'],
        responses: [
            new OA\Response(response: 200, description: 'data.domains + data.sections'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function home(): JsonResponse
    {
        return PublicApiEnvelope::success([
            'domains' => $this->readModel->domains(),
            // Chỉ những section thật sự có sản phẩm; xem
            // PublicCatalogReadModel::homeSections().
            'sections' => $this->readModel->homeSections(),
        ], 'Lấy dữ liệu trang chủ thành công.');
    }

    #[OA\Get(
        path: '/api/v1/public/health/version',
        operationId: 'getPublicHealthVersion',
        summary: 'Storefront — version hợp đồng và cờ bảo trì',
        security: [],
        tags: ['13. Public Storefront'],
        responses: [
            new OA\Response(response: 200, description: 'Trạng thái dịch vụ'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
        ]
    )]
    public function healthVersion(): JsonResponse
    {
        $maintenance = (bool) config('public_api.maintenance.enabled');

        // CỐ Ý không trả commit SHA, tên nhánh, version framework hay uptime:
        // endpoint này ẩn danh, mọi chi tiết build đều là thông tin trinh sát
        // miễn phí cho người tấn công. Chỉ đủ để FE biết có cần cập nhật app và
        // có đang bảo trì hay không.
        return PublicApiEnvelope::success([
            'status' => $maintenance ? 'MAINTENANCE' : 'OK',
            'contract_version' => config('public_api.contract_version'),
            'maintenance' => [
                'enabled' => $maintenance,
                'message' => $maintenance ? config('public_api.maintenance.message') : null,
            ],
            'server_time' => now()->toIso8601String(),
        ], 'Dịch vụ đang hoạt động.');
    }
}
