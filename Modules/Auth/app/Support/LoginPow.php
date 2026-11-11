<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LoginPow
{
    private const CACHE_PREFIX = 'login-pow:';

    private const LOCK_PREFIX = 'login-pow:lock:';

    public function __construct(
        private readonly LoginThrottle $throttle,
    ) {}

    public function required(string $email, string $ip): bool
    {
        if (! (bool) config('login_security.pow.enabled', false)) {
            return false;
        }

        $trigger = max(1, (int) config('login_security.pow.trigger_failures', 3));
        $state = $this->throttle->state($email, $ip);

        return $state['failures'] >= $trigger;
    }

    /**
     * @return array{
     *     challenge_id: string,
     *     nonce: string,
     *     difficulty: int,
     *     algorithm: string,
     *     expires_in: int
     * }
     */
    public function challenge(string $email, string $ip): array
    {
        $challengeId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(16));
        $ttl = max(30, (int) config('login_security.pow.challenge_ttl_seconds', 120));
        $difficulty = $this->difficultyFor($email, $ip);

        Cache::put(self::CACHE_PREFIX.$challengeId, [
            'nonce' => $nonce,
            'difficulty' => $difficulty,
            'email_hash' => $this->throttle->emailHash($email),
            'ip_hash' => hash('sha256', trim($ip)),
            'expires_at' => now()->getTimestamp() + $ttl,
            'used' => false,
        ], $ttl);

        return [
            'challenge_id' => $challengeId,
            'nonce' => $nonce,
            'difficulty' => $difficulty,
            'algorithm' => 'sha256-leading-zero-bits',
            'expires_in' => $ttl,
        ];
    }

    /**
     * @param  array<string, mixed>  $pow
     */
    public function verify(array $pow, string $email, string $ip): bool
    {
        $challengeId = isset($pow['challenge_id']) && is_string($pow['challenge_id'])
            ? trim($pow['challenge_id'])
            : '';
        $solutionRaw = $pow['solution'] ?? null;

        if ($challengeId === '' || (! is_int($solutionRaw) && ! (is_string($solutionRaw) && ctype_digit($solutionRaw)))) {
            return false;
        }

        $solution = (int) $solutionRaw;
        if ($solution < 0) {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX.$challengeId;

        return Cache::lock(self::LOCK_PREFIX.$challengeId, 2)->get(function () use ($cacheKey, $solution, $email): bool {
            $stored = Cache::get($cacheKey);
            if (! is_array($stored)) {
                return false;
            }

            if (($stored['used'] ?? true) === true || (($stored['expires_at'] ?? 0) <= now()->getTimestamp())) {
                Cache::forget($cacheKey);

                return false;
            }

            $expectedEmailHash = $this->throttle->emailHash($email);
            if (! hash_equals((string) ($stored['email_hash'] ?? ''), $expectedEmailHash)) {
                return false;
            }

            $nonce = (string) ($stored['nonce'] ?? '');
            $difficulty = (int) ($stored['difficulty'] ?? 16);

            if (! self::hasLeadingZeroBits($nonce, $solution, $difficulty)) {
                return false;
            }

            $stored['used'] = true;
            Cache::put($cacheKey, $stored, max(1, ((int) $stored['expires_at']) - now()->getTimestamp()));
            Cache::forget($cacheKey);

            return true;
        });
    }

    public function difficultyFor(string $email, string $ip): int
    {
        $base = max(1, (int) config('login_security.pow.base_difficulty', 16));
        $max = max($base, (int) config('login_security.pow.max_difficulty', 22));
        $failures = $this->throttle->state($email, $ip)['failures'];

        $step = 0;
        if ($failures >= 12) {
            $step = 6;
        } elseif ($failures >= 9) {
            $step = 4;
        } elseif ($failures >= 6) {
            $step = 2;
        }

        return min($max, $base + $step);
    }

    public static function hasLeadingZeroBits(string $nonce, int $solution, int $difficulty): bool
    {
        if ($difficulty <= 0) {
            return true;
        }

        $digest = hash('sha256', $nonce.':'.$solution, true);
        $fullBytes = intdiv($difficulty, 8);
        $remBits = $difficulty % 8;

        for ($i = 0; $i < $fullBytes; $i++) {
            if (ord($digest[$i]) !== 0) {
                return false;
            }
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($digest[$fullBytes]) & $mask) !== 0) {
                return false;
            }
        }

        return true;
    }
}
