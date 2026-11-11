<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Cache;

class LoginThrottle
{
    private const PAIR_PREFIX = 'login-backoff:pair:';

    private const ACCOUNT_PREFIX = 'login-backoff:account:';

    private const LOCK_PREFIX = 'login-backoff:lock:';

    public function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public function pairKey(string $email, string $ip): string
    {
        $normalizedEmail = strtolower(trim($email));

        return self::PAIR_PREFIX.hash('sha256', $normalizedEmail.'|'.trim($ip));
    }

    public function accountKey(string $email): string
    {
        return self::ACCOUNT_PREFIX.$this->emailHash($email);
    }

    /**
     * @return array{
     *     failures: int,
     *     cap_hits: int,
     *     blocked_until: ?int,
     *     step_up_required: bool,
     *     step_up_alerted: bool,
     *     pair_retry_after: int,
     *     account_retry_after: int
     * }
     */
    public function state(string $email, string $ip): array
    {
        $pair = $this->readState($this->pairKey($email, $ip));
        $account = $this->readState($this->accountKey($email));
        $now = now()->getTimestamp();

        $pairBlockedUntil = $pair['blocked_until'];
        $pairRetry = $pairBlockedUntil !== null ? max(0, $pairBlockedUntil - $now) : 0;

        $multiIp = count($account['ips']) > 1;
        $accountBlockedUntil = $multiIp ? $account['blocked_until'] : null;
        $accountRetry = $accountBlockedUntil !== null ? max(0, $accountBlockedUntil - $now) : 0;

        $blockedUntil = max($pairBlockedUntil ?? 0, $accountBlockedUntil ?? 0);

        return [
            'failures' => max($pair['failures'], $account['failures']),
            'cap_hits' => max($pair['cap_hits'], $account['cap_hits']),
            'blocked_until' => $blockedUntil > 0 ? $blockedUntil : null,
            'step_up_required' => $pair['step_up_required'] || $account['step_up_required'],
            'step_up_alerted' => $account['step_up_alerted'],
            'pair_retry_after' => $pairRetry,
            'account_retry_after' => $accountRetry,
        ];
    }

    public function pairRetryAfter(string $email, string $ip): int
    {
        $pair = $this->readState($this->pairKey($email, $ip));
        if ($pair['blocked_until'] === null) {
            return 0;
        }

        return max(0, $pair['blocked_until'] - now()->getTimestamp());
    }

    public function accountRetryAfter(string $email): int
    {
        $account = $this->readState($this->accountKey($email));
        if (count($account['ips']) <= 1 || $account['blocked_until'] === null) {
            return 0;
        }

        return max(0, $account['blocked_until'] - now()->getTimestamp());
    }

    public function retryAfter(string $email, string $ip): int
    {
        return max($this->pairRetryAfter($email, $ip), $this->accountRetryAfter($email));
    }

    /**
     * @return array{
     *     retry_after: int,
     *     cap_hits: int,
     *     step_up_required: bool,
     *     step_up_triggered: bool,
     *     failures: int,
     *     scope: string
     * }
     */
    public function registerFailure(string $email, string $ip): array
    {
        $accountKey = $this->accountKey($email);
        $pairKey = $this->pairKey($email, $ip);
        $ipHash = hash('sha256', trim($ip));

        return Cache::lock(self::LOCK_PREFIX.$this->emailHash($email), 2)->get(function () use ($accountKey, $pairKey, $ipHash): array {
            $freeAttempts = max(0, (int) config('login_security.backoff.free_attempts', 3));
            $delays = array_values(array_filter(
                array_map('intval', (array) config('login_security.backoff.delays', [1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900])),
                static fn (int $d): bool => $d > 0,
            ));
            if ($delays === []) {
                $delays = [1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900];
            }
            $capSeconds = max(1, (int) config('login_security.backoff.cap_seconds', 900));
            $ttl = max(60, (int) config('login_security.backoff.counter_ttl_seconds', 86400));
            $stepUpCycles = max(1, (int) config('login_security.backoff.step_up_after_cycles', 5));
            $now = now()->getTimestamp();

            $pair = $this->readState($pairKey);
            $account = $this->readState($accountKey);
            $wasStepUp = $pair['step_up_required'] || $account['step_up_required'];

            $pairUpdated = $this->advanceCounter($pair, $freeAttempts, $delays, $capSeconds, $stepUpCycles, $now);
            $accountUpdated = $this->advanceCounter($account, $freeAttempts, $delays, $capSeconds, $stepUpCycles, $now);

            $pairKeys = $accountUpdated['pair_keys'];
            if (! in_array($pairKey, $pairKeys, true)) {
                $pairKeys[] = $pairKey;
            }
            $accountUpdated['pair_keys'] = $pairKeys;

            $ips = $accountUpdated['ips'];
            if (! in_array($ipHash, $ips, true)) {
                $ips[] = $ipHash;
            }
            $accountUpdated['ips'] = $ips;

            $stepUpRequired = $pairUpdated['step_up_required'] || $accountUpdated['step_up_required'];
            $stepUpTriggered = ! $wasStepUp && $stepUpRequired && ! $accountUpdated['step_up_alerted'];
            if ($stepUpRequired) {
                $pairUpdated['step_up_required'] = true;
                $accountUpdated['step_up_required'] = true;
                $accountUpdated['step_up_alerted'] = true;
            }

            Cache::put($pairKey, $pairUpdated, $ttl);
            Cache::put($accountKey, $accountUpdated, $ttl);

            $multiIp = count($ips) > 1;
            $effectiveRetry = $multiIp
                ? max($pairUpdated['last_delay'], $accountUpdated['last_delay'])
                : $pairUpdated['last_delay'];

            return [
                'retry_after' => $effectiveRetry,
                'cap_hits' => max($pairUpdated['cap_hits'], $accountUpdated['cap_hits']),
                'step_up_required' => $stepUpRequired,
                'step_up_triggered' => $stepUpTriggered,
                'failures' => max($pairUpdated['failures'], $accountUpdated['failures']),
                'scope' => $multiIp && $accountUpdated['last_delay'] > $pairUpdated['last_delay'] ? 'account' : 'pair',
            ];
        });
    }

