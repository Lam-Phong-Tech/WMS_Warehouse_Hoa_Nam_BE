<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;

/**
 * Luật "SKU đã đủ thông tin để được Ghi nhận nhập kho" — nguồn sự thật DUY NHẤT
 * cho cả ba chỗ dùng: cờ trên từng dòng phiếu (GET /inbound-documents/{id}),
 * endpoint bổ sung thông tin trong phiếu, và guard chặn Post Receipt.
 *
 * Ngưỡng ở đây CỐ Ý nhẹ hơn "SKU chờ điền thông tin" của màn Danh mục SKU (màn
 * đó còn soi Thương hiệu/Nhóm/Sê-ri/Nguồn điện). Người nhận hàng chỉ nắm chắc
 * TÊN đọc được và ĐƠN VỊ TÍNH; bốn danh mục kia phụ thuộc dữ liệu danh mục có
 * sẵn nên bắt đủ ngay tại quầy nhập sẽ kẹt khi chưa có mục phù hợp — chúng để
 * người quản lý danh mục hoàn thiện sau qua bộ lọc "SKU chờ điền thông tin".
 */
final class SkuInboundInfoRule
{
    public const FIELD_NAME = 'sku_name';

    public const FIELD_UNIT = 'unit';

    /**
     * @return list<string> các trường còn thiếu; mảng rỗng = đã đủ thông tin
     */
    public static function missingFields(?string $skuCode, ?string $skuName, ?string $unit): array
    {
        $missing = [];

        $name = trim((string) $skuName);
        $code = trim((string) $skuCode);
        // BaM1StockService::materializeSkuIfMissing() đặt tên tạm BẰNG CHÍNH mã
        // SKU, nên "tên trùng mã" cũng là chưa ai đặt tên thật — không chỉ mỗi
        // trường hợp bỏ trống.
        if ($name === '' || mb_strtoupper($name) === mb_strtoupper($code)) {
            $missing[] = self::FIELD_NAME;
        }

        if (trim((string) $unit) === '') {
            $missing[] = self::FIELD_UNIT;
        }

        return $missing;
    }

    public static function isComplete(?string $skuCode, ?string $skuName, ?string $unit): bool
    {
        return self::missingFields($skuCode, $skuName, $unit) === [];
    }

    /**
     * Các SKU chưa đủ thông tin đang nằm trên một phiếu, đọc bằng ĐÚNG một truy vấn.
     *
     * @return list<array{sku_id:string, sku_code:string, sku_name:string, missing_fields:list<string>}>
     */
    public static function incompleteSkusOfDocument(string $documentId): array
    {
        $rows = DB::table('stock_document_lines as l')
            ->join('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $documentId)
            ->distinct()
            ->get(['s.sku_id', 's.sku_code', 's.sku_name', 's.unit']);

        $incomplete = [];
        foreach ($rows as $row) {
            $missing = self::missingFields($row->sku_code, $row->sku_name, $row->unit);
            if ($missing === []) {
                continue;
            }

            $incomplete[] = [
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'sku_name' => (string) $row->sku_name,
                'missing_fields' => $missing,
            ];
        }

        return $incomplete;
    }

    /**
     * Cùng câu hỏi với incompleteSkusOfDocument() nhưng cho NHIỀU phiếu trong ĐÚNG
     * một truy vấn — dùng cho danh sách phiếu nhập (web) và danh sách phiếu Mini
     * App, nơi hỏi từng phiếu một sẽ thành N+1 ngay ở trang đầu.
     *
     * @param  list<string>  $documentIds
     * @return array<string, list<array{sku_id:string, sku_code:string, sku_name:string, missing_fields:list<string>}>>
     *                                   khoá là document_id; phiếu đã đủ thông tin KHÔNG xuất hiện trong kết quả
     */
    public static function incompleteSkusOfDocuments(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map('strval', $documentIds))));
        if ($documentIds === []) {
            return [];
        }

        $rows = DB::table('stock_document_lines as l')
            ->join('sku as s', 's.sku_id', '=', 'l.product_id')
            ->whereIn('l.stock_document_id', $documentIds)
            ->distinct()
            ->get(['l.stock_document_id', 's.sku_id', 's.sku_code', 's.sku_name', 's.unit']);

        $incomplete = [];
        foreach ($rows as $row) {
            $missing = self::missingFields($row->sku_code, $row->sku_name, $row->unit);
            if ($missing === []) {
                continue;
            }

            $incomplete[(string) $row->stock_document_id][] = [
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'sku_name' => (string) $row->sku_name,
                'missing_fields' => $missing,
            ];
        }

        return $incomplete;
    }
}
