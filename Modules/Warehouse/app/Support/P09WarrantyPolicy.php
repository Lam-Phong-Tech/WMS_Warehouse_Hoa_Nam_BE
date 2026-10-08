<?php

namespace Modules\Warehouse\Support;

use InvalidArgumentException;

/** Pure P09 transport rules; the canonical controller still owns all transitions. */
final class P09WarrantyPolicy
{
    public const STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];
    public const CREATE_FIELDS = ['warehouse_id', 'warranty_case_code', 'case_no', 'item_id', 'item_code', 'defect_id', 'defect_ids', 'defect_code', 'estimated_completion_date', 'customer_name', 'customer_phone', 'customer_address', 'purchase_date', 'description', 'reported_defect', 'accessories_received', 'received_condition', 'missing_code_reason', 'manual_product_description'];
    public const UPDATE_FIELDS = ['warehouse_id', 'status', 'condition_status', 'description', 'note', 'confirmed_defect', 'accessories_received', 'received_condition', 'cancel_reason', 'defect_id', 'defect_ids', 'defect_code', 'estimated_completion_date', 'customer_name', 'customer_phone', 'customer_address', 'purchase_date'];
    public const FREE_TEXT = ['description', 'reported_defect', 'confirmed_defect', 'received_condition', 'condition_status', 'accessories_received', 'missing_code_reason', 'manual_product_description', 'cancel_reason'];

    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }

    public static function digest(array $data, string $secret): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('P09_KEY_UNAVAILABLE');
        }
        return hash_hmac('sha256', json_encode(self::canonical($data), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $secret);
    }

    public static function version(string $header): int
    {
        if (! preg_match('/^(?:"([1-9][0-9]{0,9})"|([1-9][0-9]{0,9}))$/D', trim($header), $m)) {
            throw new InvalidArgumentException('INVALID_VERSION');
        }
        return (int) ($m[1] !== '' ? $m[1] : $m[2]);
    }

    public static function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)).'%';
    }

    public static function cursorValid(array $cursor, string $scope, int $now): bool
    {
        return ($cursor['v'] ?? null) === 1 && is_string($cursor['scope'] ?? null)
            && hash_equals($scope, $cursor['scope']) && is_int($cursor['expires'] ?? null)
            && $cursor['expires'] > $now && is_string($cursor['time'] ?? null)
            && is_string($cursor['id'] ?? null);
    }

    public static function safeCase(array $data, bool $pii): array
    {
        if (! $pii) {
            foreach (self::FREE_TEXT as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = null;
                }
            }
        }
        $data['free_text_redacted'] = ! $pii;
        return $data;
    }
}
