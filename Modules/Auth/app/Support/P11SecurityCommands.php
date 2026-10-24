<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Opt-in native receipts. Never stores credentials or replays an uncertain command. */
final class P11SecurityCommands
{
    public const CONTRACT = 'p11-security-command-v1';

    public const HEADER = 'X-Security-Command-Contract';

    public const TABLE = 'p11_security_commands';

    public function legacy(Request $request, Closure $command): JsonResponse
    {
        if (! Schema::hasTable(self::TABLE)) {
            return $command();
        }

        return DB::transaction(function () use ($request, $command): JsonResponse {
            $presented = $request->user() ?? auth('api')->user();
            if ($presented instanceof User) {
                $actor = User::whereKey($presented->user_id)->lockForUpdate()->first();
                if ($actor === null || $actor->status !== 'ACTIVE') {
                    ApiError::abort(403, 'ACCOUNT_DISABLED', 'Account is no longer active.');
                }
                $family = P10SessionFamily::current($actor);
                if (DB::table(self::TABLE)->where('actor_id', $actor->user_id)->where('state', 'PENDING')->exists()) {
                    ApiError::abort(409, 'SECURITY_COMMAND_UNKNOWN', 'Removing the protocol header cannot bypass an unresolved command; reconcile its original key.');
                }
                if ($family !== null && DB::table(self::TABLE)->where('actor_id', $actor->user_id)->where('auth_session_id', $family)->exists()) {
                    ApiError::abort(428, 'SECURITY_COMMAND_CONTRACT_REQUIRED', 'This family adopted native receipts; do not downgrade to a legacy command.');
                }
            }

            return $command();
        });
    }

