<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;

class ScanResolver
{
    /** @return array{physical_code: PhysicalCode, type: string, item: ?ItemInstance, container: ?Container, sku_id: ?string} */
    public function resolveActive(string $rawCode): array
    {
        $physicalCode = PhysicalCode::query()->where('code_value', trim($rawCode))->first();
        if (! $physicalCode) {
            ApiError::abort(404, 'SCAN_CODE_NOT_FOUND', 'Không tìm thấy mã scan.');
        }

        if ($physicalCode->status !== 'ACTIVE') {
            ApiError::abort(409, 'SCAN_CODE_INACTIVE', 'Mã scan không ở trạng thái ACTIVE.');
        }

        $item = ItemInstance::query()->where('physical_code_id', $physicalCode->physical_code_id)->first();
        if ($item) {
            return [
                'physical_code' => $physicalCode,
                'type' => $physicalCode->code_type,
                'item' => $item,
                'container' => null,
                'sku_id' => $item->sku_id,
            ];
        }

        $container = Container::query()->where('physical_code_id', $physicalCode->physical_code_id)->first();
        if ($container) {
            if ($container->status !== 'ACTIVE') {
                ApiError::abort(409, 'SCAN_CONTAINER_INACTIVE', 'Container của mã scan không ở trạng thái ACTIVE.');
            }

            return [
                'physical_code' => $physicalCode,
                'type' => $physicalCode->code_type,
                'item' => null,
                'container' => $container,
                'sku_id' => $container->primary_sku_id,
            ];
        }

        ApiError::abort(409, 'SCAN_CODE_UNASSIGNED', 'Mã scan chưa được gán cho item hoặc container.');
    }
}
