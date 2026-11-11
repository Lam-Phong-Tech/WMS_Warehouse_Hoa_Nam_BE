<?php

namespace Modules\Auth\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\TrustedDevice;
use Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class TrustedDeviceService
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function cookieName(string $email): string
    {
        $prefix = (string) config('login_security.trusted_device.cookie_name_prefix', 'wms_trusted_device_');
        $hash = substr(hash('sha256', strtolower(trim($email))), 0, 16);

        return $prefix.$hash;
    }

    public function ttlDaysFor(User $user): int
    {
        if ($this->mfa->isPrivileged($user)) {
            return max(1, (int) config('login_security.trusted_device.privileged_ttl_days', 7));
        }

        return max(1, (int) config('login_security.trusted_device.ttl_days', 30));
    }

    public function findValid(string $email, ?string $rawToken): ?TrustedDevice
    {
        if (! is_string($rawToken) || trim($rawToken) === '') {
            return null;
        }

        $unwrapped = $this->unwrapCookieToken($rawToken);
        if ($unwrapped === '') {
            return null;
        }

        $normalizedEmail = strtolower(trim($email));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();
        if ($user === null) {
            return null;
        }

        $hash = hash('sha256', $unwrapped);
        $now = now();

        return TrustedDevice::query()
            ->where('user_id', $user->user_id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->where(function ($query) use ($hash, $now): void {
                $query->where('token_hash', $hash)
                    ->orWhere(function ($q) use ($hash, $now): void {
                        $q->where('previous_hash', $hash)
                            ->whereNotNull('previous_valid_until')
                            ->where('previous_valid_until', '>', $now);
                    });
            })
            ->first();
    }

    /**
     * @return array{model: TrustedDevice, raw_token: string, cookie: Cookie}
     */
    public function trust(User $user, string $installationId, Request $request): array
    {
        $rawToken = $this->generateRawToken();
        $tokenHash = hash('sha256', $rawToken);
        $ttlDays = $this->ttlDaysFor($user);
        $now = now();
        $expiresAt = $now->copy()->addDays($ttlDays);
        $maxPerUser = max(1, (int) config('login_security.trusted_device.max_per_user', 10));

        $model = DB::transaction(function () use ($user, $installationId, $tokenHash, $now, $expiresAt, $maxPerUser, $request): TrustedDevice {
            TrustedDevice::query()
                ->where('user_id', $user->user_id)
                ->where('installation_id', $installationId)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            $activeDevices = TrustedDevice::query()
                ->where('user_id', $user->user_id)
                ->whereNull('revoked_at')
                ->orderByRaw('CASE WHEN expires_at <= ? THEN 0 ELSE 1 END ASC', [$now])
                ->orderByRaw('COALESCE(last_used_at, trusted_at) ASC')
                ->get();

            if ($activeDevices->count() >= $maxPerUser) {
                $toRevokeCount = $activeDevices->count() - $maxPerUser + 1;
                foreach ($activeDevices->take($toRevokeCount) as $oldest) {
                    $oldest->update(['revoked_at' => $now]);
                    $this->auditLogger->log(
                        'DEVICE_TRUST_REVOKED',
                        'trusted_device',
                        $oldest->id,
                        $user->user_id,
                        null,
                        [],
                        ['reason' => 'max_per_user_eviction', 'installation_id' => $oldest->installation_id],
                        'Evicted oldest trusted device due to per-user limit',
                        result: 'SUCCESS',
                    );
                }
            }

            return TrustedDevice::query()->create([
                'user_id' => $user->user_id,
                'installation_id' => $installationId,
                'token_hash' => $tokenHash,
                'previous_hash' => null,
                'previous_valid_until' => null,
                'trusted_at' => $now,
                'expires_at' => $expiresAt,
                'last_used_at' => $now,
                'revoked_at' => null,
                'user_agent' => substr((string) $request->userAgent(), 0, 512) ?: null,
                'ip_address' => substr((string) $request->ip(), 0, 64) ?: null,
            ]);
        });

        return [
            'model' => $model,
            'raw_token' => $rawToken,
            'cookie' => $this->makeCookie($user->email, $rawToken, $ttlDays * 86400),
        ];
    }

    public function rotateIfNeeded(TrustedDevice $trust): ?string
    {
        $rotateAfterDays = max(1, (int) config('login_security.trusted_device.rotate_after_days', 7));
        $graceMinutes = max(1, (int) config('login_security.trusted_device.rotation_grace_minutes', 10));
        $now = now();

        if ($trust->trusted_at !== null && $trust->trusted_at->diffInDays($now) < $rotateAfterDays) {
            $trust->forceFill(['last_used_at' => $now])->save();

            return null;
        }

        if ($trust->previous_valid_until !== null && $trust->previous_valid_until->isFuture()) {
            $trust->forceFill(['last_used_at' => $now])->save();

            return null;
        }

        $newRaw = $this->generateRawToken();
        $newHash = hash('sha256', $newRaw);

        $trust->forceFill([
            'previous_hash' => $trust->token_hash,
            'previous_valid_until' => $now->copy()->addMinutes($graceMinutes),
            'token_hash' => $newHash,
            'trusted_at' => $now,
            'last_used_at' => $now,
        ])->save();

        return $newRaw;
    }

    public function makeCookieForModel(TrustedDevice $trust, string $email, string $rawToken): Cookie
    {
        $secondsLeft = max(60, now()->diffInSeconds($trust->expires_at, false));

        return $this->makeCookie($email, $rawToken, $secondsLeft);
    }

    public function revoke(TrustedDevice $trust, string $reason = 'manual'): void
    {
        if ($trust->revoked_at !== null) {
            return;
        }

        $trust->forceFill(['revoked_at' => now()])->save();
    }

    public function revokeAllForUser(string $userId, ?string $exceptInstallationId = null): int
    {
        return DB::transaction(function () use ($userId, $exceptInstallationId): int {
            $query = TrustedDevice::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at');

            if ($exceptInstallationId !== null && $exceptInstallationId !== '') {
                $query->where('installation_id', '!=', $exceptInstallationId);
            }

            return $query->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function expiredCookie(string $email): Cookie
    {
        return $this->makeCookie($email, '', -2628000);
    }

    private function generateRawToken(): string
    {
        return 'v1.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function unwrapCookieToken(string $rawToken): string
    {
        $value = trim($rawToken);
        for ($i = 0; $i < 2; $i++) {
            $stripped = CookieValuePrefix::remove($value);
            if (str_starts_with($stripped, 'v1.')) {
                return $stripped;
            }
            if (str_starts_with($value, 'v1.')) {
                return $value;
            }
            try {
                $value = Crypt::decrypt($value, false);
            } catch (Throwable) {
                break;
            }
        }

        $stripped = CookieValuePrefix::remove($value);

        return str_starts_with($stripped, 'v1.') ? $stripped : $value;
    }

    private function makeCookie(string $email, string $value, int $maxAgeSeconds): Cookie
    {
        $name = $this->cookieName($email);
        $cookieValue = $value === ''
            ? ''
            : Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()).$value, false);
        $path = (string) config('login_security.trusted_device.cookie_path', '/api/v1/auth');
        $secure = (bool) config('login_security.trusted_device.cookie_secure', true);
        $sameSite = strtolower((string) config('login_security.trusted_device.cookie_same_site', 'lax'));
        $expiresAt = $maxAgeSeconds < 0 ? 1 : now()->getTimestamp() + $maxAgeSeconds;

        return Cookie::create(
            name: $name,
            value: $cookieValue,
            expire: $expiresAt,
            path: $path,
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite,
        );
    }
}
