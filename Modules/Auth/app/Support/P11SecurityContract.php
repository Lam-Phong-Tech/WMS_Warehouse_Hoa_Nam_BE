<?php

namespace Modules\Auth\Support;

/**
 * Additive contract for existing authenticated self-service routes only.
 * This is deliberately NOT an operation store or a revoked-token read path.
 */
final class P11SecurityContract
{
    public const VERSION = 'p11-existing-routes-v1';
    public const MIN_LENGTH = PasswordPolicy::MIN_LENGTH;
    public const MAX_LENGTH = PasswordPolicy::MAX_LENGTH;
    public const WRONG_CURRENT = 'CURRENT_PASSWORD_INCORRECT';

    /** @return array<string, list<string|\Closure>> */
    public static function passwordRules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => [
                'required', 'string', 'min:'.self::MIN_LENGTH, 'max:'.self::MAX_LENGTH,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    if (PasswordPolicy::hasWhitespace($value)) {
                        $fail(PasswordPolicy::MSG_WHITESPACE);

                        return;
                    }
                    if (! PasswordPolicy::hasAllCharacterClasses($value)) {
                        $fail(PasswordPolicy::MSG_COMPLEXITY);
                    }
                },
            ],
            'new_password_confirmation' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /** @return array<string, string> */
    public static function passwordValidationMessages(): array
    {
        return [
            'current_password.required' => PasswordPolicy::MSG_CURRENT_REQUIRED,
            'current_password.string' => PasswordPolicy::MSG_CURRENT_REQUIRED,
            'new_password.required' => PasswordPolicy::MSG_NEW_REQUIRED,
            'new_password.string' => PasswordPolicy::MSG_NEW_REQUIRED,
            'new_password.min' => PasswordPolicy::MSG_MIN,
            'new_password.max' => PasswordPolicy::MSG_MAX,
            'new_password_confirmation.required' => PasswordPolicy::MSG_CONFIRM_REQUIRED,
            'new_password_confirmation.string' => PasswordPolicy::MSG_CONFIRM_REQUIRED,
            'new_password_confirmation.max' => PasswordPolicy::MSG_MAX,
        ];
    }

    /** Never silently truncate input passed to bcrypt. Do not change the configured hasher. */
    public static function maxBytes(string $driver): ?int
    {
        return $driver === 'bcrypt' ? 72 : null;
    }

    /** Called only after type/length/confirmation validation; never returns input values. */
    public static function unsafeEncoding(string $password, string $driver): bool
    {
        $max = self::maxBytes($driver);

        return str_contains($password, "\0") || ($max !== null && strlen($password) > $max);
    }

    public static function validationCode(array $failed): string
    {
        if (isset($failed['new_password_confirmation']['Required'])) {
            return 'PASSWORD_CONFIRMATION_REQUIRED';
        }
        if (isset($failed['new_password'])) {
            return 'PASSWORD_POLICY_VIOLATION';
        }

        return 'PASSWORD_INPUT_INVALID';
    }

    public static function passwordPolicy(string $driver): array
    {
        return [
            'version' => 'p11-password-v2-'.$driver,
            'min_length' => self::MIN_LENGTH,
            'max_length' => self::MAX_LENGTH,
            'length_unit' => 'UNICODE_CHARACTERS',
            'max_utf8_bytes' => self::maxBytes($driver),
            'nul_allowed' => false,
            'confirmation_required' => true,
            'confirmation_field' => 'new_password_confirmation',
            'current_password_required' => true,
            'max_age_days' => (int) config('login_security.password.max_age_days', 90),
            'history_limit' => (int) config('login_security.password.history_limit', 5),
            'system_issued_permanent' => true,
            'composition_rules' => ['UPPERCASE', 'LOWERCASE', 'DIGIT', 'SPECIAL'],
            'input_normalization' => 'EXISTING_REQUEST_MIDDLEWARE',
            'hasher' => $driver,
            'session_policy' => 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED',
            'keep_sessions_supported' => false,
        ];
    }

    public static function contract(string $driver): array
    {
        return [
            'version' => self::VERSION,
            'readiness' => 'PARTIAL_RECEIPT_AND_RECONCILIATION_UNSUPPORTED',
            'password_policy' => self::passwordPolicy($driver),
            'change_password' => [
                'method' => 'POST', 'path' => '/api/v1/auth/change-password',
                'fields' => array_keys(self::passwordRules()),
                'auth' => 'CURRENT_VALID_ACCESS_TOKEN_ACTIVE_ACCOUNT',
                'result_path' => 'meta.auth_result', 'legacy_data' => null,
                'wrong_current_http_status' => 422, 'wrong_current_code' => self::WRONG_CURRENT,
                'wrong_current_ends_session' => false,
            ],
            'sessions' => [
                'method' => 'GET', 'path' => '/api/v1/auth/sessions',
                'items_path' => 'data', 'current_session_id_path' => 'meta.current_session_id',
                'stable_session_id_field' => 'session_id', 'legacy_family_field' => 'auth_session_id',
                // Keep the P10 DELETE target contract. A family UUID is NOT a row UUID.
                'delete_target_field' => 'id', 'delete_target_kind' => 'REFRESH_TOKEN_ROW_UUID',
                'delete_path' => '/api/v1/auth/sessions/{id}',
                'current_family_protected' => true, 'ownership' => 'AUTHENTICATED_USER_ONLY',
                'pagination' => 'PAGE_LIVE_NOT_FROZEN', 'per_page_default' => 20, 'per_page_max' => 100,
                'missing_device_or_location' => null,
            ],
            'operations' => [
                'durable_receipt_supported' => false,
                'idempotency_supported' => false,
                'request_id_semantics' => 'CORRELATION_ONLY_NOT_A_RECEIPT',
                'reconciliation_supported' => false, 'url' => null, 'retention_seconds' => null,
                'revoked_token_can_read' => false,
                'automatic_mutation_retry' => false,
                'readback_proves_original_request' => false,
                'not_found_proves_not_executed' => false,
            ],
            'release' => [
                'production_ready' => false,
                'remaining' => ['DURABLE_RECEIPT', 'IDEMPOTENCY', 'OPERATION_RECONCILIATION', 'DEPLOYMENT_AND_SMOKE'],
                'legacy_access_only_tokens' => 'IAT_SECOND_PRECISION_REQUIRES_RELEASE_REVIEW',
            ],
        ];
    }
}