    public function markStepUpAlerted(string $email): bool
    {
        $accountKey = $this->accountKey($email);
        $ttl = max(60, (int) config('login_security.backoff.counter_ttl_seconds', 86400));

        return Cache::lock(self::LOCK_PREFIX.$this->emailHash($email), 2)->get(function () use ($accountKey, $ttl): bool {
            $account = $this->readState($accountKey);
            if ($account['step_up_alerted']) {
                return false;
            }
            $account['step_up_alerted'] = true;
            Cache::put($accountKey, $account, $ttl);

            return true;
        });
    }

    public function registerSuccess(string $email, ?string $ip = null): void
    {
        $this->clear($email, $ip);
    }

    public function clear(string $email, ?string $ip = null): void
    {
        $accountKey = $this->accountKey($email);

        Cache::lock(self::LOCK_PREFIX.$this->emailHash($email), 2)->get(function () use ($email, $ip, $accountKey): void {
            $account = $this->readState($accountKey);
            foreach ($account['pair_keys'] as $recordedPairKey) {
                if (is_string($recordedPairKey) && $recordedPairKey !== '') {
                    Cache::forget($recordedPairKey);
                }
            }
            if ($ip !== null && $ip !== '') {
                Cache::forget($this->pairKey($email, $ip));
            }
            Cache::forget($accountKey);
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<int>  $delays
     * @return array{
     *     failures: int,
     *     cap_hits: int,
     *     blocked_until: ?int,
     *     step_up_required: bool,
     *     step_up_alerted: bool,
     *     pair_keys: list<string>,
     *     ips: list<string>,
     *     last_delay: int
     * }
     */
    private function advanceCounter(
        array $state,
        int $freeAttempts,
        array $delays,
        int $capSeconds,
        int $stepUpCycles,
        int $now,
    ): array {
        $failures = $state['failures'] + 1;
        $capHits = $state['cap_hits'];
        $delay = 0;

        if ($failures > $freeAttempts) {
            $index = min($failures - $freeAttempts - 1, count($delays) - 1);
            $delay = min($capSeconds, max(0, $delays[$index]));
        }

        $wasCurrentlyBlockedAtCap = $state['blocked_until'] !== null && $state['blocked_until'] > $now;
        if ($delay >= $capSeconds && ! $wasCurrentlyBlockedAtCap) {
            $capHits++;
        }

        $stepUpRequired = $state['step_up_required'] || $capHits >= $stepUpCycles;

        return [
            'failures' => $failures,
            'cap_hits' => $capHits,
            'blocked_until' => $delay > 0 ? $now + $delay : null,
            'step_up_required' => $stepUpRequired,
            'step_up_alerted' => $state['step_up_alerted'],
            'pair_keys' => $state['pair_keys'],
            'ips' => $state['ips'],
            'last_delay' => $delay,
        ];
    }

    /**
     * @return array{
     *     failures: int,
     *     cap_hits: int,
     *     blocked_until: ?int,
     *     step_up_required: bool,
     *     step_up_alerted: bool,
     *     pair_keys: list<string>,
     *     ips: list<string>,
     *     last_delay: int
     * }
     */
    private function readState(string $key): array
    {
        $raw = Cache::get($key);
        if (! is_array($raw)) {
            return [
                'failures' => 0,
                'cap_hits' => 0,
                'blocked_until' => null,
                'step_up_required' => false,
                'step_up_alerted' => false,
                'pair_keys' => [],
                'ips' => [],
                'last_delay' => 0,
            ];
        }

        return [
            'failures' => max(0, (int) ($raw['failures'] ?? 0)),
            'cap_hits' => max(0, (int) ($raw['cap_hits'] ?? 0)),
            'blocked_until' => isset($raw['blocked_until']) && is_numeric($raw['blocked_until']) ? (int) $raw['blocked_until'] : null,
            'step_up_required' => (bool) ($raw['step_up_required'] ?? false),
            'step_up_alerted' => (bool) ($raw['step_up_alerted'] ?? false),
            'pair_keys' => array_values(array_filter((array) ($raw['pair_keys'] ?? []), 'is_string')),
            'ips' => array_values(array_filter((array) ($raw['ips'] ?? []), 'is_string')),
            'last_delay' => max(0, (int) ($raw['last_delay'] ?? 0)),
        ];
    }
}
