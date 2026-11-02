<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Events\AuthSessionReplaced;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

/** Rotation, password changes and family revocation serialize on the parent user. */
class RefreshTokenService
{
    /** @return array{access_token: string, session: ?array{raw: string, id: string}, replaced_existing_session: bool} */
    public function completeLogin(User $user, Request $request, bool $issueRefresh): array
    {
        AccountSessionPolicy::assertReady();

        $completed = DB::transaction(function () use ($user, $request, $issueRefresh): array {
            // Access-only clients also need a persisted family when replacement is enabled.
            $session = $issueRefresh || AccountSessionPolicy::enabled()
                ? $this->issueSession($user, $request)
                : null;
            $accessToken = $this->accessTokenFor($user, $session['id'] ?? null);
            $user->forceFill(['last_login_at' => now()])->save();

            return [
                'access_token' => $accessToken,
                'session' => $issueRefresh ? $session : null,
                'replaced_existing_session' => (bool) ($session['replaced_existing_session'] ?? false),
            ];
        }, 3);

        // The old session must see the committed replacement before it checks /auth/me.
        // Reverb availability must never turn a completed login into an error.
        if ($completed['replaced_existing_session']) {
            try {
                event(new AuthSessionReplaced((string) $user->user_id));
            } catch (Throwable $error) {
                Log::warning('Auth session replacement broadcast failed', [
                    'user_id' => (string) $user->user_id,
                    'exception' => $error::class,
                ]);
            }
        }

        return $completed;
    }

    public function issue(User $user, Request $request): string
    {
        return $this->issueSession($user, $request)['raw'];
    }

    /** @return array{raw: string, id: string, replaced_existing_session: bool} */
    public function issueSession(User $user, Request $request, ?string $authSessionId = null): array
    {
        return DB::transaction(function () use ($user, $request, $authSessionId): array {
            $fresh = User::query()->whereKey($user->user_id)->lockForUpdate()->first();
            // A password verified before a concurrent password change is not a new login.
            if ($fresh === null || $fresh->status !== 'ACTIVE'
                || ! hash_equals((string) $fresh->password_hash, (string) $user->password_hash)) {
                ApiError::abort(401, 'CREDENTIALS_CHANGED', 'Thông tin xác thực đã thay đổi; đăng nhập lại.');
            }
            $raw = $this->generateRawToken();
            $family = $authSessionId ?? (string) Str::uuid();
            $replacedExistingSession = false;
            if ($authSessionId === null && AccountSessionPolicy::enabled()) {
                AccountSessionPolicy::assertReady();
                $replacedExistingSession = RefreshToken::query()->where('user_id', $fresh->user_id)->active()->exists();
                // A new account login supersedes every prior credential, including
                // rotated/logged-out ancestors; their late replay must not revoke B.
                RefreshToken::query()->where('user_id', $fresh->user_id)
                    ->whereNull('replaced_by_auth_session_id')->update(['replaced_by_auth_session_id' => $family]);
                $this->revokeAllForUser((string) $fresh->user_id);
            }
            $record = RefreshToken::create([
                'user_id' => $fresh->user_id, 'auth_session_id' => $family,
                'token_hash' => $this->hash($raw),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'ip_address' => Str::limit((string) $request->ip(), 45, ''),
                'expires_at' => now()->addMinutes((int) config('refresh_token.ttl_minutes')),
            ]);
            // Login/MFA FormRequests may differ from the container's current Request.
            foreach ([$request, request()] as $currentRequest) {
                $currentRequest->attributes->set('wms.issued_auth_session_id', $family);
                $currentRequest->attributes->set('wms.issued_auth_session_user_id', (string) $fresh->user_id);
            }

            return ['raw' => $raw, 'id' => (string) $record->getKey(),
                'replaced_existing_session' => $replacedExistingSession];
        }, 3);
    }

    public function accessTokenFor(User $user, ?string $sessionId): string
    {
        // Clear custom claims even for access-only clients in long-running processes.
        return JWTAuth::claims($sessionId === null ? [] : ['sid' => $sessionId])->fromUser($user);
    }

    public function currentSessionId(): ?string
    {
        try {
            $sid = JWTAuth::parseToken()->getPayload()->get('sid');
        } catch (\Throwable) {
            return null;
        }

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    /** @return array{user: User, raw: string, id: string}|null */
    public function rotate(string $rawToken, Request $request): ?array
    {
        AccountSessionPolicy::assertReady();
        $request->attributes->remove('wms.auth_refresh_failure');
        $hash = $this->hash($rawToken);
        // Identity lookup only; decisions are repeated after acquiring both locks.
        $owner = RefreshToken::query()->where('token_hash', $hash)->value('user_id');
        if ($owner === null) {
            return null;
        }

        return DB::transaction(function () use ($owner, $hash, $request): ?array {
            // Same lock order as profile/password/logout/revoke: user, then token.
            $user = User::query()->whereKey($owner)->lockForUpdate()->first();
            $record = RefreshToken::query()->where('token_hash', $hash)->lockForUpdate()->first();
            if ($record === null || $record->expires_at->isPast()) {
                return null;
            }
            if ($record->replaced_by_auth_session_id !== null) {
                // Already committed login replacement is terminal even after flag rollback.
                $request->attributes->set('wms.auth_refresh_failure', 'AUTH_SESSION_REPLACED');
                $request->attributes->set('wms.auth_replacing_session_id', (string) $record->replaced_by_auth_session_id);

                return null;
            }
            if ($record->revoked_at !== null) {
                // Preserve the existing security policy for replayed refresh tokens.
                $this->revokeAllForUser((string) $record->user_id);

                return null;
            }
            if ($user === null || $user->status !== 'ACTIVE') {
                $this->revokeAllForUser((string) $record->user_id);

                return null;
            }
            $record->update(['revoked_at' => now()]);
            $session = $this->issueSession($user, $request, P10ProfilePolicy::family($record->auth_session_id, (string) $record->id));

            return ['user' => $user, 'raw' => $session['raw'], 'id' => $session['id']];
        }, 3);
    }

    public function revoke(string $rawToken): void
    {
        $hash = $this->hash($rawToken);
        $owner = RefreshToken::query()->where('token_hash', $hash)->value('user_id');
        if ($owner === null) {
            return;
        }
        DB::transaction(function () use ($owner, $hash): void {
            User::withTrashed()->whereKey($owner)->lockForUpdate()->first();
            RefreshToken::query()->where('token_hash', $hash)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }, 3);
    }

    public function revokeAllForUser(string $userId): void
    {
        DB::transaction(function () use ($userId): void {
            User::withTrashed()->whereKey($userId)->lockForUpdate()->first();
            RefreshToken::query()->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }, 3);
    }

    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
