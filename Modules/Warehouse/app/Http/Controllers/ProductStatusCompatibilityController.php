<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;
use OpenApi\Attributes as OA;

/** Legacy enable/disable actions retained for pre-SKU clients. */
class ProductStatusCompatibilityController extends Controller
{
    use ApiResponse;

    #[OA\Patch(
        path: '/api/v1/products/{id}/enable',
        operationId: 'product_status_compat_enable',
        summary: 'Kích hoạt SKU',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU cập nhật status=ACTIVE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền sku.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
        ]
    )]
    public function enable(string $id)
    {
        return $this->setStatus($id, 'ACTIVE');
    }

    #[OA\Patch(
        path: '/api/v1/products/{id}/disable',
        operationId: 'product_status_compat_disable',
        summary: 'Ngừng kích hoạt SKU',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU cập nhật status=INACTIVE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền sku.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy SKU'),
        ]
    )]
    public function disable(string $id)
    {
        return $this->setStatus($id, 'INACTIVE');
    }

    private function setStatus(string $id, string $status)
    {
        $sku = Sku::query()->find($id);
        if ($sku === null) {
            return $this->respondNotFound('Không tìm thấy SKU.');
        }

        $before = $sku->toArray();
        $sku->update(['status' => $status]);

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'sku',
            objectId: $sku->sku_id,
            actorId: auth('api')->id(),
            requestId: request()->attributes->get('request_id'),
            beforeData: $before,
            afterData: $sku->fresh()->toArray(),
        );

        return $this->respondSuccess($sku->fresh(), 'Cập nhật trạng thái SKU thành công');
    }
}
