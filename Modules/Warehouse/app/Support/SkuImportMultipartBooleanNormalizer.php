<?php

namespace Modules\Warehouse\Support;

/**
 * Normalizes browser multipart boolean values without weakening validation.
 *
 * FormData serializes booleans as strings, while Laravel's `boolean` validator
 * intentionally accepts true/false, 1/0 and "1"/"0" but not the literal strings
 * "true"/"false". Keep arbitrary values untouched so invalid input still fails
 * validation with HTTP 422 instead of being silently coerced to false.
 */
final class SkuImportMultipartBooleanNormalizer
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['update_existing', 'dry_run'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $input[$field] = self::normalizeValue($input[$field]);
        }

        return $input;
    }

    public static function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return match (strtolower(trim($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => $value,
        };
    }
}
