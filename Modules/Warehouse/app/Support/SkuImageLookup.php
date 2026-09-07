<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tra ẢNH ĐẠI DIỆN của một loạt SKU trong ĐÚNG MỘT truy vấn.
 *
 * Ảnh sản phẩm sống ở `pv_product_images` (mặt App PV), nhưng người dùng kho cũng
 * cần nhìn thấy ảnh ở màn "Danh sách SKU" và modal chi tiết SKU — không có ảnh thì
 * 992 mã DongCheng chỉ là một bảng chữ, rất khó nhận diện hàng.
 *
 * Cố ý KHÔNG nhét cột này vào view `sku`: view đó bị đọc ở hầu hết đường nóng
 * (quét mã, phiếu nhập/xuất, báo cáo, cache) nên thêm một subquery tương quan sẽ
 * làm chậm cả những nơi không cần ảnh. Ở đây chỉ controller nào thật sự hiển thị
 * ảnh mới gọi, và luôn gọi theo lô id của đúng trang đang xem (không N+1).
 */
final class SkuImageLookup
{
    private function __construct() {}

    /**
     * @param  list<string>  $skuIds
     * @return array<string,string> sku_id => image_url (chỉ SKU nào có ảnh ACTIVE)
     */
    public static function forSkuIds(array $skuIds): array
    {
        $skuIds = array_values(array_unique(array_filter($skuIds)));
        if ($skuIds === [] || ! Schema::hasTable('pv_product_images')) {
            return [];
        }

        $profile = PvRelations::profile();

        return DB::table('pv_product_images as i')
            ->join($profile['table'].' as p', 'p.'.$profile['key'], '=', 'i.profile_id')
            ->whereIn('p.'.$profile['sku'], $skuIds)
            ->where('i.status', 'ACTIVE')
            // Ảnh chính trước, sau đó theo thứ tự sắp xếp — pluck giữ bản ghi CUỐI
            // cùng cho mỗi khoá, nên sắp xếp NGƯỢC để dòng ưu tiên nhất về cuối.
            ->orderByDesc('i.sort_order')
            ->orderBy('i.is_primary')
            ->pluck('i.image_url', 'p.'.$profile['sku'])
            ->map(fn ($url) => (string) $url)
            ->all();
    }

    /**
     * Gắn `image_url` vào từng dòng của một collection SKU (Eloquent model hoặc
     * mảng — repository có cache nên trả về cả hai dạng).
     *
     * @param  iterable<int,mixed>  $rows
     * @return list<mixed>
     */
    public static function attach(iterable $rows): array
    {
        $items = is_array($rows) ? $rows : iterator_to_array($rows);
        $ids = [];
        foreach ($items as $row) {
            $id = self::skuIdOf($row);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $map = self::forSkuIds($ids);
        foreach ($items as $index => $row) {
            $id = self::skuIdOf($row);
            $url = $id !== null ? ($map[$id] ?? null) : null;
            if (is_array($row)) {
                $row['image_url'] = $url;
                $items[$index] = $row;
            } elseif (is_object($row)) {
                $row->image_url = $url;
            }
        }

        return array_values($items);
    }

    private static function skuIdOf(mixed $row): ?string
    {
        if (is_array($row)) {
            $id = $row['sku_id'] ?? $row['id'] ?? null;

            return $id !== null ? (string) $id : null;
        }
        if (is_object($row)) {
            $id = $row->sku_id ?? $row->id ?? null;

            return $id !== null ? (string) $id : null;
        }

        return null;
    }
}
