<?php

namespace Modules\Warehouse\Support;

/** Pure P07 contract rules; no database writes and no native NFC assumptions. */
final class P07NfcPolicy
{
    public const LABELS = ['ASSIGNED' => 'Đã liên kết', 'UNASSIGNED' => 'Chưa liên kết', 'LOCKED' => 'Tạm khóa'];

    public static function tab(?string $status, ?string $itemId): ?string
    {
        return match ($status) {
            'ACTIVE' => $itemId === null || $itemId === '' ? 'UNASSIGNED' : 'ASSIGNED',
            'INACTIVE', 'LOST', 'DAMAGED' => 'LOCKED',
            default => null,
        };
    }

    public static function owns(array $held, array $scope): bool
    {
        return isset($held['actor_id'], $held['auth_session_id'], $held['warehouse_id'])
            && $held['actor_id'] === (string) $scope['actor']->user_id
            && $held['auth_session_id'] === $scope['auth_session_id']
            && $held['warehouse_id'] === $scope['warehouse_id'];
    }

    public static function reservationError(?array $held, array $scope, string $token, int $now): ?string
    {
        if ($held === null || ! isset($held['expires_at']) || ($expiry = strtotime($held['expires_at'])) === false || $expiry <= $now) {
            return 'NFC_RESERVATION_EXPIRED';
        }
        if (! self::owns($held, $scope) || ! isset($held['token']) || ! hash_equals((string) $held['token'], $token)) {
            return 'NFC_RESERVATION_CONTEXT_MISMATCH';
        }

        return null;
    }

    public static function pattern(string $keyword): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($keyword))).'%';
    }
}
