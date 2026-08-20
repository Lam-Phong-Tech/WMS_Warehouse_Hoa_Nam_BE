<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Support\ProductQrPayload;

/**
 * Parser authoritative cho PRODUCT Barcode/QR composite (Change Pack QR/SKU
 * 20/08/2026, DEC-QR-001).
 *
 * Reference format V1: HN1|SKU=<sku_code>|ITEM=<item_unique>[|SER=<serial>]
 *
 * Pure function — không query DB, không đọc request; nhờ vậy unit test độc lập
 * và có thể thay adapter khi nhà cung cấp chốt format thật (DEC-QR-01). Toàn bộ
 * chuẩn hoá đi theo quy ước sẵn có của hệ thống: trim + uppercase (physical_codes
 * .code_value được strtoupper ở mọi điểm ghi/tra), nên camera gửi nguyên văn hay
 * ScanInput FE có uppercase trước đều cho cùng một kết quả parse.
 */
class ProductQrPayloadParser
{
    /** Trần độ dài từng thành phần — khớp trần cột chặt nhất giữa hai driver
     * (physical_codes.code_value varchar(100) trên sqlite, serial_number varchar(100)). */
    public const MAX_COMPONENT_LENGTH = 100;

    private readonly string $prefix;

    /** @var list<int> */
    private readonly array $supportedVersions;

    public function __construct(?string $prefix = null, ?array $supportedVersions = null)
    {
        $this->prefix = strtoupper($prefix ?? (string) config('warehouse.product_qr.composite_prefix', 'HN'));
        $this->supportedVersions = array_map('intval', $supportedVersions ?? (array) config('warehouse.product_qr.supported_versions', [1]));
    }

    /**
     * Một payload được coi là "có ý định composite" khi segment đầu khớp
     * <prefix><digits> và có ký tự '|' — từ đó trở đi mọi lỗi cấu trúc là
     * INVALID_PAYLOAD chứ không được rơi thầm về nhánh legacy.
     */
    public function looksComposite(string $rawPayload): bool
    {
        $normalized = strtoupper(trim($rawPayload));

        return preg_match('/^'.preg_quote($this->prefix, '/').'\d+\|/', $normalized) === 1;
    }

    public function parse(string $rawPayload): ProductQrPayload
    {
        $raw = trim($rawPayload);
        $normalized = strtoupper($raw);

        if (! $this->looksComposite($raw)) {
            if ($normalized === '') {
                return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT);
            }

            return ProductQrPayload::legacy($raw, $normalized);
        }

        $segments = explode('|', $normalized);
        $header = array_shift($segments);
        if (preg_match('/^'.preg_quote($this->prefix, '/').'(\d+)$/', (string) $header, $m) !== 1) {
            return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT);
        }

        $version = (int) $m[1];
        if (! in_array($version, $this->supportedVersions, true)) {
            return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_UNSUPPORTED_VERSION, $version);
        }

        $fields = [];
        foreach ($segments as $segment) {
            // Cho phép segment rỗng do dán thừa '|' cuối chuỗi? Không — cấu trúc
            // phải chặt để không đoán mò (Change Pack §9: không fallback đoán).
            if (! str_contains($segment, '=')) {
                return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT, $version);
            }
            [$key, $value] = explode('=', $segment, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || $value === '' || isset($fields[$key])) {
                return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT, $version);
            }
            if (! in_array($key, ['SKU', 'ITEM', 'SER'], true)) {
                return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT, $version);
            }
            if (mb_strlen($value) > self::MAX_COMPONENT_LENGTH) {
                return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT, $version);
            }
            $fields[$key] = $value;
        }

        if (! isset($fields['SKU'], $fields['ITEM'])) {
            return ProductQrPayload::invalid($raw, ProductQrPayload::STATUS_INVALID_FORMAT, $version);
        }

        return ProductQrPayload::compositeV1(
            rawPayload: $raw,
            skuCode: $fields['SKU'],
            itemUnique: $fields['ITEM'],
            serialNumber: $fields['SER'] ?? null,
        );
    }
}
