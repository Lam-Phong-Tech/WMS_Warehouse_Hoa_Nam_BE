<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

class LoginStepUp
{
    private const CACHE_PREFIX = 'login-step-up:';

    private const RESEND_COOLDOWN_PREFIX = 'login-step-up:cooldown:';

    private const RESEND_HOURLY_PREFIX = 'login-step-up:hourly:';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly LoginOtpService $otpService,
    ) {}

    /**
     * @return array{token: string, method: string, masked_email: string, expires_in: int}
     */
    public function issue(?User $user, string $ip, ?string $fallbackEmail = null): array
    {
        $token = Str::random(64);
        $ttl = max(60, (int) config('login_security.step_up.token_ttl_seconds', 300));
        $passwordAttempts = max(1, (int) config('login_security.step_up.password_attempts', 3));

        $email = $user !== null ? (string) $user->email : (string) ($fallbackEmail ?? '');
        $maskedEmail = self::maskEmail($email);

        if ($user === null || $user->status !== 'ACTIVE') {
            Cache::put($this->cacheKey($token), [
                'user_id' => null,
                'email' => strtolower(trim($email)),
                'method' => 'email',
                'otp_id' => null,
                'ip' => $ip,
                'code_attempts' => 0,
                'password_attempts' => $passwordAttempts,
                'verified' => false,
                'expires_at' => now()->getTimestamp() + $ttl,
            ], $ttl);

            return [
                'token' => $token,
                'method' => 'email',
                'masked_email' => $maskedEmail,
                'expires_in' => $ttl,
            ];
        }

        $method = $this->mfa->isEnabled($user) ? 'mfa' : 'email';
        $otpId = null;

        if ($method === 'email') {
            $issuedOtp = $this->otpService->issue($user, 'STEP_UP', $ip);
            $otpId = $issuedOtp['otp_id'];
            Cache::put(
                self::RESEND_COOLDOWN_PREFIX.$this->tokenHash($token),
                now()->getTimestamp() + max(1, (int) config('login_security.step_up.otp_resend_cooldown_seconds', 60)),
                max(1, (int) config('login_security.step_up.otp_resend_cooldown_seconds', 60)),
            );
        }

        Cache::put($this->cacheKey($token), [
            'user_id' => (string) $user->user_id,
            'email' => strtolower(trim((string) $user->email)),
            'method' => $method,
            'otp_id' => $otpId,
            'ip' => $ip,
            'code_attempts' => 0,
            'password_attempts' => $passwordAttempts,
            'verified' => false,
            'expires_at' => now()->getTimestamp() + $ttl,
        ], $ttl);

        return [
            'token' => $token,
            'method' => $method,
            'masked_email' => $maskedEmail,
            'expires_in' => $ttl,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     attempts_left: int,
     *     password_attempts_left: int,
     *     expires_in: int,
     *     method: string,
     *     locked: bool,
     *     expired: bool,
     *     user_id: ?string
     * }
     */
    public function verify(string $token, string $code): array
    {
        $key = $this->cacheKey($token);
        $payload = Cache::get($key);
        $maxCodeAttempts = max(1, (int) config('login_security.step_up.max_code_attempts', 5));
        $ttl = max(60, (int) config('login_security.step_up.token_ttl_seconds', 300));

        if (! is_array($payload) || (($payload['expires_at'] ?? 0) <= now()->getTimestamp())) {
            Cache::forget($key);

            return [
                'ok' => false,
                'attempts_left' => 0,
                'password_attempts_left' => 0,
                'expires_in' => 0,
                'method' => 'email',
                'locked' => false,
                'expired' => true,
                'user_id' => null,
            ];
        }

        $method = (string) ($payload['method'] ?? 'email');
        $userId = is_string($payload['user_id'] ?? null) ? $payload['user_id'] : null;
        $user = $userId !== null ? User::query()->find($userId) : null;

        if ($user === null || $user->status !== 'ACTIVE') {
            $attempts = ((int) ($payload['code_attempts'] ?? 0)) + 1;
            $locked = $attempts >= $maxCodeAttempts;
            if ($locked) {
                Cache::forget($key);
            } else {
                $payload['code_attempts'] = $attempts;
                Cache::put($key, $payload, $ttl);
            }

            return [
                'ok' => false,
                'attempts_left' => max(0, $maxCodeAttempts - $attempts),
                'password_attempts_left' => 0,
                'expires_in' => 0,
                'method' => $method,
                'locked' => $locked,
                'expired' => false,
                'user_id' => $userId,
            ];
        }

        $valid = false;
        if ($method === 'mfa') {
            $valid = $this->mfa->verifySecondFactor($user, $code) !== null;
        } else {
            $otpId = is_string($payload['otp_id'] ?? null) ? $payload['otp_id'] : '';
            if ($otpId !== '' && $this->otpService->verify($otpId, $code)) {
                $this->otpService->consume($otpId);
                $valid = true;
            }
        }

        if (! $valid) {
            $attempts = ((int) ($payload['code_attempts'] ?? 0)) + 1;
            $locked = $attempts >= $maxCodeAttempts;
            if ($locked) {
                if (is_string($payload['otp_id'] ?? null) && $payload['otp_id'] !== '') {
                    $this->otpService->invalidate($payload['otp_id']);
                }
                Cache::forget($key);
            } else {
                $payload['code_attempts'] = $attempts;
                Cache::put($key, $payload, $ttl);
            }

            return [
                'ok' => false,
                'attempts_left' => max(0, $maxCodeAttempts - $attempts),
                'password_attempts_left' => 0,
                'expires_in' => 0,
                'method' => $method,
                'locked' => $locked,
                'expired' => false,
                'user_id' => $userId,
            ];
        }

        $passwordAttempts = max(1, (int) ($payload['password_attempts'] ?? config('login_security.step_up.password_attempts', 3)));
        $payload['verified'] = true;
        $payload['password_attempts'] = $passwordAttempts;
        $payload['expires_at'] = now()->getTimestamp() + $ttl;
        Cache::put($key, $payload, $ttl);

        return [
            'ok' => true,
            'attempts_left' => max(0, $maxCodeAttempts - (int) ($payload['code_attempts'] ?? 0)),
            'password_attempts_left' => $passwordAttempts,
            'expires_in' => $ttl,
            'method' => $method,
            'locked' => false,
            'expired' => false,
            'user_id' => $userId,
        ];
    }

    /**
     * @return array{ok: bool, expires_in: int, retry_after: int, expired: bool, user_id: ?string}
     */
    public function resend(string $token, string $ip): array
    {
        $key = $this->cacheKey($token);
        $payload = Cache::get($key);
        $ttl = max(60, (int) config('login_security.step_up.token_ttl_seconds', 300));
        $cooldownSeconds = max(1, (int) config('login_security.step_up.otp_resend_cooldown_seconds', 60));
        $maxPerHour = max(1, (int) config('login_security.step_up.otp_max_per_hour', 5));

        if (! is_array($payload) || (($payload['expires_at'] ?? 0) <= now()->getTimestamp())) {
            Cache::forget($key);

            return ['ok' => false, 'expires_in' => 0, 'retry_after' => 0, 'expired' => true, 'user_id' => null];
        }

        if (($payload['method'] ?? 'email') !== 'email') {
            return ['ok' => false, 'expires_in' => 0, 'retry_after' => 0, 'expired' => true, 'user_id' => $payload['user_id'] ?? null];
        }

        $cooldownKey = self::RESEND_COOLDOWN_PREFIX.$this->tokenHash($token);
        $cooldownUntil = Cache::get($cooldownKey);
        $now = now()->getTimestamp();
        if (is_numeric($cooldownUntil) && (int) $cooldownUntil > $now) {
            return [
                'ok' => false,
                'expires_in' => 0,
                'retry_after' => (int) $cooldownUntil - $now,
                'expired' => false,
                'user_id' => $payload['user_id'] ?? null,
            ];
        }

        $userId = is_string($payload['user_id'] ?? null) ? $payload['user_id'] : null;
        $hourlyKey = self::RESEND_HOURLY_PREFIX.($userId ?? $this->tokenHash($token));
        $hourlyCount = (int) Cache::get($hourlyKey, 0);
        if ($hourlyCount >= $maxPerHour) {
            return [
                'ok' => false,
                'expires_in' => 0,
                'retry_after' => $cooldownSeconds,
                'expired' => false,
                'user_id' => $userId,
            ];
        }

        $user = $userId !== null ? User::query()->find($userId) : null;
        if ($user !== null && $user->status === 'ACTIVE') {
            $issuedOtp = $this->otpService->issue($user, 'STEP_UP', $ip);
            $payload['otp_id'] = $issuedOtp['otp_id'];
            $payload['code_attempts'] = 0;
            $payload['expires_at'] = $now + $ttl;
            Cache::put($key, $payload, $ttl);
        }

        Cache::put($cooldownKey, $now + $cooldownSeconds, $cooldownSeconds);
        Cache::put($hourlyKey, $hourlyCount + 1, 3600);

        return [
            'ok' => true,
            'expires_in' => $ttl,
            'retry_after' => 0,
            'expired' => false,
            'user_id' => $userId,
        ];
    }

    /**
     * @return array{ok: bool, attempts_left: int, method: ?string, email: ?string}
     */
    public function consumePasswordAttempt(string $token, ?string $email = null): array
    {
        $key = $this->cacheKey($token);
        $payload = Cache::get($key);

        if (! is_array($payload) || (($payload['expires_at'] ?? 0) <= now()->getTimestamp())) {
            Cache::forget($key);

            return ['ok' => false, 'attempts_left' => 0, 'method' => null, 'email' => null];
        }

        if ($email !== null && strtolower(trim($email)) !== (string) ($payload['email'] ?? '')) {
            return ['ok' => false, 'attempts_left' => 0, 'method' => null, 'email' => null];
        }

        $current = (int) ($payload['password_attempts'] ?? 0);
        if ($current <= 0) {
            Cache::forget($key);

            return ['ok' => false, 'attempts_left' => 0, 'method' => null, 'email' => null];
        }

        $remainingAfterThis = $current - 1;
        if ($remainingAfterThis <= 0) {
            $payload['password_attempts'] = 0;
        } else {
            $payload['password_attempts'] = $remainingAfterThis;
        }

        $ttl = max(1, (int) ($payload['expires_at'] - now()->getTimestamp()));
        Cache::put($key, $payload, $ttl);

        return [
            'ok' => true,
            'attempts_left' => $current,
            'method' => is_string($payload['method'] ?? null) ? $payload['method'] : null,
            'email' => is_string($payload['email'] ?? null) ? $payload['email'] : null,
        ];
    }

    public function isVerified(string $token, ?string $email = null): bool
    {
        $payload = Cache::get($this->cacheKey($token));
        if (! is_array($payload) || (($payload['expires_at'] ?? 0) <= now()->getTimestamp())) {
            return false;
        }
        if ($email !== null && strtolower(trim($email)) !== (string) ($payload['email'] ?? '')) {
            return false;
        }

        return (bool) ($payload['verified'] ?? false);
    }

    public function methodOf(string $token): ?string
    {
        $payload = Cache::get($this->cacheKey($token));

        return is_array($payload) && is_string($payload['method'] ?? null) ? $payload['method'] : null;
    }

    public function clear(string $token): void
    {
        $key = $this->cacheKey($token);
        $payload = Cache::get($key);
        if (is_array($payload) && is_string($payload['otp_id'] ?? null) && $payload['otp_id'] !== '') {
            $this->otpService->invalidate($payload['otp_id']);
        }
        Cache::forget($key);
        Cache::forget(self::RESEND_COOLDOWN_PREFIX.$this->tokenHash($token));
    }

    public static function maskEmail(string $email): string
    {
        $trimmed = trim($email);
        if ($trimmed === '' || ! str_contains($trimmed, '@')) {
            return '***@***';
        }
        [$local, $domain] = explode('@', $trimmed, 2);
        $first = $local !== '' ? mb_substr($local, 0, 1) : '*';

        return $first.'***@'.$domain;
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.$this->tokenHash($token);
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
