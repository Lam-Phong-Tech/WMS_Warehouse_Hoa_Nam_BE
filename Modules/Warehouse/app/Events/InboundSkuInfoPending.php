<?php

namespace Modules\Warehouse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phiếu nhập vừa được ghi nhận nhưng còn SKU mới chưa đủ thông tin (tên thật +
 * ĐVT), nên chưa Ghi nhận nhập kho được.
 *
 * Sinh ra ở kênh Mini App: người quét đứng ngoài quầy, không có màn bổ sung
 * thông tin SKU — người ở web phải biết mà vào làm, nếu không phiếu nằm im ở
 * "Đang quét nhận hàng" mà không ai hay.
 */
class InboundSkuInfoPending
{
    use Dispatchable, SerializesModels;

    /** @param list<string> $skuCodes */
    public function __construct(
        public readonly string $documentId,
        public readonly string $docNo,
        public readonly ?string $warehouseId,
        public readonly ?string $actorId,
        public readonly array $skuCodes,
    ) {}
}
