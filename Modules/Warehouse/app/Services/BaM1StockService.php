<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * BA 2026-08-25 adapter over the canonical diagnostic stock service.
 * Unknown SKU embedded in a valid PRODUCT QR — hoặc trong mã HỘP linh kiện
 * BOX-<SKU>-<seq> — được materialize lazily trước khi luồng scan-driven resolve
 * identity/line.
 */
class BaM1StockService extends DiagnosticCurrentBaselineStockService
{
    public function scanStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId
    ): array {
        if ($documentType === 'receipt' && ! empty($payload['code_value'])) {
            $code = (string) $payload['code_value'];

            // Mã HỘP linh kiện tự mang SKU: quét hộp là biết SKU COMPONENT ngay;
            // SKU chưa có -> tạo COMPONENT-candidate (đếm theo hộp, không serial).
            $box = app(BoxCodeParser::class)->parse($code);
            if ($box !== null) {
                $this->materializeSkuIfMissing($box['sku_code'], 'COMPONENT', false, $actorId);
            } else {
                // PRODUCT QR composite (mã item, mỗi cái một mã): loại của SKU MỚI
                // chưa xác định được từ mã, nên KHÔNG tạo im lặng. Người dùng phải
                // chọn Sản phẩm hay Linh kiện ở lần quét đầu; FE bắt lỗi này để hỏi
                // rồi gửi lại kèm new_sku_type.
                $parsed = app(ProductQrPayloadParser::class)->parse($code);
                if ($parsed->isComposite() && $parsed->skuCode !== null) {
                    $skuCode = strtoupper(trim((string) $parsed->skuCode));
                    if (! DB::table('sku')->where('sku_code', $skuCode)->exists()) {
                        $chosenType = strtoupper(trim((string) ($payload['new_sku_type'] ?? '')));
                        if ($chosenType === '') {
                            ApiError::abort(
                                422,
                                'NEW_SKU_TYPE_REQUIRED',
                                'SKU chưa có trong hệ thống — hãy chọn đây là Sản phẩm hay Linh kiện trước khi ghi nhận.',
                                ['sku_code' => $skuCode]
                            );
                        }
                        if (! in_array($chosenType, ['PRODUCT', 'COMPONENT'], true)) {
                            ApiError::abort(422, 'INVALID_SKU_TYPE', 'Loại SKU phải là PRODUCT hoặc COMPONENT.', [
                                'sku_type' => $chosenType,
                            ]);
                        }
                        // Linh kiện quét theo mã item = linh kiện dán mã từng cái
                        // (serial-tracked, +1 mỗi lần, không cần hộp). Sản phẩm giữ
                        // mặc định item-tracked theo sku_type=PRODUCT.
                        $isSerial = $chosenType === 'COMPONENT' ? true : null;
                        $this->materializeSkuIfMissing($skuCode, $chosenType, $isSerial, $actorId);
                    }
                }
            }
        }

        return parent::scanStockDocument($documentId, $documentType, $payload, $actorId);
    }

    /**
     * Tạo SKU tối thiểu nếu sku_code chưa tồn tại (tên tạm = sku_code, bổ sung
     * thông tin sau ở màn quản trị SKU). Chống race unique sku_code như bản gốc.
     */
    private function materializeSkuIfMissing(
        string $skuCode,
        string $skuType,
        ?bool $isSerialTracked,
        ?string $actorId
    ): void {
        $skuCode = strtoupper(trim($skuCode));
        if ($skuCode === '' || DB::table('sku')->where('sku_code', $skuCode)->exists()) {
            return;
        }

        $attributes = [
            'sku_code' => $skuCode,
            'sku_name' => $skuCode,
            'sku_type' => $skuType,
            'status' => 'ACTIVE',
        ];
        if ($isSerialTracked !== null) {
            $attributes['is_serial_tracked'] = $isSerialTracked;
        }

        try {
            app(SkuAggregateService::class)->create(
                $attributes,
                $actorId,
                request()?->attributes->get('request_id')
            );
        } catch (Throwable $exception) {
            // Concurrent first scans of the same unseen SKU can race on the unique
            // sku_code constraint. If the other transaction won, continue with the
            // canonical resolver; otherwise keep the original failure visible.
            if (! DB::table('sku')->where('sku_code', $skuCode)->exists()) {
                throw $exception;
            }
        }
    }
}
