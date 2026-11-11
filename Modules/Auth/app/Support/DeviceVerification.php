<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

class DeviceVerification
{
    private const CACHE_PREFIX = 'login-device-verify:';

    private const RESEND_COOLDOWN_PREFIX = 'login-device-verify:cooldown:';

    private const RESEND_HOURLY_PREFIX = 'login-device-verify:hourly:';

    public function __construct(
        private readonly LoginOtpService $otpService,
    ) {}

    /**
     * @return array{device_token: string, masked_email: string, expires_in: int}
     */
    public function issue(User $user, string $installationId, string $ip): array
    {
        $deviceToken = Str::random(64);
        $ttl = max(60, (int) config('login_security.device_otp.challenge_ttl_seconds', 300));
        $cooldown = max(1, (int) config('login_security.device_otp.resend_cooldown_seconds', 60));

        $issuedOtp = $this->otpService->issue($user, 'DEVICE', $ip);

        Cache::put($this->cacheKey($deviceToken), [
            'user_id' => (string) $user->user_id,
            'installation_id' => $installationId,
            'otp_id' => $issuedOtp['otp_id'],
            'attempts' => 0,
            'ip' => $ip,
            'expires_at' => now()->getTimestamp() + $ttl,
        ], $ttl);

        Cache::put(
            self::RESEND_COOLDOWN_PREFIX.$this->tokenHash($deviceToken),
            now()->getTimestamp() + $cooldown,
            $cooldown,
        );

        return [
            'device_token' => $deviceToken,
            'masked_email' => LoginStepUp::maskEmail((string) $user->email),
            'expires_in' => $ttl,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     user: ?User,
     *     installation_id: ?string,
     *     attempts_left: int,
     *     locked: bool,
     *     expired: bool
     * }
     */
    public function verify(string $deviceToken, string $code): array
    {
        $key = $this->cacheKey($deviceToken);
        $payload = Cache::get($key);

        if (! is_array($payload) || (($payload['expires_at'] ?? 0) <= now()->getTimestamp())) {
            Cache::forget($key);

            return [
                'ok' => false,
                'user' => null,
                'installation_id' => null,
                'attempts_left' => 0,
                'locked' => false,
                'expired' => true,
            ];
        }

        $otpId = (string) ($payload['otp_id'] ?? '');
        $maxAttempts = max(1, (int) config('login_security.device_otp.max_attempts', 5));
        $ttl = max(60, (int) config('login_security.device_otp.challenge_ttl_seconds', 300));
        $valid = $otpId !== '' && $this->otpService->verify($otpId, $code);

        if (! $valid) {
            $attempts = ((int) ($payload['attempts'] ?? 0)) + 1;
            $locked = $attempts >= $maxAttempts;
            if ($locked) {
                if ($otpId !== '') {
                    $this->otpService->invalidate($otpId);
                }
                Cache::forget($key);
            } else {
                $payload['attempts'] = $attempts;
                Cache::put($key, $payload, $ttl);
            }

            return [
                'ok' => false,
                'user' => null,
                'installation_id' => null,
                'attempts_left' => max(0, $maxAttempts - $attempts),
                'locked' => $locked,
                'expired' => false,
            ];
        }

        $this->otpService->consume($otpId);

        $user = User::query()->find((string) ($payload['user_id'] ?? ''));
        if ($user === null || $user->status !== 'ACTIVE') {
            Cache::forget($key);

            return [
                'ok' => false,
                'user' => null,
                'installation_id' => null,
                'attempts_left' => 0,
                'locked' => false,
                'expired' => true,
            ];
        }

        $installationId = (string) ($payload['installation_id'] ?? '');
        Cache::forget($key);

        return [
            'ok' => true,
            'user' => $user,
            'installation_id' => $installationId,
            'attempts_left' => max(0, $maxAttempts - (int) ($payload['attempts'] ?? 0)),
            'locked' => false,
            'expired' => false,
        ];
    }

    /**
     * @return array{ok: bool, expires_in: int, retry_after: int, rate_limited: bool, expired: bool}
     */
    public function resend(string $deviceToken): array
    {
        $key = $this->cacheKey($deviceToken);
        $payload = Cache::get($key);
        $ttl = max(60, (int) config('login_security.device_otp.challenge_ttl_seconds', 300));
        $cooldown = max(1, (int) config('login_security.device_otp.resend_cooldown_seconds', 60));
        $maxPerHour = max(1, (int) config('login_security.device_otp.max_per_hour', 5));

        if (! is_array($payload) || (($payload['expires_at'] ?? 0) <= now()->getTimestamp())) {
            Cache::forget($key);

            return [
                'ok' => false,
                'expires_in' => 0,
                'retry_after' => 0,
                'rate_limited' => false,
                'expired' => true,
            ];
        }

        $cooldownKey = self::RESEND_COOLDOWN_PREFIX.$this->tokenHash($deviceToken);
        $cooldownUntil = (int) Cache::get($cooldownKey, 0);
        $now = now()->getTimestamp();

        if ($cooldownUntil > $now) {
            return [
                'ok' => false,
                'expires_in' => max(0, ((int) $payload['expires_at']) - $now),
                'retry_after' => $cooldownUntil - $now,
                'rate_limited' => true,
                'expired' => false,
            ];
        }

        $userId = (string) ($payload['user_id'] ?? '');
        $hourlyKey = self::RESEND_HOURLY_PREFIX.hash('sha256', $userId);
        $hourlyCount = (int) Cache::get($hourlyKey, 0);

        if ($hourlyCount >= $maxPerHour) {
            return [
                'ok' => false,
                'expires_in' => max(0, ((int) $payload['expires_at']) - $now),
                'retry_after' => $cooldown,
                'rate_limited' => true,
                'expired' => false,
            ];
        }

        $user = User::query()->find($userId);
        if ($user === null || $user->status !== 'ACTIVE') {
            Cache::forget($key);

            return [
                'ok' => false,
                'expires_in' => 0,
                'retry_after' => 0,
                'rate_limited' => false,
                'expired' => true,
            ];
        }

        $issuedOtp = $this->otpService->issue($user, 'DEVICE', (string) ($payload['ip'] ?? '127.0.0.1'));
        $payload['otp_id'] = $issuedOtp['otp_id'];
        $payload['expires_at'] = $now + $ttl;
        Cache::put($key, $payload, $ttl);
        Cache::put($cooldownKey, $now + $cooldown, $cooldown);
        Cache::put($hourlyKey, $hourlyCount + 1, 3600);

        return [
            'ok' => true,
            'expires_in' => $ttl,
            'retry_after' => 0,
            'rate_limited' => false,
            'expired' => false,
        ];
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.$this->tokenHash($token);
    }
}