    public function execute(Request $request, string $operation, ?string $target, Closure $command): JsonResponse
    {
        $this->gate($request);
        $key = strtolower(trim((string) $request->header('Idempotency-Key')));
        $trace = strtolower(trim((string) $request->header('X-Request-ID')));
        if (! Str::isUuid($key) || ! Str::isUuid($trace)
            || $request->attributes->get('api.client_request_uuid', true) !== true) {
            ApiError::abort(428, 'SECURITY_COMMAND_IDENTITY_REQUIRED', 'Keep original UUID Idempotency-Key and X-Request-ID.');
        }
        if (! in_array($operation, ['CHANGE_PASSWORD', 'REVOKE_SESSION', 'LOGOUT'], true)) {
            throw new RuntimeException('P11_OPERATION_UNSUPPORTED');
        }
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        ksort($body);
        $hash = hash_hmac('sha256', json_encode([$operation, $target, $body], JSON_THROW_ON_ERROR), (string) config('app.key'));
        [$row, $created] = DB::transaction(function () use ($request, $operation, $target, $key, $trace, $hash): array {
            [$actor, $family] = $this->actor($request, true);
            $row = DB::table(self::TABLE)->where('actor_id', $actor->user_id)->where('operation_key', $key)->first();
            if ($row !== null) {
                if ($row->auth_session_id !== $family) {
                    ApiError::abort(409, 'SECURITY_COMMAND_CHECK_ONLY', 'New session must verify MFA and GET the original key, not replay the command.');
                }
                if (! hash_equals($row->request_hash, $hash) || $row->request_id !== $trace) {
                    ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Original key/body/target/request identity must not change.');
                }

                return [$row, false];
            }
            if (DB::table(self::TABLE)->where('actor_id', $actor->user_id)->where('state', 'PENDING')->exists()) {
                ApiError::abort(409, 'SECURITY_COMMAND_UNKNOWN', 'An unresolved security command requires reconciliation or audited support, not a new key.');
            }
            $binding = $this->factor($actor);
            DB::table(self::TABLE)->insert(['actor_id' => $actor->user_id, 'operation_key' => $key, 'auth_session_id' => $family,
                'request_id' => $trace, 'operation' => $operation, 'target_id' => $target ?? $actor->user_id,
                'request_hash' => $hash, 'mfa_binding' => $binding, 'state' => 'PENDING', 'created_at' => now()->utc()->toISOString()]);

            return [$this->row($actor->user_id, $key), true];
        });
        if (! $created) {
            return $this->reply($row, false, true);
        }
        try {
            $row = DB::transaction(function () use ($request, $row, $command): object {
                [$actor, $family] = $this->actor($request, true);
                if ($family !== $row->auth_session_id || ! hash_equals($row->mfa_binding, $this->factor($actor))) {
                    ApiError::abort(409, 'SECURITY_COMMAND_CONTEXT_CHANGED', 'Session or pre-existing factor changed.');
                }
                $response = $command();
                if (! $response->isSuccessful() || $response->getStatusCode() === 202) {
                    throw new HttpResponseException($response);
                }
                $result = $this->result($row, $response->getData(true));
                DB::table(self::TABLE)->where('actor_id', $row->actor_id)->where('operation_key', $row->operation_key)
                    ->where('state', 'PENDING')->update(['state' => 'SUCCEEDED', 'http_status' => $response->getStatusCode(),
                        'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => now()->utc()->toISOString()]);

                return $this->row($row->actor_id, $row->operation_key);
            });
        } catch (Throwable $error) {
            // A failed commit acknowledgement is not proof of rollback.
            try {
                $stored = $this->row($row->actor_id, $row->operation_key);
                if ($stored->state !== 'PENDING') {
                    return $this->reply($stored, false, true);
                }
                $http = $error instanceof HttpResponseException ? $error->getResponse()->getStatusCode()
                    : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 0);
                if ($http >= 400 && $http < 500) {
                    $body = $error instanceof HttpResponseException && $error->getResponse() instanceof JsonResponse
                        ? $error->getResponse()->getData(true) : [];
                    $code = $body['error_code'] ?? 'SECURITY_COMMAND_REJECTED';
                    if (! is_string($code) || ! preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $code)) {
                        $code = 'SECURITY_COMMAND_REJECTED';
                    }
                    DB::table(self::TABLE)->where('actor_id', $row->actor_id)->where('operation_key', $row->operation_key)
                        ->where('state', 'PENDING')->update(['state' => 'REJECTED', 'http_status' => $http,
                            'error_code' => $code, 'completed_at' => now()->utc()->toISOString()]);
                }
                $row = $this->row($row->actor_id, $row->operation_key);
            } catch (Throwable) {
                // Preserve the precommitted intent; no SQL/body/token in the response.
            }
        }

        return $this->reply($row, false, false);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->gate($request);
        $this->readInput($request, $key);

        return DB::transaction(function () use ($request, $key): JsonResponse {
            [$actor, $family] = $this->actor($request, true);
            $row = DB::table(self::TABLE)->where('actor_id', $actor->user_id)->where('operation_key', strtolower($key))->first();
            if ($row === null) {
                return response()->json(['success' => true, 'data' => ['request_key' => strtolower($key), 'state' => 'UNKNOWN',
                    'request_state' => 'NOT_FOUND', 'retry_policy' => 'CHECK_ONLY', 'support_resolution_required' => true],
                    'meta' => ['contract' => self::CONTRACT]]);
            }
            if ($row->auth_session_id !== $family) {
                $proof = DB::table('p11_security_reconciliations')->where('actor_id', $actor->user_id)
                    ->where('operation_key', strtolower($key))->where('auth_session_id', $family)->first();
                if ($proof === null || Carbon::parse($proof->expires_at, 'UTC')->lte(now())
                    || ($proof->credential_epoch === null ? null : (int) $proof->credential_epoch) !== $actor->passwordChangedAtEpoch()
                    || ! hash_equals($proof->credential_binding, $this->credential($actor))
                    || ! hash_equals($row->mfa_binding, $proof->mfa_binding)
                    || ($binding = $this->factorValue($actor)) === null || ! hash_equals($row->mfa_binding, $binding)) {
                    ApiError::abort(403, 'SECURITY_RECONCILIATION_MFA_REQUIRED', 'Reauthenticate with password and the pre-existing MFA factor before reading this original key.');
                }
            }

            return $this->reply($row, true, false);
        });
    }

