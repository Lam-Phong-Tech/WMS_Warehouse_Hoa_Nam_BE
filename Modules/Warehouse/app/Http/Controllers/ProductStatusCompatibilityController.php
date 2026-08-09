<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Sku;

/** Legacy enable/disable actions retained for pre-SKU clients. */
class ProductStatusCompatibilityController extends Controller
{
    use ApiResponse;

    public function enable(string $id)
    {
        return $this->setStatus($id, 'ACTIVE');
    }

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
