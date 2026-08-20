<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\ProductQrPayload;

/**
 * Resolver authoritative cho identity trong composite PRODUCT QR (DEC-QR-001).
 *
 * Dùng chung cho inbound/outbound/classify/trace/warranty để mọi ngữ cảnh nhìn
 * cùng một sự thật: SKU resolve theo sku_code embedded, item resolve theo
 * item_unique đã chuẩn hoá (physical_codes.code_value trước, serial_number sau —
 * cùng thứ tự với resolveWarrantyItem hiện hành).
 */
class ProductIdentityResolver
{
    /**
     * @return object Row của bảng sku; abort 422 SKU_NOT_FOUND / SKU_INACTIVE khi
     *                không resolve được (Change Pack §9: không tự tạo SKU).
     */
    public function requireActiveSkuByCode(string $skuCode): object
    {
        $sku = DB::table('sku')->where('sku_code', $skuCode)->first()
            ?? DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [strtoupper($skuCode)])->first();

        if ($sku === null) {
            ApiError::abort(422, 'SKU_NOT_FOUND', 'SKU trong QR không tồn tại trong master data.', [
                'sku_code' => $skuCode,
            ]);
        }
        if (strtoupper((string) $sku->status) !== 'ACTIVE') {
            ApiError::abort(422, 'SKU_INACTIVE', 'SKU trong QR đang không ACTIVE.', [
                'sku_code' => $skuCode,
                'status' => (string) $sku->status,
            ]);
        }

        return $sku;
    }

    /**
     * Tìm item đã đăng ký theo normalized item_unique. Trả null khi chưa tồn tại
     * (inbound coi là new item candidate; outbound/warranty/trace coi là
     * UNKNOWN_CODE). Không lock — caller cần lock thì tự lock lại theo item_id.
     */
    public function findItemByUnique(string $itemUnique): ?object
    {
        $normalized = strtoupper(trim($itemUnique));
        if ($normalized === '') {
            return null;
        }

        $physical = DB::table('physical_codes')
            ->where('code_value', $normalized)
            ->where('status', 'ACTIVE')
            ->first();
        if ($physical !== null) {
            $itemId = ($physical->assigned_object_type ?? null) === 'ITEM' && ! empty($physical->assigned_object_id)
                ? (string) $physical->assigned_object_id
                : DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->value('item_id');
            if ($itemId !== null) {
                $item = DB::table('item_instances')->where('item_id', $itemId)->first();
                if ($item !== null) {
                    return $item;
                }
            }
        }

        return DB::table('item_instances')->where('serial_number', $normalized)->first();
    }

    /**
     * item_unique đã bị chiếm bởi BẤT KỲ bản ghi nào (kể cả physical code không
     * ACTIVE) — dùng để chặn đăng ký candidate đè lên identity cũ.
     */
    public function identityOccupied(string $itemUnique): bool
    {
        $normalized = strtoupper(trim($itemUnique));
        if ($normalized === '') {
            return false;
        }

        return DB::table('physical_codes')->where('code_value', $normalized)->exists()
            || DB::table('item_instances')->where('serial_number', $normalized)->exists();
    }

    /**
     * Cross-check SKU embedded với SKU thật của item đã resolve (Change Pack
     * §6.2 bước 12 / AC-QR-05): lệch là WRONG_SKU, không cho override tay.
     */
    public function assertItemMatchesSku(object $item, object $sku, ProductQrPayload $payload): void
    {
        if ((string) $item->sku_id !== (string) $sku->sku_id) {
            ApiError::abort(422, 'WRONG_SKU', 'SKU embedded trong QR không khớp SKU của item đã đăng ký.', [
                'sku_code' => $payload->skuCode,
                'item_unique' => $payload->itemUnique,
            ]);
        }
    }
}
