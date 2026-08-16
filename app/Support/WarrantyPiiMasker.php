<?php

namespace App\Support;

/**
 * SRS v1.12 §13.8 Privacy Contract + NFR-SRS-015.
 *
 * Shape masking bắt buộc khi actor thiếu warranty.pii.view:
 * - Customer name: "N*** H***" — giữ chữ cái đầu MỖI từ.
 * - Customer phone: "******9922" — chỉ lộ 3 số cuối (NFR-SRS-015).
 * - Customer address: ẩn hoặc rút gọn — giữ phần địa giới cuối (quận/tỉnh).
 */
final class WarrantyPiiMasker
{
    public static function name(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $value) ?: [];

        return implode(' ', array_map(
            static fn (string $word): string => mb_substr($word, 0, 1).'***',
            $words
        ));
    }

    public static function phone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = mb_strlen($value);
        if ($length <= 3) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 3).mb_substr($value, -3);
    }

    public static function address(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Rút gọn về phần địa giới cuối cùng; số nhà/đường bị ẩn hoàn toàn.
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $part): bool => $part !== ''));
        if (count($parts) <= 1) {
            return '***';
        }

        return '***, '.end($parts);
    }
}
