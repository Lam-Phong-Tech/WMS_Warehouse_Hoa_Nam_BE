<?php

namespace Modules\Warehouse\Services;

/**
 * Parser thuần cho mã HỘP linh kiện nhập kho (scan-driven COMPONENT box).
 *
 * Reference format: <prefix>-<SKU_CODE>-<BOX_SEQ>
 *   Ví dụ: BOX-HN-COMP-BASE-003-0001
 *     -> sku_code = HN-COMP-BASE-003 (SKU linh kiện, sku_type=COMPONENT)
 *     -> box_seq  = 0001
 *
 * Mã hộp tự mang SKU nên khi quét, hệ thống đọc được SKU ngay; SKU chưa có
 * trong kho sẽ được materialize thành COMPONENT-candidate ở tầng scan (giống
 * PRODUCT auto-create của BaM1StockService, GĐ1-REQ-007).
 *
 * Pure function — không query DB, không đọc request; nhờ vậy unit test độc lập.
 * Chuẩn hoá đi theo quy ước sẵn có: trim + uppercase (containers.container_code
 * được strtoupper ở mọi điểm ghi/tra), nên camera gửi nguyên văn hay ScanInput
 * FE uppercase trước đều cho cùng một kết quả parse.
 *
 * SKU code có thể chứa dấu '-' và kết thúc bằng chữ số (vd HN-COMP-BASE-003):
 * regex greedy giữ lại đúng đuôi '-<digits>' cuối cùng làm box_seq, phần còn
 * lại là sku_code — khớp ví dụ chuẩn của nghiệp vụ.
 */
class BoxCodeParser
{
    private readonly string $prefix;

    public function __construct(?string $prefix = null)
    {
        $this->prefix = strtoupper($prefix ?? (string) config('warehouse.box_code.prefix', 'BOX'));
    }

    public function looksLikeBox(string $rawCode): bool
    {
        return $this->parse($rawCode) !== null;
    }

    /**
     * @return array{box_code: string, sku_code: string, box_seq: string}|null
     */
    public function parse(string $rawCode): ?array
    {
        $normalized = strtoupper(trim($rawCode));
        if ($normalized === '') {
            return null;
        }

        $pattern = '/^'.preg_quote($this->prefix, '/').'-(?<sku>.+)-(?<seq>[0-9]+)$/';
        if (preg_match($pattern, $normalized, $matches) !== 1) {
            return null;
        }

        $skuCode = trim((string) $matches['sku']);
        if ($skuCode === '') {
            return null;
        }

        return [
            'box_code' => $normalized,
            'sku_code' => $skuCode,
            'box_seq' => (string) $matches['seq'],
        ];
    }
}
