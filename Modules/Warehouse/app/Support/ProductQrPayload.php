<?php

namespace Modules\Warehouse\Support;

/**
 * Kết quả parse một raw scan payload của PRODUCT Barcode/QR (Change Pack QR/SKU
 * 20/08/2026). Bất biến sau khi tạo; mọi field đã được trim/uppercase theo đúng
 * quy ước chuẩn hoá của physical_codes.code_value (strtoupper khi ghi lẫn khi tra).
 */
final class ProductQrPayload
{
    public const FORMAT_COMPOSITE_V1 = 'COMPOSITE_V1';

    public const FORMAT_LEGACY_CODE_VALUE = 'LEGACY_CODE_VALUE';

    public const STATUS_VALID = 'VALID';

    public const STATUS_INVALID_FORMAT = 'INVALID_FORMAT';

    public const STATUS_UNSUPPORTED_VERSION = 'UNSUPPORTED_VERSION';

    private function __construct(
        public readonly string $rawPayload,
        public readonly string $format,
        public readonly string $parseStatus,
        public readonly ?int $payloadVersion,
        public readonly ?string $skuCode,
        public readonly ?string $itemUnique,
        public readonly ?string $serialNumber,
        public readonly ?string $normalizedCodeValue,
    ) {}

    public static function compositeV1(
        string $rawPayload,
        string $skuCode,
        string $itemUnique,
        ?string $serialNumber
    ): self {
        return new self(
            rawPayload: $rawPayload,
            format: self::FORMAT_COMPOSITE_V1,
            parseStatus: self::STATUS_VALID,
            payloadVersion: 1,
            skuCode: $skuCode,
            itemUnique: $itemUnique,
            serialNumber: $serialNumber,
            normalizedCodeValue: $itemUnique,
        );
    }

    public static function legacy(string $rawPayload, string $normalizedCodeValue): self
    {
        return new self(
            rawPayload: $rawPayload,
            format: self::FORMAT_LEGACY_CODE_VALUE,
            parseStatus: self::STATUS_VALID,
            payloadVersion: null,
            skuCode: null,
            itemUnique: null,
            serialNumber: null,
            normalizedCodeValue: $normalizedCodeValue,
        );
    }

    public static function invalid(string $rawPayload, string $parseStatus, ?int $payloadVersion = null): self
    {
        return new self(
            rawPayload: $rawPayload,
            format: self::FORMAT_COMPOSITE_V1,
            parseStatus: $parseStatus,
            payloadVersion: $payloadVersion,
            skuCode: null,
            itemUnique: null,
            serialNumber: null,
            normalizedCodeValue: null,
        );
    }

    public function isComposite(): bool
    {
        return $this->format === self::FORMAT_COMPOSITE_V1 && $this->parseStatus === self::STATUS_VALID;
    }

    public function isLegacy(): bool
    {
        return $this->format === self::FORMAT_LEGACY_CODE_VALUE;
    }

    public function isValid(): bool
    {
        return $this->parseStatus === self::STATUS_VALID;
    }
}