    public function verify(Request $request, string $key): JsonResponse
    {
        $this->gate($request);
        Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if ($request->query() !== [] || $request->allFiles() !== [] || array_diff(array_keys($body), ['current_password', 'code']) !== []) {
            ApiError::abort(422, 'SECURITY_RECONCILIATION_INPUT_INVALID', 'Only current_password and code are accepted; no caller/session takeover fields.');
        }
        $input = Validator::make($body, ['current_password' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:100']])->validate();
        // Return failures outside the transaction so MFA attempt/replay counters and audit persist.
        $result = DB::transaction(function () use ($request, $key, $input): array {
            [$actor, $family] = $this->actor($request, true);
            $row = DB::table(self::TABLE)->where('actor_id', $actor->user_id)->where('operation_key', strtolower($key))->first();
            if ($row === null) {
                return $this->verificationDenied($request, $actor, $key, 'SECURITY_SUPPORT_RESOLUTION_REQUIRED');
            }
            $binding = $this->factorValue($actor);
            if ($binding === null || ! hash_equals($row->mfa_binding, $binding)) {
                return $this->verificationDenied($request, $actor, $key, 'SECURITY_MFA_FACTOR_CHANGED');
            }
            $valid = Hash::check($input['current_password'], $actor->password_hash)
                && app(MfaService::class)->verifySecondFactor($actor, $input['code']) !== null;
            AuditLogger::record(action: $valid ? 'SECURITY_RECONCILIATION_VERIFIED' : 'SECURITY_RECONCILIATION_DENIED',
                objectType: 'p11_security_command', objectId: $row->operation_key, actorId: $actor->user_id,
                requestId: $request->attributes->get('request_id'), afterData: ['auth_session_id' => $family, 'command_replayed' => false],
                result: $valid ? 'SUCCESS' : 'DENIED');
            if (! $valid) {
                return ['error' => 'SECURITY_REAUTHENTICATION_FAILED'];
            }
            $expires = now()->utc()->addSeconds((int) config('p11.reconciliation_assurance_seconds', 300));
            DB::table('p11_security_reconciliations')->updateOrInsert(['actor_id' => $actor->user_id,
                'operation_key' => strtolower($key), 'auth_session_id' => $family], ['mfa_binding' => $row->mfa_binding,
                    'credential_epoch' => $actor->passwordChangedAtEpoch(), 'credential_binding' => $this->credential($actor),
                    'verified_at' => now()->utc()->toISOString(), 'expires_at' => $expires->toISOString()]);

            return ['request_key' => strtolower($key), 'verified' => true, 'expires_at' => $expires->toISOString(),
                'retry_policy' => 'CHECK_ONLY', 'command_replayed' => false];
        });
        if (isset($result['error'])) {
            ApiError::abort(403, $result['error'], 'Verification failed; do not replay the original command or change its identity.');
        }

        return response()->json(['success' => true, 'data' => $result, 'meta' => ['contract' => self::CONTRACT]]);
    }

    public static function contract(): array
    {
        return ['version' => self::CONTRACT, 'state' => config('p11.security_commands_enabled') ? 'ENABLED' : 'DISABLED',
            'header' => self::HEADER, 'read' => '/api/v1/auth/security-requests/{original_key}',
            'step_up' => '/api/v1/auth/security-requests/{original_key}/verify', 'new_session_takeover' => false,
            'revoked_token_access' => false, 'retry_policy' => 'CHECK_ONLY', 'production_approved' => false];
    }

    private function gate(Request $request): void
    {
        if ($request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(428, 'SECURITY_COMMAND_CONTRACT_REQUIRED', 'Use the explicit security command contract.');
        }
        if (! config('p11.security_commands_enabled') || ! Schema::hasTable(self::TABLE) || ! Schema::hasTable('p11_security_reconciliations')) {
            ApiError::abort(503, 'SECURITY_COMMANDS_NOT_ENABLED', 'Security receipt protocol is not released or schema is unavailable.');
        }
    }

    private function actor(Request $request, bool $lock): array
    {
        $presented = $request->user() ?? auth('api')->user();
        $q = User::query()->whereKey($presented instanceof User ? $presented->user_id : '');
        $user = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($user === null || $user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Active authenticated owner required.');
        }

        return [$user, P10SessionFamily::current($user, true)];
    }

    private function factor(User $actor): string
    {
        $binding = $this->factorValue($actor);
        if ($binding === null) {
            ApiError::abort(409, 'SECURITY_PREEXISTING_MFA_REQUIRED', 'Enroll/confirm MFA before using the versioned command protocol; old UNKNOWN requires audited support.');
        }

        return $binding;
    }

    private function factorValue(User $actor): ?string
    {
        $factor = UserMfa::query()->whereKey($actor->user_id)->lockForUpdate()->first();
        if ($factor === null || ! $factor->isConfirmed() || ! is_string($factor->getRawOriginal('secret_ciphertext'))
            || $factor->getRawOriginal('secret_ciphertext') === '') {
            return null;
        }

        return hash_hmac('sha256', json_encode([$actor->user_id, $factor->getRawOriginal('secret_ciphertext'),
            $factor->confirmed_at->utc()->toISOString()], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function verificationDenied(Request $request, User $actor, string $key, string $code): array
    {
        AuditLogger::record(action: 'SECURITY_RECONCILIATION_DENIED', objectType: 'p11_security_command',
            objectId: strtolower($key), actorId: $actor->user_id, requestId: $request->attributes->get('request_id'),
            afterData: ['reason' => $code, 'command_replayed' => false], result: 'DENIED');

        return ['error' => $code];
    }

    private function row(string $actor, string $key): object
    {
        return DB::table(self::TABLE)->where('actor_id', $actor)->where('operation_key', $key)->firstOrFail();
    }

    private function credential(User $actor): string
    {
        // A second-resolution credential epoch alone cannot distinguish two
        // changes in the same second. Store only a keyed binding, never the hash itself.
        return hash_hmac('sha256', (string) $actor->password_hash, (string) config('app.key'));
    }

    private function readInput(Request $request, string $key): void
    {
        Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();
        if ($request->getContent() !== '' || $request->query() !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, 'SECURITY_RECONCILIATION_INPUT_INVALID', 'GET uses the exact key and no body/query.');
        }
    }

    private function result(object $row, array $body): array
    {
        if (($body['success'] ?? null) !== true) {
            throw new RuntimeException('P11_OWNER_RESULT_INVALID');
        }
        if ($row->operation === 'REVOKE_SESSION') {
            $data = $body['data'] ?? [];
            $target = RefreshToken::query()->whereKey($row->target_id)->where('user_id', $row->actor_id)->first();
            if ($target === null || ($data['id'] ?? null) !== $row->target_id || ($data['revoked'] ?? null) !== true
                || P10SessionFamily::query($row->actor_id, P10ProfilePolicy::family($target->auth_session_id, $target->id))->active()->exists()) {
                throw new RuntimeException('P11_REVOCATION_UNCONFIRMED');
            }

            return ['target_id' => $row->target_id, 'revoked' => true, 'revocation_scope' => 'AUTH_SESSION_FAMILY'];
        }
        $active = $row->operation === 'LOGOUT' ? P10SessionFamily::query($row->actor_id, $row->auth_session_id)->active()->exists()
            : RefreshToken::query()->where('user_id', $row->actor_id)->active()->exists();
        if ($active || data_get($body, 'meta.auth_result.state') !== 'SUCCEEDED') {
            throw new RuntimeException('P11_FAMILY_REVOCATION_UNCONFIRMED');
        }
        if ($row->operation === 'LOGOUT') {
            return ['scope' => 'CURRENT_AUTH_SESSION_FAMILY', 'business_drafts_deleted' => false, 'work_shift_ended' => false];
        }
        $time = data_get($body, 'meta.auth_result.password_changed_at');
        if (! is_string($time) || $time === '' || User::whereKey($row->actor_id)->firstOrFail()->passwordChangedAtEpoch() !== Carbon::parse($time)->timestamp) {
            throw new RuntimeException('P11_PASSWORD_TIME_UNCONFIRMED');
        }

        return ['session_policy' => 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED', 'relogin_required' => true, 'password_changed_at' => $time];
    }

    private function reply(object $row, bool $read, bool $replay): JsonResponse
    {
        $state = $row->state === 'PENDING' ? 'UNKNOWN' : $row->state;
        $receipt = ['contract' => self::CONTRACT, 'request_key' => $row->operation_key, 'request_id' => $row->request_id,
            'operation' => $row->operation, 'target_id' => $row->target_id, 'state' => $state, 'request_state' => $row->state,
            'retry_policy' => 'CHECK_ONLY', 'command_replayed' => false];
        if ($row->completed_at !== null) {
            $receipt['completed_at'] = Carbon::parse($row->completed_at, 'UTC')->utc()->toISOString();
        }
        if ($row->result_json !== null) {
            $receipt['result'] = json_decode($row->result_json, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($row->error_code !== null) {
            $receipt['error_code'] = $row->error_code;
        }
        $http = $read ? 200 : ($state === 'UNKNOWN' ? 503 : (int) $row->http_status);
        $body = ['success' => $read || $state === 'SUCCEEDED', 'data' => ['receipt' => $receipt],
            'meta' => ['contract' => self::CONTRACT, 'durable_request_receipt' => true, 'request_reconcile_supported' => true]];
        if (! $read && $state !== 'SUCCEEDED') {
            $body['error_code'] = $row->error_code ?? 'SECURITY_COMMAND_UNKNOWN';
            $body['message'] = 'Check the original request; never replay or replace its identity.';
        }

        return response()->json($body, $http)->header('Cache-Control', 'private, no-store')->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }
}
