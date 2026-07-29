<?php

namespace Modules\Warehouse\Exceptions;

use App\Support\ApiError;

class WarehouseException
{
    /** @return never */
    public static function insufficientStock(string $productCode): void
    {
        ApiError::abort(422, 'INSUFFICIENT_STOCK', "Tồn kho không đủ cho sản phẩm {$productCode}.");
    }

    /** @return never */
    public static function invalidStatus(string $message): void
    {
        ApiError::abort(409, 'STATE_CONFLICT', $message);
    }

    /** @return never */
    public static function versionConflict(): void
    {
        ApiError::abort(409, 'STATE_CONFLICT', 'Dữ liệu tồn kho đã bị thay đổi bởi thao tác khác, vui lòng thử lại.');
    }
}
