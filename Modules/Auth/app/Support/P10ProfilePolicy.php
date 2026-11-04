<?php

namespace Modules\Auth\Support;

use InvalidArgumentException;

/** Published constraints, not inferred UI permissions or new API operations. */
final class P10ProfilePolicy
{
    public const EDITABLE = ['display_name', 'phone', 'email'];

    public static function version(string $header): int
    {
        $header = trim($header);
        if (! preg_match('/^(?:"([1-9][0-9]{0,9})"|([1-9][0-9]{0,9}))$/D', $header, $match)) {
            throw new InvalidArgumentException('INVALID_VERSION');
        }
        $value = (int) (($match[1] ?? '') !== '' ? $match[1] : $match[2]);
        if ($value >= 2147483647) {
            throw new InvalidArgumentException('INVALID_VERSION');
        }

        return $value;
    }

    public static function unsupportedFields(array $body): array
    {
        return array_values(array_diff(array_keys($body), self::EDITABLE));
    }

    public static function family(?string $family, string $rowId): string
    {
        return strtolower($family !== null && $family !== '' ? $family : $rowId);
    }

    public static function contract(): array
    {
        return [
            'version' => 'auth-profile-p10-v1',
            'editable_fields' => self::EDITABLE,
            'ui_name_field' => 'display_name',
            'nickname_supported' => false,
            'display_name' => ['nullable' => false, 'trim' => true, 'min_length' => 1, 'max_length' => 255],
            'phone' => ['nullable' => true, 'pattern' => '^0[0-9]{9}$'],
            'if_match_required' => true,
            'etag_scope' => 'USER_VERSION_NOT_AUTHORIZATION_SNAPSHOT',
            'email' => ['nullable' => false, 'current_password_required_when_changed' => true, 'unique' => true],
            'avatar' => ['supported' => true, 'max_bytes' => 2097152, 'mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
                'private_read' => 'GET /api/v1/auth/me/avatar', 'upload' => 'POST /api/v1/auth/me/avatar',
                'atomic_profile_fields' => true, 'if_match_scope' => 'USER_VERSION', 'request_receipt_supported' => false],
            'ui_permission_groups' => ['status' => 'BA_MAPPING_REQUIRED', 'groups' => null],
            'authorization_source' => 'SERVER_ROLES_PERMISSIONS_WAREHOUSE_SCOPE',
            'request_reconciliation' => [
                'supported' => false, 'request_id_is_receipt' => false,
                'profile_readback' => 'GET /api/v1/auth/me',
                'session_readback' => 'GET /api/v1/auth/sessions',
                'readback_proves_original_request' => false,
                'automatic_mutation_retry' => false,
            ],
            'password_session_policy' => 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED',
            'logout_scope' => 'CURRENT_AUTH_SESSION_FAMILY_OR_ACCESS_ONLY',
            'logout_ends_work_shift' => false,
            'logout_deletes_business_drafts' => false,
            'end_shift' => ['supported_by_p10' => false, 'dependency' => 'P14'],
        ];
    }
}
