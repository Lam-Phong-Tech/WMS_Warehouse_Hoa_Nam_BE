<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Support\LoginThrottle;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delays_progress_and_step_up_after_five_cap_cycles(): void
    {
        config([
            'login_security.backoff.free_attempts' => 3,
            'login_security.backoff.delays' => [1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900],
            'login_security.backoff.step_up_after_cycles' => 5,
        ]);
        Cache::flush();
        $throttle = app(LoginThrottle::class);

        foreach ([0, 0, 0, 1, 2, 4, 8, 16, 30, 60, 120, 300, 600, 900] as $index => $expected) {
            $result = $throttle->registerFailure('user@example.test', '10.0.0.5');
            $this->assertSame($expected, $result['retry_after'], 'failure #'.($index + 1));
            Carbon::setTestNow(now()->addSeconds(max(1, $result['retry_after'])));
        }

        for ($cycle = 2; $cycle <= 5; $cycle++) {
            Carbon::setTestNow(now()->addSeconds(901));
            $result = $throttle->registerFailure('user@example.test', '10.0.0.5');
            $this->assertSame(900, $result['retry_after'], "cycle #{$cycle}");
        }

        $this->assertTrue($throttle->state('user@example.test', '10.0.0.5')['step_up_required']);
    }

    public function test_pair_and_account_keys_are_independent_and_success_clears_them(): void
    {
        config(['login_security.backoff.free_attempts' => 0]);
        Cache::flush();
        $throttle = app(LoginThrottle::class);
        $throttle->registerFailure('user@example.test', '10.0.0.5');

        $this->assertSame(0, $throttle->retryAfter('user@example.test', '10.0.0.6'));
        $this->assertGreaterThan(0, $throttle->retryAfter('user@example.test', '10.0.0.5'));

        $throttle->registerSuccess('user@example.test');
        $this->assertSame(0, $throttle->retryAfter('user@example.test', '10.0.0.5'));
    }

    public function test_distributed_multi_ip_failures_block_at_account_level_and_unrelated_users_on_same_nat_are_isolated(): void
    {
        config([
            'login_security.backoff.free_attempts' => 1,
            'login_security.backoff.delays' => [30],
        ]);
        Cache::flush();
        $throttle = app(LoginThrottle::class);

        $throttle->registerFailure('victim@example.test', '10.0.0.5');
        $throttle->registerFailure('victim@example.test', '10.0.0.6');

        // Multi-IP attack triggers account-level block even for a third IP:
        $this->assertSame(30, $throttle->retryAfter('victim@example.test', '10.0.0.7'));
        // Unrelated user on the same NAT IP is unaffected:
        $this->assertSame(0, $throttle->retryAfter('colleague@example.test', '10.0.0.5'));
    }
}
