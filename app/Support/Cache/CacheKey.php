<?php

namespace App\Support\Cache;

/**
 * Bộ dựng cache key thống nhất cho toàn bộ ứng dụng — không nơi nào được tự ghép
 * chuỗi key thủ công (mục 4/5 yêu cầu caching). Mọi key đều mang tiền tố
 * `wms:{env}:` để tránh đụng độ giữa các môi trường dùng chung 1 Valkey instance.
 */
final class CacheKey
{
    private function __construct() {}

    /**
     * @param  array<int|string, mixed>  $parts  Các thành phần định danh thêm vào key.
     *                                           Key số nguyên -> chỉ lấy giá trị; key chuỗi -> "ten-gia_tri".
     */
    public static function of(string $namespace, array $parts = [], ?int $version = null): string
    {
        $segments = array_merge(
            ['wms', app()->environment()],
            array_filter(explode(':', trim($namespace, ':')), static fn (string $s): bool => $s !== '')
        );

        foreach ($parts as $name => $value) {
            $segments[] = is_int($name)
                ? self::normalizePart($value)
                : $name.'-'.self::normalizePart($value);
        }

        if ($version !== null) {
            $segments[] = 'v'.$version;
        }

        return implode(':', $segments);
    }

    public static function normalizePart(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return self::hashFilters($value);
        }

        // Loại ký tự có thể phá cấu trúc segment (':' là delimiter, khoảng trắng gây rối key).
        return (string) str_replace([':', ' '], ['_', '_'], (string) $value);
    }

    /**
     * Hash ổn định cho mảng filter/query params — sort đệ quy theo key để
     * `?a=1&b=2` và `?b=2&a=1` cho cùng một cache key khi ngữ nghĩa giống nhau.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function hashFilters(array $filters): string
    {
        $normalized = self::normalizeRecursively($filters);

        return substr(hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 20);
    }

    /**
     * Chữ ký warehouse-scope của user hiện tại — PHẢI đưa vào key của bất kỳ dữ liệu
     * nào mà kết quả phụ thuộc scope, để hai user khác scope không bao giờ dùng
     * chung 1 cache entry (chống data leakage, mục 22).
     *
     * @param  array<int, string>|null  $scopeIds  null = không giới hạn (SUPER_ADMIN/role SYSTEM)
     */
    public static function scopeSignature(?array $scopeIds): string
    {
        if ($scopeIds === null) {
            return 'all';
        }

        if ($scopeIds === []) {
            return 'none';
        }

        sort($scopeIds);

        return substr(hash('sha256', implode(',', $scopeIds)), 0, 12);
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    private static function normalizeRecursively(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalizeRecursively($value);
            } elseif (is_string($value)) {
                $data[$key] = trim($value);
            }
        }

        ksort($data);

        return $data;
    }
}
